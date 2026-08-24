<?php

namespace Softbean\Telemetry;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Softbean\Telemetry\Client\HubClient;
use Softbean\Telemetry\Console\FlushOutboxCommand;
use Softbean\Telemetry\Console\HeartbeatCommand;
use Softbean\Telemetry\Console\TestConnectionCommand;
use Softbean\Telemetry\Listeners\AuthEventSubscriber;
use Softbean\Telemetry\Recording\AuditRecorder;
use Softbean\Telemetry\Recording\Masker;
use Softbean\Telemetry\Recording\Outbox;
use Softbean\Telemetry\Recording\OutboxDispatcher;

class TelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/softbean-telemetry.php', 'softbean-telemetry');

        $this->app->singleton(Outbox::class);
        $this->app->singleton(Masker::class, fn () => new Masker);
        $this->app->singleton(HubClient::class, fn () => HubClient::doConfig());

        $this->app->singleton(AuditRecorder::class, fn ($app) => new AuditRecorder(
            $app->make(Outbox::class),
            $app->make(Masker::class),
        ));

        $this->app->singleton(OutboxDispatcher::class, fn ($app) => new OutboxDispatcher(
            $app->make(Outbox::class),
            $app->make(HubClient::class),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/softbean-telemetry.php' => config_path('softbean-telemetry.php'),
        ], 'softbean-telemetry-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                FlushOutboxCommand::class,
                HeartbeatCommand::class,
                TestConnectionCommand::class,
            ]);
        }

        // Tudo abaixo depende de telemetria ligada. Com ela desligada o pacote
        // fica instalado mas inerte: sem rota, sem listener, sem agendamento.
        if (! config('softbean-telemetry.ativo', false)) {
            return;
        }

        if (config('softbean-telemetry.auditoria.capturar_autenticacao', true)) {
            Event::subscribe(AuthEventSubscriber::class);
        }

        if (config('softbean-telemetry.saude.rota_ativa', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/health.php');
        }

        $this->agendarTarefas();
    }

    /**
     * O agendamento é registrado pelo próprio pacote: pedir para o dono de
     * cada produto lembrar de agendar seria a forma mais provável de um
     * produto silenciosamente parar de reportar.
     */
    private function agendarTarefas(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            $schedule->command(FlushOutboxCommand::class)
                ->everyMinute()
                ->withoutOverlapping()
                ->runInBackground();

            $schedule->command(HeartbeatCommand::class)
                ->everyFiveMinutes()
                ->withoutOverlapping();
        });
    }
}
