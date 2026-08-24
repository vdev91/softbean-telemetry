<?php

namespace Softbean\Telemetry\Recording;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A fila local. Escreve rapido e nunca lanca: registrar auditoria nao pode
 * derrubar a operacao que o usuario pediu.
 */
class Outbox
{
    public function tabela(): string
    {
        return config('softbean-telemetry.outbox.tabela', 'softbean_outbox');
    }

    /** Conexao onde a fila vive. Null usa a padrao da aplicacao. */
    public function conexao(): ?string
    {
        return config('softbean-telemetry.outbox.conexao');
    }

    /**
     * A fila sempre na mesma conexao, mesmo dentro do contexto de um tenant.
     *
     * Em produto multi-tenant a conexao padrao troca a cada request. Se a fila
     * seguisse essa troca, cada escola acumularia a propria auditoria no
     * proprio banco e o comando de envio — que roda no contexto central —
     * nunca a encontraria.
     */
    private function consulta(): Builder
    {
        return DB::connection($this->conexao())->table($this->tabela());
    }

    public function enfileirar(string $tipo, array $payload): void
    {
        try {
            $this->consulta()->insert([
                'tipo' => $tipo,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'tentativas' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Tabela ainda nao migrada, banco indisponivel: registra e segue.
            Log::warning('[softbean-telemetry] nao foi possivel enfileirar evento: '.$e->getMessage());
        }
    }

    /** @return array<int, object> */
    public function pendentes(string $tipo, int $limite): array
    {
        return $this->consulta()
            ->whereNull('enviado_em')
            ->where('tipo', $tipo)
            ->where(fn ($q) => $q->whereNull('tentar_apos')->orWhere('tentar_apos', '<=', now()))
            ->where('tentativas', '<', (int) config('softbean-telemetry.outbox.max_tentativas', 10))
            ->orderBy('id')
            ->limit($limite)
            ->get()
            ->all();
    }

    public function tiposPendentes(): array
    {
        return $this->consulta()
            ->whereNull('enviado_em')
            ->distinct()
            ->pluck('tipo')
            ->all();
    }

    /** @param  array<int, int>  $ids */
    public function marcarEnviados(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->consulta()
            ->whereIn('id', $ids)
            ->update(['enviado_em' => now(), 'updated_at' => now(), 'ultimo_erro' => null]);
    }

    /**
     * Recuo exponencial: um hub fora do ar nao deve ser martelado a cada
     * minuto, e o produto tem coisa melhor a fazer.
     *
     * @param  array<int, int>  $ids
     */
    public function marcarFalha(array $ids, string $erro): void
    {
        if ($ids === []) {
            return;
        }

        $registros = $this->consulta()->whereIn('id', $ids)->get(['id', 'tentativas']);

        foreach ($registros as $registro) {
            $tentativas = (int) $registro->tentativas + 1;
            $espera = min(60 * 60, (2 ** min($tentativas, 10)) * 5);

            $this->consulta()->where('id', $registro->id)->update([
                'tentativas' => $tentativas,
                'ultimo_erro' => mb_substr($erro, 0, 1000),
                'tentar_apos' => now()->addSeconds($espera),
                'updated_at' => now(),
            ]);
        }
    }

    /** Limpa o que ja foi entregue ha tempo suficiente. */
    public function limpar(): int
    {
        $horas = (int) config('softbean-telemetry.outbox.reter_enviados_horas', 24);

        return $this->consulta()
            ->whereNotNull('enviado_em')
            ->where('enviado_em', '<', now()->subHours($horas))
            ->delete();
    }

    /** Quantos eventos desistiram de ser entregues. Sinal de problema real. */
    public function contarDesistidos(): int
    {
        return $this->consulta()
            ->whereNull('enviado_em')
            ->where('tentativas', '>=', (int) config('softbean-telemetry.outbox.max_tentativas', 10))
            ->count();
    }
}
