<?php

/*
|------------------------------------------------------------------------------
| Caos do NOSSO lado
|------------------------------------------------------------------------------
|
| O gateway fake sabe falhar sozinho (GATEWAY_FAIL_RATE, GATEWAY_TIMEOUT_RATE,
| no .env da raiz). Mas ele só falha no caminho da COBRANÇA — e metade do
| tratamento de erro da Fase 2 está na FILA, onde o gateway não entra.
|
| O job de notificação não chama ninguém: ele grava `notified_at` e loga. Sem
| uma alavanca, ele nunca falha, e então `$tries`, `backoff()`, `failed()` e a
| tabela `failed_jobs` ficam sendo teoria. Daí este arquivo.
|
| É instrumento de teste, igual ao caos do gateway: por env, para você ligar e
| desligar sem editar código.
|
|   NOTIFICATION_FAIL_RATE=1   em src/.env
|   docker compose restart worker       <- o worker NÃO recarrega código nem
|                                          config sozinho (ver Fase 1)
|
| A Fase 6 ("Caos") cresce aqui.
*/

return [

    'notification' => [
        /*
         * Fração das execuções do SendOrderNotification que explodem de
         * propósito, de 0 a 1. A falha acontece DEPOIS do log
         * `notification.sending` e ANTES de gravar `notified_at`: é o caso
         * ruim de verdade, o do trabalho que começou e não terminou.
         *
         * Com 1, o job falha sempre e você vê a sequência inteira: as
         * tentativas com o backoff entre elas, o `failed()` e a linha na
         * dead letter queue.
         *
         * Com 0.5, metade falha — é o cenário que ensina por que `backoff`
         * exponencial importa: as que falham param de competir com as que
         * ainda têm chance.
         */
        'fail_rate' => (float) env('NOTIFICATION_FAIL_RATE', 0),
    ],

];
