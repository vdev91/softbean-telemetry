<?php

return [

    // Desligar aqui para o pacote virar no-op: nada e gravado, nada e enviado.
    // Util em ambiente local e em teste automatizado.
    'ativo' => env('SOFTBEAN_TELEMETRIA_ATIVA', false),

    'hub' => [
        'url' => env('SOFTBEAN_HUB_URL'),
        'chave_publica' => env('SOFTBEAN_CHAVE_PUBLICA'),
        'chave_secreta' => env('SOFTBEAN_CHAVE_SECRETA'),
        'timeout' => (int) env('SOFTBEAN_HUB_TIMEOUT', 10),
    ],

    'produto' => env('SOFTBEAN_PRODUTO'),
    'ambiente' => env('SOFTBEAN_AMBIENTE', 'producao'),

    'outbox' => [
        // Eventos ficam nesta tabela ate serem confirmados pelo hub. Se o hub
        // cair, nada se perde: acumula aqui e sai no proximo envio.
        'tabela' => 'softbean_outbox',

        // Em produto multi-tenant, fixe aqui a conexao CENTRAL (ex.: 'pgsql').
        //
        // Sem isto, um evento gerado dentro do contexto de um tenant vai para
        // o banco daquele tenant, e o comando de envio — que roda no contexto
        // central — nunca o encontra. A auditoria de cada escola ficaria presa
        // no banco da propria escola, sem ninguem para envia-la.
        //
        // Produto de banco unico pode deixar null: usa a conexao padrao.
        'conexao' => env('SOFTBEAN_OUTBOX_CONEXAO'),

        'tamanho_do_lote' => (int) env('SOFTBEAN_LOTE', 200),
        'max_tentativas' => (int) env('SOFTBEAN_MAX_TENTATIVAS', 10),
        'reter_enviados_horas' => (int) env('SOFTBEAN_RETER_ENVIADOS', 24),
    ],

    'auditoria' => [
        // Eventos de login, logout, falha de login e troca de senha sao
        // capturados sozinhos, sem precisar tocar no codigo do produto.
        'capturar_autenticacao' => true,

        // Campos cujo valor nunca deve sair do produto. O diff registra que o
        // campo mudou, mas troca o valor por uma marca.
        'campos_mascarados' => [
            'password', 'senha', 'password_confirmation', 'remember_token',
            'token', 'api_key', 'secret', 'chave_secreta', 'access_token',
            'refresh_token', 'cvv', 'cartao', 'numero_cartao',
        ],

        // Campos que, se presentes num diff, marcam o evento como contendo
        // dado pessoal — o que encurta a retencao no hub.
        'campos_dado_pessoal' => [
            'cpf', 'cnpj', 'rg', 'email', 'telefone', 'celular', 'endereco',
            'cep', 'data_nascimento', 'nascimento', 'nome', 'responsavel',
        ],

        // Ruido que nao interessa a auditoria.
        'ignorar_campos' => [
            'updated_at', 'created_at', 'remember_token', 'last_login_at',
        ],
    ],

    'saude' => [
        // Endpoint que o hub consulta para saber se o produto responde.
        'rota_ativa' => env('SOFTBEAN_ROTA_SAUDE', true),
        'prefixo' => '_softbean',
    ],

];
