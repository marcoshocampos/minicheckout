<?php

/*
|--------------------------------------------------------------------------
| Bootstrap da suíte de testes
|--------------------------------------------------------------------------
|
| FASE 2 — este arquivo existe por causa de um bug REAL, que a Fase 2
| encontrou do pior jeito possível: rodar `phpunit` dentro do container
| APAGAVA o banco de desenvolvimento.
|
| O caminho do bug, que vale mais que o conserto:
|
|   1. o docker-compose injeta DB_CONNECTION=pgsql, QUEUE_CONNECTION=redis e
|      CACHE_STORE=redis em TODO container (é topologia, e topologia vem do
|      compose por decisão do projeto);
|   2. o phpunit.xml declara sqlite/:memory: para os testes;
|   3. `php artisan test` dentro do container usava o Postgres de
|      desenvolvimento;
|   4. o primeiro teste com `RefreshDatabase` roda `migrate:fresh`, que faz
|      DROP de todas as tabelas.
|
| Doze pedidos da Fase 1 morreram para esta descoberta.
|
| E a parte que engana: `<env name="DB_CONNECTION" value="sqlite"/>` no
| phpunit.xml NÃO resolve, nem com `force="true"`. O phpunit escreve em
| `putenv()` e em `$_ENV`; o `Illuminate\Support\Env` lê por adaptadores, e o
| PRIMEIRO da fila é o `ServerConstAdapter`, que lê `$_SERVER`. No CLI do PHP,
| `$_SERVER` já vem com as variáveis do ambiente — então o valor do container
| continua ganhando, e silenciosamente.
|
| Por isso a escrita abaixo é nos TRÊS lugares. É um bootstrap de cinco linhas
| úteis, e é a diferença entre uma suíte segura e uma suíte que destrói dado.
|
| A lição é a mesma da fase: o caminho de erro mais perigoso é o que não
| reclama. Um teste que aponta para o banco errado passa — e é justamente por
| passar que ninguém olha.
|
*/

require __DIR__ . '/../vendor/autoload.php';

$apenasDoTeste = [
    // Nunca o Postgres de desenvolvimento: RefreshDatabase dropa tabela.
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    // Fila `sync` e cache/sessão em memória: teste não deve depender de
    // Redis de pé, nem deixar job na fila de verdade.
    'QUEUE_CONNECTION' => 'sync',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
];

foreach ($apenasDoTeste as $chave => $valor) {
    putenv("{$chave}={$valor}");
    $_ENV[$chave] = $valor;
    // O que faltava: o ServerConstAdapter do Laravel lê daqui primeiro.
    $_SERVER[$chave] = $valor;
}
