<?php

namespace Softbean\Telemetry\Client;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fala com o Softbean Desk.
 *
 * Assina cada requisição com HMAC sobre (timestamp + nonce + método + rota +
 * digest do corpo). O segredo nunca sai daqui; o que viaja é só a assinatura.
 */
class HubClient
{
    public function __construct(
        private readonly ?string $urlBase,
        private readonly ?string $chavePublica,
        private readonly ?string $chaveSecreta,
        private readonly int $timeout = 10,
    ) {}

    public static function doConfig(): self
    {
        return new self(
            urlBase: config('softbean-telemetry.hub.url'),
            chavePublica: config('softbean-telemetry.hub.chave_publica'),
            chaveSecreta: config('softbean-telemetry.hub.chave_secreta'),
            timeout: (int) config('softbean-telemetry.hub.timeout', 10),
        );
    }

    public function estaConfigurado(): bool
    {
        return filled($this->urlBase)
            && filled($this->chavePublica)
            && filled($this->chaveSecreta);
    }

    /**
     * @param  string  $caminho  Ex.: /api/ingest/events
     *
     * @throws RuntimeException quando falta configuração
     */
    public function enviar(string $caminho, array $corpo): Resposta
    {
        if (! $this->estaConfigurado()) {
            throw new RuntimeException(
                'Telemetria sem configuração: defina SOFTBEAN_HUB_URL, SOFTBEAN_CHAVE_PUBLICA e SOFTBEAN_CHAVE_SECRETA no .env.'
            );
        }

        $caminho = '/'.ltrim($caminho, '/');

        // O corpo é serializado uma vez só e enviado exatamente como foi
        // assinado: reserializar depois mudaria o digest e o hub recusaria.
        $json = json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $nonce = (string) Str::uuid();

        $assinatura = hash_hmac('sha256', implode('.', [
            $timestamp,
            $nonce,
            'POST',
            $caminho,
            hash('sha256', $json),
        ]), $this->chaveSecreta);

        $resposta = Http::timeout($this->timeout)
            ->withBody($json, 'application/json')
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Softbean-Key' => $this->chavePublica,
                'X-Softbean-Timestamp' => (string) $timestamp,
                'X-Softbean-Nonce' => $nonce,
                'X-Softbean-Signature' => $assinatura,
            ])
            ->post(rtrim($this->urlBase, '/').$caminho);

        return new Resposta(
            sucesso: $resposta->successful(),
            status: $resposta->status(),
            corpo: $resposta->json() ?? [],
            mensagem: $resposta->successful() ? null : ($resposta->json('message') ?? $resposta->body()),
        );
    }
}
