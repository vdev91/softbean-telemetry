# softbean/telemetry

Cliente de auditoria e telemetria dos produtos Softbean. Envia trilha de
auditoria, saude, metricas de uso e custo de IA para o Softbean Desk.

## Instalacao

```bash
composer require softbean/telemetry
php artisan migrate
```

Cole no `.env` o bloco que o painel gera em **Produtos > (produto) > Ambientes
> Instrucoes de instalacao**:

```
SOFTBEAN_HUB_URL=https://softbean.com.br
SOFTBEAN_PRODUTO=elo-escolar
SOFTBEAN_AMBIENTE=producao
SOFTBEAN_CHAVE_PUBLICA=sbk_...
SOFTBEAN_CHAVE_SECRETA=sbs_...
SOFTBEAN_TELEMETRIA_ATIVA=true
```

Confira:

```bash
php artisan config:clear && php artisan optimize
php artisan softbean:testar-conexao
```

O config:clear vem antes de proposito: o processo do optimize boota com o
cache de config antigo, que ainda diz que a telemetria esta desligada, e monta
a colecao de rotas sem a rota de saude antes de cachear. Sem limpar a config
primeiro, o hub enxerga o produto como fora do ar mesmo com a ingestao
funcionando.

## O que ja funciona sem escrever codigo

- Login, logout, falha de login, bloqueio por tentativas e troca de senha.
- Heartbeat a cada 5 minutos com versao, git sha, migrations pendentes,
  jobs falhados e capacidades do produto.
- Endpoint assinado `/_softbean/health` para o hub consultar ativamente.

Requer o scheduler do Laravel rodando (`* * * * * php artisan schedule:run`).

## Auditar alteracoes de um model

```php
use Softbean\Telemetry\Concerns\Auditable;

class Aluno extends Model
{
    use Auditable;
}
```

Criar, alterar e excluir passam a gerar evento com diff de antes e depois.
Campos sensiveis (senha, token, cartao) tem o valor mascarado; o diff registra
que mudaram, nao o que sao.

E opt-in de proposito: auditar todo model afogaria a trilha em ruido.

## Registrar algo a mao

```php
use Softbean\Telemetry\Facades\Softbean;

Softbean::auditar(
    acao: 'exportou_relatorio_de_alunos',
    categoria: 'acesso_sensivel',
    severidade: 'aviso',
    contexto: ['turma' => '5A', 'formato' => 'pdf'],
);
```

Categorias validas: `autenticacao`, `dados`, `acesso_sensivel`,
`administracao`, `seguranca`, `sistema`, `financeiro`.

## Custo de IA

```php
$inicio = microtime(true);
$resposta = $this->chamarGemini($prompt);

Softbean::usoIa(
    provider: 'gemini',
    modelo: 'gemini-2.0-flash',
    tokensEntrada: $resposta['usageMetadata']['promptTokenCount'] ?? 0,
    tokensSaida: $resposta['usageMetadata']['candidatesTokenCount'] ?? 0,
    operacao: 'analise_de_contrato',
    latenciaMs: (int) ((microtime(true) - $inicio) * 1000),
);
```

## Metricas e tenants

```php
Softbean::metrica('usuarios_ativos', 42);
Softbean::tenants([
    ['ref' => 'escola-modelo', 'nome' => 'Escola Modelo', 'status' => 'ativo'],
]);
```

## Como nada se perde

Tudo e gravado primeiro na tabela `softbean_outbox`, no banco do proprio
produto, e so sai de la quando o hub confirma o recebimento. Hub fora do ar
significa fila acumulando, nao auditoria perdida. As tentativas usam recuo
exponencial e o heartbeat avisa quando ha evento represado.

Nenhum metodo do pacote lanca excecao: falha de telemetria vai para o log e
nunca derruba a operacao que o usuario pediu.
