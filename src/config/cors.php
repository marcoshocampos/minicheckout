<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    /*
    | Publicado para o "Try it out" do Swagger UI funcionar.
    |
    | O Swagger UI roda em http://localhost:8012 e a API em
    | http://localhost:8010 — origens diferentes, então o navegador faz
    | preflight (OPTIONS) e exige os headers de CORS na resposta.
    |
    | Sem isto o botão falha com um erro de CORS no console, que parece um
    | problema da API e não é. E repare: as rotas deste projeto NÃO têm o
    | prefixo /api (apiPrefix: '' em bootstrap/app.php), então o default
    | `api/*` do Laravel não as cobriria.
    */
    'paths' => ['orders', 'orders/*', 'webhooks/*', 'up'],

    'allowed_methods' => ['*'],

    // Origem explícita, não '*': o Swagger UI local é o único consumidor
    // cross-origin previsto. Curinga em CORS é o tipo de default que a gente
    // copia sem pensar e depois esquece em produção.
    'allowed_origins' => [
        env('SWAGGER_UI_ORIGIN', 'http://localhost:8012'),
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Sem isto, JavaScript no navegador NÃO consegue ler estes headers, mesmo
    // que eles cheguem na resposta. É a regra do CORS que mais confunde:
    // o header está lá, o DevTools mostra, e `response.headers.get()` devolve
    // null. X-Request-Id aqui é o que permite a Fase 1 ser visível no Swagger.
    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 0,

    'supports_credentials' => false,

];
