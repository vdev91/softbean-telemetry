<?php

namespace Softbean\Telemetry\Scanning;

use Illuminate\Support\Facades\File;

/**
 * Lê as migrations do projeto e devolve os pares (tabela, coluna).
 *
 * Só relata o que existe; não classifica. A classificação — o que é dado
 * pessoal, o que é sensível, o que é de menor — fica no hub, num único lugar,
 * para ajustar os padrões não exigir redeploy de produto nenhum.
 *
 * Lê as migrations em vez de consultar o banco porque não precisa de conexão,
 * enxerga o schema pretendido (inclusive migration ainda não aplicada) e cobre
 * database/migrations/tenant, que é onde mora o schema por cliente em produto
 * multi-tenant — justamente o que mais guarda dado pessoal.
 */
class SchemaReader
{
    /** Tipos de coluna reconhecidos. */
    private const TIPOS = 'string|char|text|mediumText|longText|integer|bigInteger|unsignedBigInteger|'
        .'unsignedInteger|smallInteger|tinyInteger|boolean|date|dateTime|dateTimeTz|timestamp|time|'
        .'json|jsonb|decimal|float|double|uuid|ulid|ipAddress|macAddress|binary|year|foreignId|foreignUuid';

    /**
     * @return array{colunas: array<int, array{tabela: string, campo: string}>, avisos: array<int, string>}
     */
    public function ler(?string $caminho = null): array
    {
        $caminho ??= base_path();
        $pasta = $caminho.'/database/migrations';
        $avisos = [];

        if (! File::isDirectory($pasta)) {
            return ['colunas' => [], 'avisos' => ['O projeto não tem database/migrations.']];
        }

        $pares = [];

        foreach (File::allFiles($pasta) as $arquivo) {
            if ($arquivo->getExtension() !== 'php') {
                continue;
            }

            try {
                foreach ($this->extrair($arquivo->getPathname()) as [$tabela, $campo]) {
                    // Coluna criada e depois alterada aparece duas vezes; a
                    // chave colapsa isso numa linha só.
                    $pares[$tabela.'.'.$campo] = ['tabela' => $tabela, 'campo' => $campo];
                }
            } catch (\Throwable $e) {
                $avisos[] = 'Não foi possível ler '.$arquivo->getFilename().': '.$e->getMessage();
            }
        }

        return ['colunas' => array_values($pares), 'avisos' => $avisos];
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function extrair(string $arquivo): array
    {
        $conteudo = File::get($arquivo);
        $pares = [];

        $padraoBloco = '/Schema::(?:create|table)\s*\(\s*[\'"]([^\'"]+)[\'"].*?\{(.*?)\n\s*\}\s*\)/s';

        if (! preg_match_all($padraoBloco, $conteudo, $blocos, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($blocos as $bloco) {
            $padraoColuna = '/\$table->(?:'.self::TIPOS.')\s*\(\s*[\'"]([^\'"]+)[\'"]/';

            if (preg_match_all($padraoColuna, $bloco[2], $colunas)) {
                foreach ($colunas[1] as $coluna) {
                    $pares[] = [$bloco[1], $coluna];
                }
            }
        }

        return $pares;
    }
}
