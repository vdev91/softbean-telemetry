<?php

namespace Softbean\Telemetry\Client;

/** Resultado de um envio ao hub, sem depender do objeto de resposta HTTP. */
final class Resposta
{
    public function __construct(
        public readonly bool $sucesso,
        public readonly int $status,
        public readonly array $corpo = [],
        public readonly ?string $mensagem = null,
    ) {}

    /**
     * Erro do lado do produto (credencial errada, corpo invalido) nao adianta
     * retentar: vai falhar igual. Erro de servidor ou de rede, sim.
     */
    public function valeRetentar(): bool
    {
        return $this->status === 0
            || $this->status === 429
            || $this->status >= 500;
    }
}
