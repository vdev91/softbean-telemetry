<?php

namespace Softbean\Telemetry\Scanning;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Auditoria técnica do projeto onde este pacote está instalado.
 *
 * Vive no pacote, e não no hub, porque em servidor com um usuário por site o
 * hub não consegue — nem deve — entrar na pasta dos outros produtos. Quem tem
 * acesso ao projeto é o próprio projeto: ele se varre e empurra os achados.
 *
 * Não toca em banco nem em model: devolve um array e quem chamou decide o que
 * fazer com ele. É isso que permite o hub usar a mesma classe como biblioteca
 * para varrer a si mesmo, sem passar pela rede.
 */
class SecurityScanner
{
    /** @var array<int, array<string, mixed>> */
    private array $achados = [];

    /** @var array<int, string> */
    private array $avisos = [];

    /**
     * @return array{achados: array<int, array<string, mixed>>, avisos: array<int, string>}
     */
    public function varrer(?string $caminho = null): array
    {
        $this->achados = [];
        $this->avisos = [];

        $caminho ??= base_path();

        if (! File::isDirectory($caminho)) {
            $this->avisos[] = "Caminho inacessível: {$caminho}";

            return ['achados' => [], 'avisos' => $this->avisos];
        }

        $this->verificarDependencias($caminho);
        $this->verificarConfiguracao($caminho);
        $this->verificarSegredos($caminho);
        $this->verificarPermissoes($caminho);

        return ['achados' => $this->achados, 'avisos' => $this->avisos];
    }

    /** CVEs conhecidas nas dependências, via composer audit. */
    private function verificarDependencias(string $caminho): void
    {
        try {
            $processo = new Process(
                ['composer', 'audit', '--format=json', '--no-interaction'],
                $caminho,
                null,
                null,
                180
            );
            $processo->run();

            $saida = json_decode($processo->getOutput(), true);

            if (! is_array($saida)) {
                $this->avisos[] = 'composer audit não pôde ser executado (composer fora do PATH ou sem rede).';

                return;
            }

            foreach ($saida['advisories'] ?? [] as $pacote => $avisosPacote) {
                foreach ($avisosPacote as $aviso) {
                    $cve = $aviso['cve'] ?? $aviso['advisoryId'] ?? 'sem-id';

                    $this->achar(
                        origem: 'composer_audit',
                        chave: "composer_audit:{$pacote}:{$cve}",
                        titulo: $pacote.' com vulnerabilidade conhecida',
                        descricao: $aviso['title'] ?? null,
                        severidade: $this->traduzirSeveridade($aviso['severity'] ?? null),
                        extras: [
                            'pacote' => $pacote,
                            'versao_atual' => $aviso['affectedVersions'] ?? null,
                            'cve' => is_string($cve) ? mb_substr($cve, 0, 40) : null,
                            'referencia' => $aviso['link'] ?? null,
                            'detalhes' => ['reportado_em' => $aviso['reportedAt'] ?? null],
                        ],
                    );
                }
            }

            // Pacote abandonado não recebe correção de segurança: é dívida que
            // vira vulnerabilidade no dia em que alguém achar um furo nele.
            foreach ($saida['abandoned'] ?? [] as $pacote => $substituto) {
                $this->achar(
                    origem: 'dependencias',
                    chave: "abandonado:{$pacote}",
                    titulo: $pacote.' está abandonado',
                    descricao: $substituto
                        ? "O autor recomenda migrar para {$substituto}."
                        : 'O pacote não tem mais manutenção e não receberá correções de segurança.',
                    severidade: 'baixa',
                    extras: ['pacote' => $pacote],
                );
            }
        } catch (Throwable $e) {
            $this->avisos[] = 'Falha ao auditar dependências: '.$e->getMessage();
        }
    }

    /** Configurações do .env que expõem a aplicação. */
    private function verificarConfiguracao(string $caminho): void
    {
        $env = $this->lerEnv($caminho);

        if ($env === []) {
            $this->avisos[] = 'Não foi possível ler o .env para conferir a configuração.';

            return;
        }

        $ehProducao = strtolower($env['APP_ENV'] ?? '') === 'production';

        if ($this->ligado($env['APP_DEBUG'] ?? null)) {
            $this->achar(
                origem: 'config',
                chave: 'config:app_debug',
                titulo: 'APP_DEBUG está ligado',
                descricao: 'Com debug ligado, qualquer erro devolve rastro de pilha, trechos de código e valores de variável ao visitante. É a forma mais rápida de entregar a estrutura interna e, muitas vezes, credenciais.',
                // Fora de produção é esperado; em produção é grave.
                severidade: $ehProducao ? 'critica' : 'informativa',
            );
        }

        if (blank($env['APP_KEY'] ?? null)) {
            $this->achar(
                origem: 'config',
                chave: 'config:app_key',
                titulo: 'APP_KEY não está definida',
                descricao: 'Sem chave da aplicação, sessão e cookies não têm criptografia confiável.',
                severidade: 'critica',
            );
        }

        if ($ehProducao && ! $this->ligado($env['SESSION_SECURE_COOKIE'] ?? null)) {
            $this->achar(
                origem: 'config',
                chave: 'config:session_secure',
                titulo: 'Cookie de sessão sem a marca Secure',
                descricao: 'Defina SESSION_SECURE_COOKIE=true para o cookie de sessão nunca trafegar fora de HTTPS.',
                severidade: 'media',
            );
        }

        if ($ehProducao && strtolower($env['MAIL_MAILER'] ?? '') === 'log') {
            $this->achar(
                origem: 'config',
                chave: 'config:mail_log',
                titulo: 'Envio de e-mail apontando para log em produção',
                descricao: 'Nenhum e-mail está sendo entregue de verdade: tudo cai no arquivo de log — inclusive redefinição de senha.',
                severidade: 'alta',
            );
        }

        if (($env['DB_PASSWORD'] ?? '') === '' && filled($env['DB_HOST'] ?? null)) {
            $this->achar(
                origem: 'config',
                chave: 'config:db_sem_senha',
                titulo: 'Banco de dados sem senha',
                descricao: 'A conexão está configurada sem senha. Mesmo em rede interna, qualquer processo da máquina alcança o banco.',
                severidade: $ehProducao ? 'critica' : 'media',
            );
        }
    }

    /** Segredo que escapou para o versionamento. */
    private function verificarSegredos(string $caminho): void
    {
        $gitignore = $caminho.'/.gitignore';

        if (File::exists($gitignore) && ! preg_match('/^\s*\.env\s*$/m', File::get($gitignore))) {
            $this->achar(
                origem: 'segredos',
                chave: 'segredos:env_nao_ignorado',
                titulo: '.env não está no .gitignore',
                descricao: 'O arquivo com todas as credenciais pode ser commitado por acidente. Depois de entrar no histórico, remover não resolve: a chave precisa ser rotacionada.',
                severidade: 'critica',
            );
        }

        if (! File::isDirectory($caminho.'/.git')) {
            return;
        }

        try {
            $processo = new Process(['git', 'ls-files', '--error-unmatch', '.env'], $caminho, null, null, 30);
            $processo->run();

            if ($processo->isSuccessful()) {
                $this->achar(
                    origem: 'segredos',
                    chave: 'segredos:env_versionado',
                    titulo: '.env está versionado no git',
                    descricao: 'As credenciais estão dentro do repositório. Remova do índice e rotacione tudo que estava no arquivo.',
                    severidade: 'critica',
                );
            }
        } catch (Throwable) {
            // Git indisponível: a checagem do .gitignore já cobre o essencial.
        }
    }

    /** Permissão ampla demais em arquivo sensível. Só faz sentido em Unix. */
    private function verificarPermissoes(string $caminho): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }

        $env = $caminho.'/.env';

        if (! File::exists($env)) {
            return;
        }

        $permissao = substr(sprintf('%o', fileperms($env)), -3);

        if ((int) $permissao[2] > 0) {
            $this->achar(
                origem: 'permissoes',
                chave: 'permissoes:env_legivel_por_todos',
                titulo: '.env legível por qualquer usuário do servidor',
                descricao: "Permissão atual: {$permissao}. Em servidor compartilhado, qualquer processo lê as credenciais. Ajuste para 600.",
                severidade: 'alta',
            );
        }
    }

    private function achar(
        string $origem,
        string $chave,
        string $titulo,
        ?string $descricao,
        string $severidade,
        array $extras = [],
    ): void {
        $this->achados[] = array_filter([
            'origem' => $origem,
            'chave' => mb_substr($chave, 0, 191),
            'titulo' => mb_substr($titulo, 0, 255),
            'descricao' => $descricao,
            'severidade' => $severidade,
        ] + $extras, fn ($v) => $v !== null);
    }

    /** Lê o .env como texto, sem depender do que a aplicação carregou. */
    private function lerEnv(string $caminho): array
    {
        $arquivo = $caminho.'/.env';

        if (! File::exists($arquivo) || ! File::isReadable($arquivo)) {
            return [];
        }

        $valores = [];

        foreach (explode("\n", File::get($arquivo)) as $linha) {
            $linha = trim($linha);

            if ($linha === '' || str_starts_with($linha, '#') || ! str_contains($linha, '=')) {
                continue;
            }

            [$chave, $valor] = explode('=', $linha, 2);
            $valores[trim($chave)] = trim($valor, " \t\"'");
        }

        return $valores;
    }

    private function ligado(mixed $valor): bool
    {
        return in_array(strtolower((string) $valor), ['true', '1', 'on', 'yes'], true);
    }

    private function traduzirSeveridade(?string $severidade): string
    {
        return match (strtolower((string) $severidade)) {
            'critical' => 'critica',
            'high' => 'alta',
            'medium' => 'media',
            'low' => 'baixa',
            // O aviso existe, mas o provedor não classificou: média evita
            // tanto o alarme falso quanto a falsa tranquilidade.
            default => 'media',
        };
    }
}
