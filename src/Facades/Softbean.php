<?php

namespace Softbean\Telemetry\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Softbean\Telemetry\Recording\AuditRecorder;

/**
 * Atalho para a telemetria dentro do produto.
 *
 * @method static void auditar(string $acao, string $categoria = 'dados', string $severidade = 'info', ?Model $alvo = null, array $alteracoes = [], array $contexto = [], ?string $tenant = null, ?bool $contemDadoPessoal = null, mixed $ator = null)
 * @method static void auditarModelo(Model $modelo, string $acao, array $contexto = [])
 * @method static mixed semAuditoriaDeModelos(callable $operacao)
 * @method static void usoIa(string $provider, string $modelo, int $tokensEntrada = 0, int $tokensSaida = 0, ?string $operacao = null, ?float $custoUsd = null, ?int $latenciaMs = null, bool $sucesso = true, ?string $erro = null, ?string $tenant = null)
 * @method static void usoIaDaResposta(string $provider, string $modelo, ?array $resposta, ?string $operacao = null, ?int $latenciaMs = null, bool $sucesso = true, ?string $erro = null, ?string $tenant = null)
 * @method static void metrica(string $metrica, float $valor, ?string $unidade = null, ?string $tenant = null, ?string $dia = null)
 * @method static void tenants(array $tenants)
 * @method static bool ativo()
 *
 * @see AuditRecorder
 */
class Softbean extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AuditRecorder::class;
    }
}
