<?php

namespace Softbean\Telemetry\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Levanta o estado do produto para o heartbeat.
 *
 * Cada sonda é isolada: um produto sem tabela de jobs, sem git ou sem
 * permissão de leitura em algum caminho não pode fazer o heartbeat inteiro
 * falhar — o hub prefere um retrato parcial a nenhum retrato.
 */
class EnvironmentProbe
{
    public function retrato(): array
    {
        return array_filter([
            'status' => 'online',
            'versao_app' => $this->versaoApp(),
            'git_sha' => $this->gitSha(),
            'php_versao' => PHP_VERSION,
            'framework' => 'Laravel',
            'framework_versao' => $this->versaoFramework(),
            'migrations_pendentes' => $this->migrationsPendentes(),
            'jobs_falhados' => $this->jobsFalhados(),
            'capacidades' => $this->capacidades(),
            'detalhes' => $this->detalhes(),
        ], fn ($v) => $v !== null);
    }

    /**
     * O que este produto tem. É o que permite o painel do hub se adaptar a um
     * produto novo sem alteração de código do lado de lá.
     */
    public function capacidades(): array
    {
        return array_filter([
            'auth' => $this->temTabela('users'),
            'tenancy' => $this->temTenancy(),
            'filas' => config('queue.default') !== 'sync',
            'ia' => $this->provedoresDeIa(),
            'banco' => config('database.default'),
            'cache' => config('cache.default'),
        ], fn ($v) => $v !== null && $v !== [] && $v !== false);
    }

    private function versaoApp(): ?string
    {
        try {
            $caminho = base_path('VERSION');

            if (File::exists($caminho)) {
                return trim(File::get($caminho)) ?: null;
            }

            $composer = base_path('composer.json');

            if (File::exists($composer)) {
                return json_decode(File::get($composer), true)['version'] ?? null;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Lê o SHA direto do .git, sem invocar o binário do git: em servidor de
     * produção o git costuma não estar no PATH do usuário do PHP.
     */
    private function gitSha(): ?string
    {
        try {
            $head = base_path('.git/HEAD');

            if (! File::exists($head)) {
                return null;
            }

            $conteudo = trim(File::get($head));

            if (! str_starts_with($conteudo, 'ref:')) {
                return substr($conteudo, 0, 40) ?: null;
            }

            $ref = trim(substr($conteudo, 4));
            $arquivoRef = base_path('.git/'.$ref);

            if (File::exists($arquivoRef)) {
                return substr(trim(File::get($arquivoRef)), 0, 40) ?: null;
            }

            // Referência empacotada (repositório com packed-refs).
            $packed = base_path('.git/packed-refs');

            if (File::exists($packed)) {
                foreach (explode("\n", File::get($packed)) as $linha) {
                    if (str_ends_with(trim($linha), $ref)) {
                        return substr(trim($linha), 0, 40);
                    }
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function versaoFramework(): ?string
    {
        try {
            return app()->version();
        } catch (Throwable) {
            return null;
        }
    }

    private function migrationsPendentes(): ?int
    {
        try {
            if (! Schema::hasTable('migrations')) {
                return null;
            }

            $executadas = DB::table('migrations')->pluck('migration')->flip();
            $pendentes = 0;

            foreach (File::glob(database_path('migrations/*.php')) as $arquivo) {
                if (! $executadas->has(basename($arquivo, '.php'))) {
                    $pendentes++;
                }
            }

            return $pendentes;
        } catch (Throwable) {
            return null;
        }
    }

    private function jobsFalhados(): ?int
    {
        try {
            return Schema::hasTable('failed_jobs')
                ? DB::table('failed_jobs')->count()
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function temTabela(string $tabela): bool
    {
        try {
            return Schema::hasTable($tabela);
        } catch (Throwable) {
            // Banco indisponível no momento da sonda: melhor reportar ausência
            // do que derrubar o heartbeat inteiro.
            return false;
        }
    }

    private function temTenancy(): bool
    {
        return class_exists(\Stancl\Tenancy\Tenancy::class);
    }

    /** Quais provedores de IA estão configurados. Vira controle de custo no hub. */
    private function provedoresDeIa(): array
    {
        $provedores = [];

        foreach ([
            'gemini' => 'GEMINI_API_KEY',
            'anthropic' => 'ANTHROPIC_API_KEY',
            'openai' => 'OPENAI_API_KEY',
        ] as $nome => $variavel) {
            if (filled(env($variavel))) {
                $provedores[] = $nome;
            }
        }

        return $provedores;
    }

    private function detalhes(): array
    {
        $detalhes = [];

        try {
            $livre = @disk_free_space(base_path());
            $total = @disk_total_space(base_path());

            if ($livre && $total) {
                $detalhes['disco_livre_pct'] = round($livre / $total * 100, 1);
            }
        } catch (Throwable) {
            // Sem permissão para consultar o disco: segue sem essa informação.
        }

        $detalhes['debug_ligado'] = (bool) config('app.debug');
        $detalhes['ambiente'] = config('app.env');

        return $detalhes;
    }
}
