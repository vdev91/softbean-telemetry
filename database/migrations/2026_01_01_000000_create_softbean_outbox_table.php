<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fila local do que ainda nao foi confirmado pelo hub.
 *
 * Sem ela, um hub fora do ar significaria auditoria perdida — justamente no
 * momento em que ela mais importa. Aqui o evento fica gravado no banco do
 * proprio produto ate a entrega ser confirmada.
 */
return new class extends Migration
{
    /**
     * Fixa a conexao para acompanhar a config da fila.
     *
     * Em produto multi-tenant a fila mora na conexao central, e a tabela
     * precisa ser criada exatamente la — nao no banco de um tenant.
     */
    public function getConnection(): ?string
    {
        return config('softbean-telemetry.outbox.conexao');
    }

    public function up(): void
    {
        $tabela = config('softbean-telemetry.outbox.tabela', 'softbean_outbox');

        Schema::connection($this->getConnection())->create($tabela, function (Blueprint $table) {
            $table->id();

            // events, metrics, ai-usage, tenants
            $table->string('tipo', 30);
            $table->json('payload');

            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->text('ultimo_erro')->nullable();
            $table->timestamp('tentar_apos')->nullable();
            $table->timestamp('enviado_em')->nullable();
            $table->timestamps();

            $table->index(['enviado_em', 'tipo', 'tentar_apos'], 'softbean_outbox_pendentes');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())
            ->dropIfExists(config('softbean-telemetry.outbox.tabela', 'softbean_outbox'));
    }
};
