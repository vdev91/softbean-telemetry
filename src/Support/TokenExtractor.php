<?php

namespace Softbean\Telemetry\Support;

/**
 * Traduz a contagem de tokens que cada provedor devolve.
 *
 * Cada API nomeia isso de um jeito e nenhuma delas garante o formato entre
 * versoes. Por isso a busca e por chave conhecida, com fallback: token nao
 * contado vira zero em vez de derrubar a chamada que o usuario pediu.
 *
 * @phpstan-type Tokens array{entrada: int, saida: int, cache: int}
 */
class TokenExtractor
{
    /**
     * Onde cada provedor guarda a contagem, em ordem de preferencia.
     *
     * @var array<string, array{entrada: array<int, string>, saida: array<int, string>, cache: array<int, string>}>
     */
    private const CHAVES = [
        'gemini' => [
            'entrada' => ['usageMetadata.promptTokenCount'],
            'saida' => ['usageMetadata.candidatesTokenCount'],
            'cache' => ['usageMetadata.cachedContentTokenCount'],
        ],
        'anthropic' => [
            'entrada' => ['usage.input_tokens'],
            'saida' => ['usage.output_tokens'],
            'cache' => ['usage.cache_read_input_tokens'],
        ],
        'openai' => [
            'entrada' => ['usage.prompt_tokens'],
            'saida' => ['usage.completion_tokens'],
            'cache' => ['usage.prompt_tokens_details.cached_tokens'],
        ],
    ];

    /**
     * Todos os caminhos conhecidos, para quando o provedor nao for informado
     * ou nao estiver na lista. Um formato desconhecido devolve zero — melhor
     * do que somar o campo errado e produzir um custo fantasioso.
     *
     * @return array{entrada: int, saida: int, cache: int}
     */
    public static function extrair(?array $resposta, ?string $provider = null): array
    {
        if ($resposta === null || $resposta === []) {
            return ['entrada' => 0, 'saida' => 0, 'cache' => 0];
        }

        $mapa = self::CHAVES[strtolower((string) $provider)] ?? null;

        if ($mapa === null) {
            $mapa = [
                'entrada' => array_merge(...array_column(self::CHAVES, 'entrada')),
                'saida' => array_merge(...array_column(self::CHAVES, 'saida')),
                'cache' => array_merge(...array_column(self::CHAVES, 'cache')),
            ];
        }

        return [
            'entrada' => self::primeiro($resposta, $mapa['entrada']),
            'saida' => self::primeiro($resposta, $mapa['saida']),
            'cache' => self::primeiro($resposta, $mapa['cache']),
        ];
    }

    /** @param  array<int, string>  $caminhos */
    private static function primeiro(array $resposta, array $caminhos): int
    {
        foreach ($caminhos as $caminho) {
            $valor = self::buscar($resposta, $caminho);

            if (is_numeric($valor)) {
                return (int) $valor;
            }
        }

        return 0;
    }

    /** data_get() sem depender do helper do Laravel estar carregado. */
    private static function buscar(array $dados, string $caminho): mixed
    {
        foreach (explode('.', $caminho) as $pedaco) {
            if (! is_array($dados) || ! array_key_exists($pedaco, $dados)) {
                return null;
            }

            $dados = $dados[$pedaco];
        }

        return $dados;
    }
}
