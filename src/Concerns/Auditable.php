<?php

namespace Softbean\Telemetry\Concerns;

use Softbean\Telemetry\Recording\AuditRecorder;

/**
 * Coloque este trait num model e toda criacao, alteracao e exclusao dele
 * entra na trilha, com o diff de antes e depois.
 *
 * E opt-in de proposito: auditar todo model do sistema encheria a trilha de
 * ruido (cache, sessao, job) e afogaria o que importa. Escolha os models que
 * guardam decisao ou dado pessoal.
 */
trait Auditable
{
    /**
     * Campos deste model cujo valor nunca deve sair do produto.
     *
     * Declare `protected array $softbeanNaoEnviar = ['campo'];` no model que
     * guarda conteudo do usuario: o texto de um contrato, o corpo de um
     * documento, o resultado de uma analise. A trilha continua registrando
     * que o campo mudou; o valor fica no produto.
     *
     * Mandar esse conteudo ao hub criaria uma segunda copia de dado do
     * usuario, em outro banco, com outra superficie de vazamento — e sem
     * ganho de auditoria, porque saber QUE mudou ja responde a pergunta.
     *
     * A propriedade nao e declarada aqui de proposito: o PHP recusa a
     * composicao quando trait e classe declaram a mesma propriedade com
     * inicializadores diferentes.
     *
     * @return array<int, string>
     */
    public function softbeanCamposNaoEnviados(): array
    {
        return property_exists($this, 'softbeanNaoEnviar')
            ? (array) $this->softbeanNaoEnviar
            : [];
    }

    public static function bootAuditable(): void
    {
        static::created(function ($modelo) {
            app(AuditRecorder::class)->auditarModelo($modelo, 'criou');
        });

        static::updated(function ($modelo) {
            app(AuditRecorder::class)->auditarModelo($modelo, 'atualizou');
        });

        static::deleted(function ($modelo) {
            // Exclusao logica e reversivel; exclusao de verdade nao. A trilha
            // distingue as duas porque a gravidade e diferente.
            $acao = method_exists($modelo, 'isForceDeleting') && $modelo->isForceDeleting()
                ? 'excluiu_definitivamente'
                : 'excluiu';

            app(AuditRecorder::class)->auditarModelo($modelo, $acao);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function ($modelo) {
                app(AuditRecorder::class)->auditarModelo($modelo, 'restaurou');
            });
        }
    }
}
