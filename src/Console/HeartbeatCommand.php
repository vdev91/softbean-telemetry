<?php

namespace Softbean\Telemetry\Console;

use Illuminate\Console\Command;
use Softbean\Telemetry\Client\HubClient;
use Softbean\Telemetry\Recording\Outbox;
use Softbean\Telemetry\Support\EnvironmentProbe;
use Throwable;

class HeartbeatCommand extends Command
{
    protected $signature = 'softbean:heartbeat';

    protected $description = 'Reporta ao hub que este produto esta no ar, com versao, pendencias e capacidades.';

    public function handle(HubClient $cliente, EnvironmentProbe $sonda, Outbox $outbox): int
    {
        if (! config('softbean-telemetry.ativo', false)) {
            $this->comment('Telemetria desligada (SOFTBEAN_TELEMETRIA_ATIVA=false).');

            return self::SUCCESS;
        }

        $retrato = $sonda->retrato();

        // Fila que parou de escoar e problema de saude tanto quanto migration
        // pendente: o hub precisa saber que existe auditoria represada aqui.
        try {
            $desistidos = $outbox->contarDesistidos();

            if ($desistidos > 0) {
                $retrato['status'] = 'degradado';
                $retrato['detalhes']['eventos_nao_entregues'] = $desistidos;
            }
        } catch (Throwable) {
            // Tabela da fila ainda nao existe: nao impede o heartbeat.
        }

        try {
            $resposta = $cliente->enviar('/api/ingest/heartbeat', $retrato);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $resposta->sucesso) {
            $this->error("Hub recusou o heartbeat [{$resposta->status}]: {$resposta->mensagem}");

            return self::FAILURE;
        }

        $this->info('Heartbeat entregue. Estado reportado: '.($resposta->corpo['status'] ?? 'online'));

        return self::SUCCESS;
    }
}
