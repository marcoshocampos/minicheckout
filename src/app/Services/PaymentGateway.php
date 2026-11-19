<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

/**
 * Cliente do gateway fake.
 *
 * FASE 0: deliberadamente ingênuo. Sem timeout, sem retry, sem tratamento.
 * Se o gateway pendurar, esta chamada pendura com ele e o seu cliente também.
 * Ligue `GATEWAY_LATENCY_MS=5000` e veja a requisição inteira travar.
 *
 * ===========================================================================
 * FASE 2 — TIMEOUT E RETRY: o que entra aqui
 *
 * As alavancas já existem em config/services.php (`services.gateway.*`), com
 * env em src/.env. O que falta é usá-las, e escolher a política.
 *
 *     return Http::acceptJson()
 *         ->timeout(config('services.gateway.timeout'))
 *         ->connectTimeout(config('services.gateway.connect_timeout'))
 *         ->retry(
 *             times: config('services.gateway.tries'),
 *             sleepMilliseconds: $backoff,     // closure, ver abaixo
 *             when: $retryable,                // closure, ver abaixo
 *             throw: false,
 *         )
 *         ->withHeaders([...])
 *         ->post($url, [...]);
 *
 * ---------------------------------------------------------------------------
 * 1. TIMEOUT: são DOIS, e eles medem coisas diferentes
 *
 *    connectTimeout  só o aperto de mão TCP. Host morto, DNS errado, firewall
 *                    que descarta pacote caem aqui. Pode ser curto: 1s.
 *    timeout         o tempo TOTAL até a resposta completa. É o que protege
 *                    você do gateway que aceita a conexão e some.
 *
 *    Sem `connectTimeout`, um host que não responde ao SYN te deixa esperando
 *    o default do sistema operacional (dezenas de segundos) ANTES de o
 *    `timeout` começar a valer para alguma coisa útil.
 *
 *    Como escolher o número: olhe o p99 do gateway e dê alguma folga. Timeout
 *    em cima do p50 é retry desnecessário em metade do tráfego; timeout de 30s
 *    é não ter timeout.
 *
 * 2. O ORÇAMENTO TOTAL tem um teto, e ele não é seu
 *
 *    pior caso = tries x timeout + soma dos backoffs
 *
 *    Neste projeto o nginx tem `fastcgi_read_timeout 60s`
 *    (docker/nginx/default.conf:64). Se o orçamento passar de 60s, o nginx
 *    corta a requisição e devolve um 504 em HTML — o seu Problem Details nunca
 *    é montado, e o cliente recebe uma página de erro do nginx. Ou seja: o
 *    retry que não cabe no tempo de quem te chama não existe; ele só consome
 *    worker de php-fpm e atrasa todo mundo.
 *
 *    Teste isto de propósito: `GATEWAY_TIMEOUT_RATE=1` com
 *    `GATEWAY_TIMEOUT_SLEEP_MS=30000` e 3 tentativas = 90s > 60s.
 *
 * 3. O QUE RETENTAR (o `when`)
 *
 *    O callback é chamado com `(Throwable $e, PendingRequest $request,
 *    ?string $method)` e devolve bool. O terceiro argumento é o verbo HTTP —
 *    existe justamente para você poder dizer "GET eu retento sempre, POST só
 *    se eu souber que não foi processado".
 *
 *    Como o Laravel transforma resposta em exceção para passar por aqui:
 *    resposta malsucedida vira `RequestException` (via
 *    `$response->toException()`), e aí `$e->response->status()` é o status.
 *    Erro de rede vira `Illuminate\Http\Client\ConnectionException`, que NÃO
 *    tem `->response`.
 *
 *      ConnectionException   -> RETENTAR. Mas veja o item 5: timeout é
 *                              diferente de conexão recusada.
 *      503                   -> RETENTAR. É o status que significa "volte
 *                              depois"; respeite o `Retry-After` se vier.
 *      500                   -> AMBÍGUO, e é a decisão mais interessante
 *                              desta fase. O gateway pode ter cobrado e
 *                              explodido ao montar a resposta. Retentar pode
 *                              cobrar duas vezes.
 *      400 / 402 / 404 / 422 -> NUNCA. A resposta não vai mudar. Retentar um
 *                              400 é três vezes o mesmo erro, três vezes mais
 *                              lento, e três linhas de ruído no log.
 *      429                   -> retentar, mas obedecendo o `Retry-After` do
 *                              header, não o seu backoff.
 *
 * 4. BACKOFF: exponencial, com JITTER
 *
 *    `sleepMilliseconds` aceita uma closure `(int $attempt, Throwable $e)`:
 *
 *      fn (int $attempt) => (int) (100 * (2 ** ($attempt - 1)))
 *                            + random_int(0, 100);
 *
 *    Sem backoff, as 3 tentativas batem no gateway que já está mal em poucos
 *    milissegundos. Com backoff FIXO e sem jitter, mil clientes que falharam
 *    no mesmo segundo voltam juntos no mesmo segundo seguinte: você construiu
 *    um DDoS sincronizado contra o seu próprio gateway. O jitter é o que
 *    espalha a volta; é uma linha de código e é a diferença entre o gateway
 *    conseguir levantar ou não.
 *
 *    (Alternativa: `times` como array de atrasos — `retry([100, 500, 2000])`.
 *    Explícito e sem jitter.)
 *
 * 5. O QUE O `throw: false` FAZ, E O QUE ELE NÃO FAZ
 *
 *    Com `throw: false`, esgotadas as tentativas, a última RESPOSTA
 *    malsucedida é devolvida em vez de lançada — é o que mantém o
 *    `$response->status() === 402` do OrderController funcionando.
 *
 *    Mas `ConnectionException` é lançada SEMPRE, com `throw: false` ou não:
 *    não existe resposta para devolver. Então esta função tem dois caminhos de
 *    saída (um `return` e um `throw`), e quem chama precisa lidar com os dois.
 *    Isso não é detalhe de implementação — é a diferença entre
 *    GatewayUnavailable e PaymentUnconfirmed:
 *
 *      ConnectionException por connect timeout / conexão recusada
 *          -> a requisição não chegou   -> GatewayUnavailable
 *      ConnectionException por timeout de leitura (a cobrança foi enviada)
 *          -> não sabemos o resultado   -> PaymentUnconfirmed
 *
 *    O Guzzle não te entrega esses dois casos em classes diferentes: vem tudo
 *    como ConnectionException, e a dica está na mensagem (cURL error 28
 *    "Operation timed out" vs 7 "Failed to connect"). Decidir quanto disso
 *    você quer inspecionar é parte da fase.
 *
 * 6. ONDE LOGAR A TENTATIVA
 *
 *    O `when` é o único lugar que sabe que vai haver outra tentativa. Um
 *    `Log::warning('gateway.retrying', ['attempt' => ..., 'status' => ...])`
 *    ali é o que torna o retry VISÍVEL — senão o cliente espera 4 segundos e
 *    o log não explica por quê. (Na Fase 4 cada tentativa vira um span e isso
 *    aparece desenhado.)
 *
 * ---------------------------------------------------------------------------
 * E A PERGUNTA CENTRAL DO PROJETO
 *
 * Toda vez que você retenta um POST que pode ter sido processado, você passa a
 * depender da idempotência do OUTRO lado. O `idempotency_key` já vai no corpo
 * da chamada abaixo; o gateway fake IGNORA de propósito.
 *
 * Então, depois de ligar o retry, meça:
 *
 *     curl -s localhost:8011/_stats | jq .attempts_total
 *
 * 1 requisição do cliente com `GATEWAY_FAIL_RATE=1` e 3 tentativas = 3
 * cobranças tentadas. Com um gateway de verdade, que cobra mesmo, isso é
 * dinheiro. É o motivo de a Fase 3 existir, e é por isso que "retry sem
 * idempotência é perigoso" não é slogan.
 * ===========================================================================
 */
class PaymentGateway
{
    public function charge(Order $order, string $idempotencyKey): Response
    {
        $url = rtrim((string) config('services.gateway.url'), '/') . '/charge';

        return Http::acceptJson()
            // FASE 2: ->timeout(), ->connectTimeout() e ->retry() entram aqui.
            ->withHeaders([
                // FASE 1: o id sai do Context, não de request(). Duas razões:
                // o Context tem o valor já VALIDADO pelo middleware, e o retry
                // desta chamada pode acontecer dentro de um job, onde
                // request() não existe.
                // FASE 4: aqui também entra o header `traceparent` (W3C).
                'X-Request-Id' => (string) Context::get('request_id', '-'),
            ])
            ->post($url, [
                'order_id' => $order->id,
                'amount_cents' => $order->amount_cents,
                'currency' => $order->currency,
                // Vai desde a Fase 0 e ninguém do outro lado respeita. Quando
                // o retry do item 3 acima estiver ligado, é esta chave que
                // deveria impedir a cobrança dupla — e é a Fase 3 que faz o
                // nosso lado honrar a dela.
                'idempotency_key' => $idempotencyKey,
            ]);
    }
}
