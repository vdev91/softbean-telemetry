<?php

namespace Softbean\Telemetry\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Events\Dispatcher;
use Softbean\Telemetry\Recording\AuditRecorder;

/**
 * Captura os eventos de autenticacao sem o produto precisar escrever nada.
 *
 * Login, logout, falha e bloqueio por tentativa excessiva sao o minimo de uma
 * trilha util: e a partir deles que se responde "quem entrou, de onde, quando"
 * e se percebe ataque de forca bruta.
 */
class AuthEventSubscriber
{
    public function __construct(private readonly AuditRecorder $auditor) {}

    public function aoEntrar(Login $evento): void
    {
        $this->auditor->auditar(
            acao: 'entrou',
            categoria: 'autenticacao',
            ator: $evento->user,
            contexto: ['guard' => $evento->guard, 'lembrar' => $evento->remember],
        );
    }

    public function aoSair(Logout $evento): void
    {
        $this->auditor->auditar(
            acao: 'saiu',
            categoria: 'autenticacao',
            ator: $evento->user,
            contexto: ['guard' => $evento->guard],
        );
    }

    public function aoFalhar(Failed $evento): void
    {
        // As credenciais trazem a senha tentada: so o identificador sai daqui.
        $identificador = $evento->credentials['email']
            ?? $evento->credentials['usuario']
            ?? $evento->credentials['login']
            ?? null;

        $this->auditor->auditar(
            acao: 'falha_de_login',
            categoria: 'autenticacao',
            severidade: 'aviso',
            ator: ['tipo' => 'anonimo', 'email' => $identificador],
            contexto: ['guard' => $evento->guard, 'usuario_existe' => $evento->user !== null],
            contemDadoPessoal: true,
        );
    }

    public function aoBloquear(Lockout $evento): void
    {
        // Bloqueio por excesso de tentativa e sinal de ataque, nao de descuido.
        $this->auditor->auditar(
            acao: 'bloqueio_por_tentativas',
            categoria: 'seguranca',
            severidade: 'critico',
            ator: ['tipo' => 'anonimo'],
            contexto: ['ip' => $evento->request->ip()],
        );
    }

    public function aoRedefinirSenha(PasswordReset $evento): void
    {
        $this->auditor->auditar(
            acao: 'redefiniu_senha',
            categoria: 'seguranca',
            severidade: 'aviso',
            ator: $evento->user,
        );
    }

    public function aoCadastrar(Registered $evento): void
    {
        $this->auditor->auditar(
            acao: 'cadastrou_se',
            categoria: 'autenticacao',
            ator: $evento->user,
            contemDadoPessoal: true,
        );
    }

    public function subscribe(Dispatcher $eventos): array
    {
        return [
            Login::class => 'aoEntrar',
            Logout::class => 'aoSair',
            Failed::class => 'aoFalhar',
            Lockout::class => 'aoBloquear',
            PasswordReset::class => 'aoRedefinirSenha',
            Registered::class => 'aoCadastrar',
        ];
    }
}
