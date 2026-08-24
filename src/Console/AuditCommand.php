<?php

namespace Softbean\Telemetry\Console;

use Illuminate\Console\Command;
use Softbean\Telemetry\Recording\Outbox;
use Softbean\Telemetry\Scanning\SchemaReader;
use Softbean\Telemetry\Scanning\SecurityScanner;

/**
 * Varre este projeto e enfileira o resultado para o hub.
 *
 * Roda dentro do produto, como o usuário dono da pasta — que é o único que
 * consegue ler o .env, o composer.lock e as permissões dos arquivos. Em
 * servidor com um usuário por site, o hub não alcança nada disso, e não
 * deveria mesmo: é essa separação que impede um site comprometido de ler as
 * credenciais dos outros.
 */
class AuditCommand extends Command
{
    protected $signature = 'softbean:auditar
                            {--so-seguranca : Roda so a auditoria tecnica}
                            {--so-dados : Roda so a leitura de schema para o inventario LGPD}';

    protected $description = 'Varre este projeto (CVEs, configuracao, segredos, schema) e envia ao Softbean Desk.';

    public function handle(SecurityScanner $scanner, SchemaReader $leitor, Outbox $outbox): int
    {
        if (! config('softbean-telemetry.ativo', false)) {
            $this->comment('Telemetria desligada (SOFTBEAN_TELEMETRIA_ATIVA=false).');

            return self::SUCCESS;
        }

        $soSeguranca = $this->option('so-seguranca');
        $soDados = $this->option('so-dados');
        $rodarTudo = ! $soSeguranca && ! $soDados;

        if ($rodarTudo || $soSeguranca) {
            $resultado = $scanner->varrer();

            foreach ($resultado['avisos'] as $aviso) {
                $this->line("  <fg=yellow>·</> {$aviso}");
            }

            // Enfileira mesmo sem achado: uma varredura que não achou nada é
            // informação — é ela que permite o hub encerrar o que foi
            // corrigido. Sem isso, achado resolvido ficaria aberto para sempre.
            $outbox->enfileirar('security-findings', [
                'varrido_em' => now()->format('Y-m-d\TH:i:sP'),
                'achados' => $resultado['achados'],
            ]);

            $this->info(count($resultado['achados']).' achado(s) de segurança enfileirado(s).');
        }

        if ($rodarTudo || $soDados) {
            $resultado = $leitor->ler();

            foreach ($resultado['avisos'] as $aviso) {
                $this->line("  <fg=yellow>·</> {$aviso}");
            }

            $outbox->enfileirar('schema', [
                'lido_em' => now()->format('Y-m-d\TH:i:sP'),
                'colunas' => $resultado['colunas'],
            ]);

            $this->info(count($resultado['colunas']).' coluna(s) de schema enfileirada(s).');
        }

        $this->line('<fg=gray>Sai no próximo softbean:enviar (a cada minuto pelo agendador).</>');

        return self::SUCCESS;
    }
}
