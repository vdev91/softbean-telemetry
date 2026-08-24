<?php

namespace Softbean\Telemetry\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege o endpoint de saude.
 *
 * Sem isto, qualquer um descobriria versao, migrations pendentes e provedores
 * configurados do produto — um mapa util para quem estiver procurando brecha.
 * O hub assina a consulta com a mesma chave da ingestao.
 */
class VerifyHubSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $segredo = config('softbean-telemetry.hub.chave_secreta');
        $chavePublica = config('softbean-telemetry.hub.chave_publica');

        if (blank($segredo)) {
            return response()->json(['message' => 'Telemetria nao configurada.'], 503);
        }

        $timestamp = $request->header('X-Softbean-Timestamp');
        $nonce = $request->header('X-Softbean-Nonce');
        $assinatura = $request->header('X-Softbean-Signature');

        if (blank($timestamp) || blank($nonce) || blank($assinatura)) {
            return response()->json(['message' => 'Consulta nao assinada.'], 401);
        }

        if ($request->header('X-Softbean-Key') !== $chavePublica) {
            return response()->json(['message' => 'Credencial invalida.'], 401);
        }

        if (! ctype_digit((string) $timestamp) || abs(time() - (int) $timestamp) > 300) {
            return response()->json(['message' => 'Consulta fora da janela de tempo.'], 401);
        }

        $esperada = hash_hmac('sha256', implode('.', [
            $timestamp,
            $nonce,
            $request->getMethod(),
            '/'.ltrim($request->path(), '/'),
            hash('sha256', $request->getContent()),
        ]), $segredo);

        if (! hash_equals($esperada, (string) $assinatura)) {
            return response()->json(['message' => 'Credencial invalida.'], 401);
        }

        return $next($request);
    }
}
