<?php

namespace Softbean\Telemetry\Console;

use Illuminate\Console\Command;
use Softbean\Telemetry\Recording\OutboxDispatcher;

class FlushOutboxCommand extends Command
{
    protected $signature = 'softbean:enviar';

    protected $description = 'Envia ao hub a auditoria e a telemetria acumuladas na fila local.';

    public function handle(OutboxDispatcher $despachante): int
    {
        $resultado = $despachante->despachar();

        foreach ($resultado['mensagens'] as $mensagem) {
            $this->warn($mensagem);
        }

        if ($resultado['enviados'] > 0) {
            $this->info("Enviados: {$resultado['enviados']}");
        }

        if ($resultado['falhas'] > 0) {
            $this->error("Falharam: {$resultado['falhas']} (voltam para a fila com recuo progressivo)");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
