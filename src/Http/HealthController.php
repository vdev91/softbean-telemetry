<?php

namespace Softbean\Telemetry\Http;

use Illuminate\Http\JsonResponse;
use Softbean\Telemetry\Recording\Outbox;
use Softbean\Telemetry\Support\EnvironmentProbe;
use Throwable;

/**
 * Responde ao hub quando ele vem perguntar se o produto esta de pe.
 *
 * Existe porque o heartbeat sozinho nao basta: um produto que travou para de
 * mandar heartbeat, mas so o silencio nao diz se ele caiu, se a fila parou ou
 * se foi a rede. Esta consulta ativa distingue os casos.
 */
class HealthController
{
    public function __invoke(EnvironmentProbe $sonda, Outbox $outbox): JsonResponse
    {
        $retrato = $sonda->retrato();

        try {
            $retrato['eventos_na_fila'] = $outbox->contarDesistidos();
        } catch (Throwable) {
            $retrato['eventos_na_fila'] = null;
        }

        return response()->json($retrato);
    }
}
