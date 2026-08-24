<?php

namespace Softbean\Telemetry\Recording;

use Softbean\Telemetry\Client\HubClient;
use Throwable;

/**
 * Esvazia a fila local mandando o que está acumulado para o hub.
 *
 * Só marca como entregue o que o hub confirmou. Falha de rede devolve o lote
 * para a fila com recuo exponencial; falha de credencial não adianta retentar
 * e o lote fica parado até alguém corrigir o .env — o que é melhor do que
 * descartar auditoria em silêncio.
 */
class OutboxDispatcher
{
    /** Cada tipo da fila vai para um endpoint, com a chave que ele espera. */
    private const ROTAS = [
        'events' => ['/api/ingest/events', 'eventos'],
        'metrics' => ['/api/ingest/metrics', 'metricas'],
        'ai-usage' => ['/api/ingest/ai-usage', 'chamadas'],
        'tenants' => ['/api/ingest/tenants', 'tenants'],
        'security-findings' => ['/api/ingest/security-findings', 'varreduras'],
        'schema' => ['/api/ingest/schema', 'leituras'],
    ];

    /**
     * Uma varredura inteira num item só: mandar cinco de uma vez estouraria o
     * corpo da requisição sem ganho nenhum, já que o hub só precisa da última.
     */
    private const TAMANHO_POR_TIPO = [
        'security-findings' => 1,
        'schema' => 1,
    ];

    public function __construct(
        private readonly Outbox $outbox,
        private readonly HubClient $cliente,
    ) {}

    /**
     * @return array{enviados: int, falhas: int, mensagens: array<int, string>}
     */
    public function despachar(): array
    {
        if (! config('softbean-telemetry.ativo', false) || ! $this->cliente->estaConfigurado()) {
            return ['enviados' => 0, 'falhas' => 0, 'mensagens' => ['Telemetria desligada ou sem configuração.']];
        }

        $enviados = 0;
        $falhas = 0;
        $mensagens = [];

        foreach ($this->outbox->tiposPendentes() as $tipo) {
            if (! isset(self::ROTAS[$tipo])) {
                continue;
            }

            [$rota, $chave] = self::ROTAS[$tipo];
            $tamanho = self::TAMANHO_POR_TIPO[$tipo] ?? (int) config('softbean-telemetry.outbox.tamanho_do_lote', 200);

            // Enquanto houver pendência daquele tipo, segue mandando: um
            // produto que ficou horas sem hub tem backlog para drenar.
            while (true) {
                $pendentes = $this->outbox->pendentes($tipo, $tamanho);

                if ($pendentes === []) {
                    break;
                }

                $ids = array_map(fn ($linha) => (int) $linha->id, $pendentes);
                $itens = array_map(
                    fn ($linha) => json_decode($linha->payload, true),
                    $pendentes
                );

                try {
                    $resposta = $this->cliente->enviar($rota, [$chave => $itens]);
                } catch (Throwable $e) {
                    $this->outbox->marcarFalha($ids, $e->getMessage());
                    $falhas += count($ids);
                    $mensagens[] = $tipo.': '.$e->getMessage();

                    break;
                }

                if ($resposta->sucesso) {
                    $this->outbox->marcarEnviados($ids);
                    $enviados += count($ids);

                    continue;
                }

                $this->outbox->marcarFalha($ids, (string) $resposta->mensagem);
                $falhas += count($ids);
                $mensagens[] = $tipo.' ['.$resposta->status.']: '.$resposta->mensagem;

                // Sem sentido insistir no mesmo tipo neste ciclo.
                break;
            }
        }

        $this->outbox->limpar();

        return ['enviados' => $enviados, 'falhas' => $falhas, 'mensagens' => $mensagens];
    }
}
