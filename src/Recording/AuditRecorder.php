<?php

namespace Softbean\Telemetry\Recording;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Softbean\Telemetry\Support\TokenExtractor;
use Throwable;

/**
 * A porta de entrada da telemetria dentro do produto.
 *
 * Todo método aqui é à prova de falha: se registrar der errado, a operação do
 * usuário segue e o problema vai para o log. Auditoria que derruba a aplicação
 * acaba desligada na primeira sexta-feira ruim, e aí não há auditoria nenhuma.
 */
class AuditRecorder
{
    /** Ligado durante uma operação em lote. Ver semAuditoriaDeModelos(). */
    private bool $modelosPausados = false;

    public function __construct(
        private readonly Outbox $outbox,
        private readonly Masker $mascarador,
    ) {}

    public function ativo(): bool
    {
        return (bool) config('softbean-telemetry.ativo', false);
    }

    /**
     * Registra um evento de auditoria.
     *
     * @param  array<string, mixed>  $alteracoes  Diff no formato [campo => [de, para]]
     */
    public function auditar(
        string $acao,
        string $categoria = 'dados',
        string $severidade = 'info',
        ?Model $alvo = null,
        array $alteracoes = [],
        array $contexto = [],
        ?string $tenant = null,
        ?bool $contemDadoPessoal = null,
        mixed $ator = null,
    ): void {
        if (! $this->ativo()) {
            return;
        }

        try {
            $ator ??= Auth::user();

            // Vale também para o diff montado à mão: senha e token não saem
            // daqui nem quando quem chamou não percebeu que passou um.
            $alteracoes = $this->mascarador->mascararDiff($alteracoes);

            $this->outbox->enfileirar('events', [
                'uid' => (string) Str::uuid(),
                'categoria' => $categoria,
                'acao' => $acao,
                'severidade' => $severidade,
                'ocorrido_em' => now()->format('Y-m-d\TH:i:s.uP'),
                'tenant' => $tenant ?? $this->tenantAtual(),
                'ator' => $this->descreverAtor($ator),
                'alvo' => $this->descreverAlvo($alvo),
                'alteracoes' => $alteracoes,
                'contexto' => $contexto,
                'requisicao' => $this->descreverRequisicao(),
                'contem_dado_pessoal' => $contemDadoPessoal
                    ?? $this->mascarador->contemDadoPessoal(array_keys($alteracoes)),
            ]);
        } catch (Throwable $e) {
            $this->reportar($e);
        }
    }

    /**
     * Silencia a auditoria automática de models durante uma operação em lote.
     *
     * Importar 500 alunos não são 500 fatos auditáveis — é um. Sem isto, a
     * trilha afoga o evento que interessa ("fulano importou 500 registros")
     * numa enxurrada de "criou HistoricoAluno", e cada linha ainda carrega
     * uma escrita a mais dentro do request que o usuário está esperando.
     *
     * Só afeta o gatilho automático dos models. Chamadas explícitas a
     * auditar() continuam funcionando — é justamente assim que se registra o
     * resumo da operação.
     */
    public function semAuditoriaDeModelos(callable $operacao): mixed
    {
        $anterior = $this->modelosPausados;
        $this->modelosPausados = true;

        try {
            return $operacao();
        } finally {
            // finally, e não depois do return: uma exceção no meio do lote
            // não pode deixar a auditoria desligada para o resto do request.
            $this->modelosPausados = $anterior;
        }
    }

    /** Audita uma mudança de model calculando o diff sozinho. */
    public function auditarModelo(Model $modelo, string $acao, array $contexto = []): void
    {
        if (! $this->ativo() || $this->modelosPausados) {
            return;
        }

        try {
            $alteracoes = match ($acao) {
                'criou' => $this->mascarador->diff([], $modelo->getAttributes()),
                'excluiu', 'excluiu_definitivamente' => $this->mascarador->snapshotRemocao($modelo->getOriginal()),
                default => $this->mascarador->diff($modelo->getOriginal(), $modelo->getChanges()),
            };

            $alteracoes = $this->esconderCamposDoModelo($modelo, $alteracoes);

            // Update que não mudou nada de relevante (só o updated_at) não
            // merece uma linha na trilha.
            if ($acao === 'atualizou' && $alteracoes === []) {
                return;
            }

            $this->auditar(
                acao: $acao,
                categoria: 'dados',
                alvo: $modelo,
                alteracoes: $alteracoes,
                contexto: $contexto,
            );
        } catch (Throwable $e) {
            $this->reportar($e);
        }
    }

    /** Registra uma chamada de IA com o custo que ela gerou. */
    public function usoIa(
        string $provider,
        string $modelo,
        int $tokensEntrada = 0,
        int $tokensSaida = 0,
        ?string $operacao = null,
        ?float $custoUsd = null,
        ?int $latenciaMs = null,
        bool $sucesso = true,
        ?string $erro = null,
        ?string $tenant = null,
    ): void {
        if (! $this->ativo()) {
            return;
        }

        try {
            $this->outbox->enfileirar('ai-usage', array_filter([
                'provider' => $provider,
                'modelo' => $modelo,
                'operacao' => $operacao,
                'tokens_entrada' => $tokensEntrada,
                'tokens_saida' => $tokensSaida,
                'custo_usd' => $custoUsd,
                'latencia_ms' => $latenciaMs,
                'sucesso' => $sucesso,
                'erro' => $erro,
                'tenant' => $tenant ?? $this->tenantAtual(),
                'actor_email' => Auth::user()?->email,
                'ocorrido_em' => now()->format('Y-m-d\TH:i:s'),
            ], fn ($v) => $v !== null));
        } catch (Throwable $e) {
            $this->reportar($e);
        }
    }

    /**
     * Registra uma chamada de IA lendo a contagem de tokens direto da resposta
     * bruta do provedor.
     *
     * Poupa cada produto de saber que o Gemini chama de `promptTokenCount`, o
     * Claude de `input_tokens` e a OpenAI de `prompt_tokens` — e de descobrir
     * que mudou quando o custo aparecer zerado no painel.
     */
    public function usoIaDaResposta(
        string $provider,
        string $modelo,
        ?array $resposta,
        ?string $operacao = null,
        ?int $latenciaMs = null,
        bool $sucesso = true,
        ?string $erro = null,
        ?string $tenant = null,
    ): void {
        if (! $this->ativo()) {
            return;
        }

        $tokens = TokenExtractor::extrair($resposta, $provider);

        $this->usoIa(
            provider: $provider,
            modelo: $modelo,
            tokensEntrada: $tokens['entrada'],
            tokensSaida: $tokens['saida'],
            operacao: $operacao,
            latenciaMs: $latenciaMs,
            sucesso: $sucesso,
            erro: $erro,
            tenant: $tenant,
        );
    }

    /** Registra uma métrica diária. Reenviar o mesmo dia sobrescreve. */
    public function metrica(string $metrica, float $valor, ?string $unidade = null, ?string $tenant = null, ?string $dia = null): void
    {
        if (! $this->ativo()) {
            return;
        }

        try {
            $this->outbox->enfileirar('metrics', array_filter([
                'metrica' => $metrica,
                'valor' => $valor,
                'unidade' => $unidade,
                'tenant' => $tenant,
                'dia' => $dia ?? now()->toDateString(),
            ], fn ($v) => $v !== null));
        } catch (Throwable $e) {
            $this->reportar($e);
        }
    }

    /** Sincroniza a lista de organizações de um produto multi-tenant. */
    public function tenants(array $tenants): void
    {
        if (! $this->ativo() || $tenants === []) {
            return;
        }

        try {
            foreach ($tenants as $tenant) {
                $this->outbox->enfileirar('tenants', $tenant);
            }
        } catch (Throwable $e) {
            $this->reportar($e);
        }
    }

    /**
     * Aplica a lista de campos que o model declarou como "não sai daqui".
     *
     * Preserva a chave e marca o valor, para a trilha continuar mostrando que
     * o campo mudou sem carregar o conteúdo.
     */
    private function esconderCamposDoModelo(Model $modelo, array $alteracoes): array
    {
        if (! method_exists($modelo, 'softbeanCamposNaoEnviados')) {
            return $alteracoes;
        }

        foreach ($modelo->softbeanCamposNaoEnviados() as $campo) {
            if (array_key_exists($campo, $alteracoes)) {
                $alteracoes[$campo] = '[conteúdo não enviado]';
            }
        }

        return $alteracoes;
    }

    /**
     * Descobre o tenant atual sem exigir que o produto use stancl/tenancy.
     * Quem não é multi-tenant simplesmente devolve null.
     */
    private function tenantAtual(): ?string
    {
        try {
            if (function_exists('tenant')) {
                $tenant = tenant();

                return $tenant?->getTenantKey() ? (string) $tenant->getTenantKey() : null;
            }
        } catch (Throwable) {
            // Fora de contexto de tenant: não é erro.
        }

        return null;
    }

    private function descreverAtor(mixed $ator): array
    {
        if ($ator instanceof Model) {
            return [
                'tipo' => 'usuario',
                'ref' => (string) $ator->getKey(),
                'nome' => $ator->getAttribute('name') ?? $ator->getAttribute('nome'),
                'email' => $ator->getAttribute('email'),
                'papel' => $ator->getAttribute('role')
                    ?? $ator->getAttribute('papel')
                    ?? $ator->getAttribute('tipo'),
            ];
        }

        if (is_array($ator)) {
            return $ator;
        }

        // Sem usuário autenticado: ou é console, ou é visitante anônimo.
        return [
            'tipo' => app()->runningInConsole() ? 'sistema' : 'anonimo',
            'nome' => app()->runningInConsole() ? 'console' : null,
        ];
    }

    private function descreverAlvo(?Model $alvo): array
    {
        if (! $alvo) {
            return [];
        }

        return [
            'tipo' => $alvo::class,
            'ref' => (string) $alvo->getKey(),
            'rotulo' => $this->rotularAlvo($alvo),
        ];
    }

    /** Um rótulo legível para a trilha não virar uma lista de IDs. */
    private function rotularAlvo(Model $alvo): ?string
    {
        foreach (['nome', 'name', 'titulo', 'title', 'assunto', 'descricao', 'email'] as $campo) {
            $valor = $alvo->getAttribute($campo);

            if (filled($valor) && is_string($valor)) {
                return Str::limit($valor, 200, '');
            }
        }

        return class_basename($alvo).' #'.$alvo->getKey();
    }

    private function descreverRequisicao(): array
    {
        if (app()->runningInConsole()) {
            return ['rota' => 'console'];
        }

        $request = request();

        return array_filter([
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'rota' => $request?->route()?->getName() ?? $request?->path(),
            'metodo' => $request?->getMethod(),
            'request_id' => $request?->header('X-Request-Id'),
        ], fn ($v) => $v !== null);
    }

    private function reportar(Throwable $e): void
    {
        if (function_exists('report')) {
            report($e);
        }
    }
}
