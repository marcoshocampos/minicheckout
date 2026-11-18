<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FASE 1 — ESQUELETO. A lógica é sua.
 *
 * Objetivo: toda requisição tem um id, e esse id aparece em TODO log que ela
 * produzir — inclusive nos logs do job que ela disparar, que roda em outro
 * processo, minutos depois, sem requisição HTTP nenhuma.
 *
 * Hoje o nginx já gera um `X-Request-Id` e o repassa para o PHP (veja
 * `docker/nginx/default.conf`). O que falta é o lado do Laravel.
 *
 * ---------------------------------------------------------------------------
 * O QUE IMPLEMENTAR, na ordem
 *
 * 1. LER o header. Se o cliente mandou um id, respeite: é o que permite ele
 *    dizer "deu erro, o id é X" e você achar a requisição.
 *
 *        $incoming = $request->header(self::HEADER);
 *
 * 2. VALIDAR o que veio. Isto é entrada de usuário e vai parar no seu log:
 *      - tamanho (um id de 10 KB enche o disco de quem armazena)
 *      - charset (quebra de linha no valor = uma linha de log falsa, injetada
 *        pelo cliente; é log injection de verdade)
 *    Decida a regra e descarte o que não passar, gerando um novo.
 *
 * 3. GERAR quando não vier. Opções nativas:
 *      - (string) Str::ulid()  -> ordenável por tempo, 26 chars
 *      - (string) Str::uuid()  -> uuid v4, aleatório
 *      - (string) Str::uuid7() -> ordenável por tempo
 *    ULID/UUIDv7 ordenam por tempo, o que ajuda quem indexa o log.
 *
 * 4. PUBLICAR no Context. Esta é a linha que faz a fase funcionar:
 *
 *        Context::add('request_id', $id);
 *
 *    O que o Context tem de especial (confirmado no código do framework):
 *      a) `Illuminate\Log\Context\ContextLogProcessor` é empurrado em TODO
 *         canal de log, e injeta o Context no campo `extra` do registro do
 *         Monolog. Ou seja: você NÃO precisa repetir request_id em cada
 *         `Log::info()`. Ele entra sozinho.
 *      b) `ContextServiceProvider` registra um `Queue::createPayloadUsing`
 *         que grava `Context::dehydrate()` no payload do job, e um listener
 *         de `JobProcessing` que faz `hydrate()` do outro lado. A travessia
 *         HTTP -> fila é AUTOMÁTICA. Você não passa o id para o job; ele
 *         chega junto.
 *
 *    Repare onde isso cai no JSON: em `extra`, não em `context`. Se você quer
 *    `request_id` na raiz do objeto, é aí que entra o tap
 *    `App\Logging\FormatLogsAsJson` (veja o arquivo).
 *
 * 5. DEVOLVER no response, sempre — inclusive em 4xx e 5xx:
 *
 *        $response->headers->set(self::HEADER, $id);
 *
 * ---------------------------------------------------------------------------
 * ONDE ISTO JÁ ESTÁ LIGADO
 *
 * `bootstrap/app.php` faz `$middleware->prepend(RequestId::class)`: global e
 * primeiro da fila. Global porque um 500 que acontece antes do roteamento
 * também precisa de id. Primeiro porque qualquer coisa que logue depois dele
 * já tem o contexto.
 *
 * ---------------------------------------------------------------------------
 * PERGUNTAS PARA RESPONDER COM CÓDIGO
 *
 * - O `order_id` também deveria entrar no Context? Onde? (O `OrderController`
 *   loga `order_id` na mão hoje — depois do Context, isso fica redundante.)
 * - `Context::add()` vs `Context::addHidden()`: o que você NÃO quer que vaze
 *   para o log, mas quer que atravesse para o job?
 * - O gateway recebe `X-Request-Id` e o devolve no webhook. Quando o webhook
 *   chega, ele é a MESMA requisição ou uma nova? O que isso significa para o
 *   id que você grava?
 * - Dois processos (web e worker) escrevendo o mesmo `request_id`: como você
 *   distingue no log quem escreveu o quê?
 */
class RequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        // TODO: 1-4 acima, antes do $next (o resto da aplicação já tem que
        //       enxergar o contexto).

        $response = $next($request);

        // TODO: 5 acima.

        return $response;
    }
}
