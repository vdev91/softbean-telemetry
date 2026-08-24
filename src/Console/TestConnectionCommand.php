<?php

namespace Softbean\Telemetry\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Softbean\Telemetry\Client\HubClient;
use Softbean\Telemetry\Support\EnvironmentProbe;
use Throwable;

/**
 * Diagnóstico de instalação. Roda depois de colar o bloco de .env e diz
 * exatamente o que falta, em vez de deixar o produto reportando para o vazio.
 */
class TestConnectionCommand extends Command
{
    protected $signature = 'softbean:testar-conexao';

    protected $description = 'Confere a configuracao da telemetria e testa a comunicacao com o hub.';

    public function handle(HubClient $cliente, EnvironmentProbe $sonda): int
    {
        $this->info('Conferindo a instalação da telemetria Softbean...');
        $this->newLine();

        $problemas = [];

        $ativo = (bool) config('softbean-telemetry.ativo');
        $this->linha('Telemetria ativa', $ativo, $ativo ? 'sim' : 'não (SOFTBEAN_TELEMETRIA_ATIVA=false)');

        if (! $ativo) {
            $problemas[] = 'Defina SOFTBEAN_TELEMETRIA_ATIVA=true no .env.';
        }

        foreach ([
            'SOFTBEAN_HUB_URL' => config('softbean-telemetry.hub.url'),
            'SOFTBEAN_CHAVE_PUBLICA' => config('softbean-telemetry.hub.chave_publica'),
            'SOFTBEAN_CHAVE_SECRETA' => config('softbean-telemetry.hub.chave_secreta'),
            'SOFTBEAN_PRODUTO' => config('softbean-telemetry.produto'),
        ] as $variavel => $valor) {
            $preenchido = filled($valor);

            // A chave secreta nunca é ecoada, nem em diagnóstico.
            $exibicao = match (true) {
                ! $preenchido => 'ausente',
                $variavel === 'SOFTBEAN_CHAVE_SECRETA' => 'definida',
                default => (string) $valor,
            };

            $this->linha($variavel, $preenchido, $exibicao);

            if (! $preenchido) {
                $problemas[] = "Defina {$variavel} no .env.";
            }
        }

        $tabela = config('softbean-telemetry.outbox.tabela', 'softbean_outbox');
        $conexao = config('softbean-telemetry.outbox.conexao');

        try {
            $temTabela = Schema::connection($conexao)->hasTable($tabela);
        } catch (Throwable $e) {
            $temTabela = false;
        }

        $this->linha(
            "Tabela {$tabela}",
            $temTabela,
            ($temTabela ? 'criada' : 'faltando').($conexao ? " (conexão {$conexao})" : '')
        );

        if (! $temTabela) {
            $problemas[] = 'Rode: php artisan migrate';
        }

        // A rota de saúde só é registrada com a telemetria ligada. Se as rotas
        // foram cacheadas antes disso — o que sempre acontece, porque o deploy
        // roda optimize e o .env vem depois — ela fica de fora do cache e o hub
        // enxerga o produto como fora do ar mesmo com a ingestão funcionando.
        if ($ativo && config('softbean-telemetry.saude.rota_ativa', true)) {
            $rotaRegistrada = Route::has('softbean.health');

            $this->linha(
                'Rota /_softbean/health',
                $rotaRegistrada,
                $rotaRegistrada ? 'registrada' : 'fora do cache de rotas'
            );

            if (! $rotaRegistrada) {
                // config:clear ANTES, e não só optimize: o processo do optimize
                // boota com o cache de config antigo — o que ainda diz que a
                // telemetria está desligada — e monta a coleção de rotas sem a
                // rota de saúde antes de cachear. Limpar a config primeiro faz
                // o boot seguinte ler o .env de verdade.
                $problemas[] = 'Rode: php artisan config:clear && php artisan optimize';
            }
        }

        $this->newLine();

        if ($problemas !== []) {
            $this->error('Instalação incompleta:');

            foreach ($problemas as $problema) {
                $this->line('  · '.$problema);
            }

            return self::FAILURE;
        }

        $this->info('Enviando um heartbeat de teste...');

        try {
            $resposta = $cliente->enviar('/api/ingest/heartbeat', $sonda->retrato());
        } catch (Throwable $e) {
            $this->error('Não foi possível falar com o hub: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $resposta->sucesso) {
            $this->error("O hub recusou [{$resposta->status}]: {$resposta->mensagem}");
            $this->newLine();

            $this->line(match ($resposta->status) {
                401 => 'Credencial inválida. Confira se as chaves no .env são as mesmas do ambiente cadastrado no painel — e se elas não foram rotacionadas depois de você copiar.',
                404 => 'A URL do hub responde, mas não tem a rota de ingestão. Confira SOFTBEAN_HUB_URL.',
                429 => 'Limite de requisições atingido. Aguarde um minuto e tente de novo.',
                default => 'Veja o log do hub para o detalhe da recusa.',
            });

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Conexão estabelecida. O produto já aparece como no ar no Softbean Desk.');
        $this->line('Estado reportado: '.($resposta->corpo['status'] ?? 'online'));

        return self::SUCCESS;
    }

    private function linha(string $rotulo, bool $ok, string $valor): void
    {
        $this->line(sprintf(
            '  %s %s: <fg=gray>%s</>',
            $ok ? '<fg=green>OK  </>' : '<fg=red>FALTA</>',
            str_pad($rotulo, 24),
            $valor
        ));
    }
}
