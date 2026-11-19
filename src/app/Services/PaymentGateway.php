<?php

namespace App\Services;

use App\Exceptions\GatewayUnavailable;
use App\Exceptions\PaymentUnconfirmed;
use App\Models\Order;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Cliente do gateway fake.
 *
 * ===========================================================================
 * FASE 2 — A DECISÃO DESTE ARQUIVO
 *
 * Esta classe tem UMA responsabilidade conceitual: traduzir o que aconteceu no
 * fio para uma das três respostas possíveis do mundo real.
 *
 *   sucesso        o gateway respondeu                    -> devolve a Response
 *   NÃO cobrou     e nós temos prova disso                -> GatewayUnavailable
 *   NÃO SABEMOS    pode ter cobrado                       -> PaymentUnconfirmed
 *
 * O terceiro estado é o que quase nenhum código tem, e é o que importa: quem
 * junta "não cobrou" com "não sei" no mesmo `catch` acaba retentando um POST
 * que pode ter movido dinheiro.
 *
 * A regra desta classe, e é a única regra:
 *
 *   SÓ RETENTA QUANDO HÁ PROVA DE QUE A COBRANÇA NÃO FOI PROCESSADA.
 *
 * ---------------------------------------------------------------------------
 * A TABELA (é o contrato interno da fase)
 *
 *   o que aconteceu                      retenta?  vira                HTTP
 *   -----------------------------------  --------  ------------------  ----
 *   conexão recusada / DNS / connect     SIM       GatewayUnavailable  503
 *     timeout (não chegou a sair)
 *   408, 429, 503 (o gateway disse       SIM       GatewayUnavailable  503
 *     "não processei, volte depois")
 *   timeout de LEITURA (foi, e não       não       PaymentUnconfirmed  504
 *     voltou resposta)
 *   500, 502, 504 (respondeu depois      não       PaymentUnconfirmed  504
 *     de ter trabalhado — ou não)
 *   402 recusa                           não       PaymentDeclined     402
 *     (quem lança é o controller)
 *   400/404/405 (payload errado)         não       bug NOSSO           500
 *
 * Enquanto a Fase 3 não existir, a linha do 500 é a mais frustrante: o retry
 * resolveria 9 de 10 casos, e a gente não liga porque o décimo é cobrança
 * dupla. É isso que a idempotência compra — quando o OUTRO lado honrar a
 * `idempotency_key`, aquelas duas linhas viram SIM e o 504 quase desaparece.
 *
 * ---------------------------------------------------------------------------
 * COMO SE DISTINGUE "NÃO CHEGOU" DE "NÃO SEI" (a parte que ninguém faz)
 *
 * O Laravel entrega TODA falha de rede como uma `ConnectionException` só, o
 * que empurra para o mesmo `catch` dois casos de risco completamente
 * diferentes. Mas a informação não se perde: a exceção do Guzzle fica no
 * `getPrevious()`, e o Guzzle 8 tem uma taxonomia precisa —
 *
 *   ConnectException         errno 6/7/35: nem conectou      -> não chegou
 *     + ConnectTimeoutException  estourou o aperto de mão     -> não chegou
 *   NetworkTimeoutException  errno 28 depois de conectar     -> NÃO SEI
 *   ResponseTimeoutException resposta começou e parou        -> NÃO SEI
 *   NetworkException         errno 52/55/56 (send/recv)      -> NÃO SEI
 *
 * `ConnectTimeoutException extends ConnectException`, então um `instanceof`
 * em `ConnectException` cobre os dois casos seguros. E o default, quando não
 * há `previous` ou a classe é desconhecida, é o PESSIMISTA: não sei. Nunca
 * afirme "não cobrou" sem prova.
 *
 * ---------------------------------------------------------------------------
 * OS DOIS TIMEOUTS, E O TETO QUE NÃO É SEU
 *
 *   connectTimeout (1s)  só o aperto de mão TCP. Host morto, DNS errado,
 *                        firewall que descarta pacote. Pode ser curto.
 *   timeout (3s)         o tempo TOTAL até a resposta completa.
 *
 * Sem `connectTimeout`, um host que não responde ao SYN te deixa esperando o
 * default do sistema operacional antes de o `timeout` valer para algo útil.
 *
 *   pior caso = tries x timeout + soma dos backoffs = 3x3s + ~0,3s = ~9,3s
 *
 * O nginx deste projeto corta em `fastcgi_read_timeout 60s`
 * (docker/nginx/default.conf:64). Orçamento acima disso = o nginx devolve um
 * 504 em HTML, o Problem Details nunca é montado e o cliente recebe uma
 * página de erro. Retry que não cabe no tempo de quem te chama não existe:
 * só consome worker de php-fpm.
 *
 * ---------------------------------------------------------------------------
 * BACKOFF COM JITTER, E POR QUE O JITTER NÃO É ENFEITE
 *
 * Backoff fixo sincroniza: mil clientes que falharam no mesmo segundo voltam
 * juntos no segundo seguinte, e você construiu um DDoS contra o seu próprio
 * gateway na hora em que ele está mais fraco. O `random_int` abaixo espalha a
 * volta. É uma linha, e é a diferença entre o gateway levantar ou não.
 *
 * ---------------------------------------------------------------------------
 * E MEÇA O PREÇO DO RETRY
 *
 *     curl -s localhost:8011/_stats | jq '.attempts_total'
 *
 * Com `GATEWAY_FAIL_RATE=1 GATEWAY_FAIL_STATUS=503`, 1 requisição do cliente
 * = 3 cobranças TENTADAS, porque o gateway fake ignora a `idempotency_key` de
 * propósito. Num gateway de verdade, que cobra mesmo, cada tentativa dessas é
 * dinheiro. É por isso que "retry sem idempotência é perigoso" não é slogan.
 * ===========================================================================
 */
class PaymentGateway
{
    /**
     * Status em que o próprio gateway afirma não ter processado a cobrança.
     * Só estes são seguros para retentar, e é por isso que 500 não está aqui.
     */
    private const RETRYABLE_STATUSES = [408, 429, 503];

    public function charge(Order $order, string $idempotencyKey): Response
    {
        $url = rtrim((string) config('services.gateway.url'), '/') . '/charge';

        // Contagem EXATA de tentativas. O cliente HTTP do Laravel não conta
        // para quem chamou, e o `when` do retry não é consultado na última
        // tentativa de erro de conexão — contar ali daria um número errado.
        // O middleware de request roda uma vez por chamada FÍSICA.
        $attempts = 0;

        try {
            $response = Http::acceptJson()
                ->timeout((float) config('services.gateway.timeout'))
                ->connectTimeout((float) config('services.gateway.connect_timeout'))
                ->withRequestMiddleware(function (RequestInterface $request) use (&$attempts): RequestInterface {
                    $attempts++;

                    return $request;
                })
                ->retry(
                    times: max(1, (int) config('services.gateway.tries')),
                    sleepMilliseconds: fn (int $attempt): int => $this->backoffMs($attempt),
                    // Closure e não arrow function: `$attempts` precisa vir por
                    // REFERÊNCIA (arrow function captura o valor do momento em
                    // que é criada, que aqui é sempre zero).
                    when: function (Throwable $e) use (&$attempts): bool {
                        return $this->shouldRetry($e, $attempts);
                    },
                    // Esgotadas as tentativas, devolve a última RESPOSTA em vez
                    // de lançar. Não suprime ConnectionException: não existe
                    // resposta para devolver. Daí o catch abaixo.
                    throw: false,
                )
                ->withHeaders([
                    // FASE 1: o id sai do Context, não de request(). O Context
                    // tem o valor já VALIDADO pelo middleware, e o retry desta
                    // chamada pode acontecer dentro de um job, onde request()
                    // não existe.
                    // FASE 4: aqui também entra o header `traceparent` (W3C).
                    'X-Request-Id' => (string) Context::get('request_id', '-'),
                ])
                ->post($url, [
                    'order_id' => $order->id,
                    'amount_cents' => $order->amount_cents,
                    'currency' => $order->currency,
                    // Vai desde a Fase 0 e ninguém do outro lado respeita.
                    // É esta chave que DEVERIA impedir a cobrança dupla do
                    // retry acima — e é a Fase 3 que faz o nosso lado honrar
                    // a dela.
                    'idempotency_key' => $idempotencyKey,
                ]);
        } catch (ConnectionException $e) {
            // Não houve resposta. A única pergunta que importa: a requisição
            // chegou a sair?
            throw $this->fromConnectionException($e, $order, max($attempts, 1));
        }

        return $this->ensureUsable($response, $order, max($attempts, 1));
    }

    /**
     * A política de retry, num lugar só.
     *
     * Chamada com a exceção equivalente da tentativa que acabou de falhar:
     * resposta malsucedida chega como `RequestException` (o Laravel converte
     * via `$response->toException()`), falha de rede como `ConnectionException`.
     *
     * ARMADILHA DO `when`, e ela faz o log MENTIR se você não souber: o
     * callback é consultado também na ÚLTIMA tentativa quando a falha é uma
     * RESPOSTA (o `tap` interno de `PendingRequest::send` o chama antes de
     * olhar o contador), e NÃO é consultado na última quando a falha é de
     * conexão (aí quem decide é o `retry()` de helpers.php, que checa o
     * contador primeiro). Ou seja: "vou retentar" dito aqui não é a mesma
     * coisa que "vai haver outra tentativa".
     *
     * Por isso o `$attempt` entra na decisão. Devolver `false` na última
     * tentativa não muda comportamento nenhum — com `throw: false` a resposta
     * é devolvida de qualquer jeito —, muda o LOG: três linhas "retrying"
     * para duas retentativas é o tipo de erro que só se descobre contando.
     */
    private function shouldRetry(Throwable $e, int $attempt): bool
    {
        $tries = max(1, (int) config('services.gateway.tries'));
        $status = $e instanceof RequestException ? $e->response->status() : null;

        // Há PROVA de que a cobrança não foi processada?
        $seguro = match (true) {
            $e instanceof ConnectionException => $this->neverArrived($e),
            $status !== null => in_array($status, self::RETRYABLE_STATUSES, true),
            default => false,
        };

        if ($seguro && $attempt < $tries) {
            // Sem esta linha o cliente espera 9 segundos e o log não explica
            // por quê. (Na Fase 4 cada tentativa vira um span.)
            Log::warning('gateway.retrying', [
                'attempt' => $attempt,
                'tries' => $tries,
                'gateway_status' => $status,
                'error' => $status === null ? $e->getMessage() : null,
            ]);

            return true;
        }

        // 4xx de negócio (402) não merece linha: a resposta é definitiva e
        // quem a trata é o controller. Falha de infra, sim — e o MOTIVO de não
        // insistir é a informação mais importante deste arquivo.
        if ($status === null || $status >= 500) {
            Log::warning('gateway.not_retrying', [
                'attempt' => $attempt,
                'tries' => $tries,
                'gateway_status' => $status,
                'reason' => match (true) {
                    $seguro => 'tentativas esgotadas',
                    $status === null => 'a requisicao saiu e nao voltou: pode ter cobrado',
                    default => 'o gateway respondeu 5xx: pode ter cobrado antes de explodir',
                },
            ]);
        }

        return false;
    }

    /** Exponencial com jitter: 100+j, 200+j, 400+j milissegundos. */
    private function backoffMs(int $attempt): int
    {
        $base = max(1, (int) config('services.gateway.retry_base_ms'));

        return (int) ($base * (2 ** ($attempt - 1))) + random_int(0, $base);
    }

    /**
     * Houve resposta, mas ela pode não ser utilizável pelo negócio.
     *
     * Sim, isto lança exceção a partir de um valor de retorno — e é de
     * propósito: deixar um 500 virar `$response` no controller é como o bug
     * de hoje acontece (`$response->json('payment_id')` de um corpo que não
     * tem `payment_id`).
     */
    private function ensureUsable(Response $response, Order $order, int $attempts): Response
    {
        $status = $response->status();

        if (in_array($status, self::RETRYABLE_STATUSES, true)) {
            // Retentou e o gateway seguiu dizendo "não processei".
            throw new GatewayUnavailable(
                orderId: $order->id,
                attempts: $attempts,
                gatewayStatus: $status,
            );
        }

        if ($response->serverError()) {
            // 500/502/504: respondeu, e o que ele fez antes de responder é
            // desconhecido. Não sabemos se cobrou.
            throw new PaymentUnconfirmed(
                orderId: $order->id,
                attempts: $attempts,
            );
        }

        return $response;
    }

    private function fromConnectionException(
        ConnectionException $e,
        Order $order,
        int $attempts,
    ): GatewayUnavailable|PaymentUnconfirmed {
        if ($this->neverArrived($e)) {
            return new GatewayUnavailable(
                orderId: $order->id,
                attempts: $attempts,
                previous: $e,
            );
        }

        return new PaymentUnconfirmed(
            orderId: $order->id,
            attempts: $attempts,
            timeoutSeconds: (int) config('services.gateway.timeout'),
            previous: $e,
        );
    }

    /**
     * Temos prova de que a cobrança não foi processada?
     *
     * Só quando o Guzzle disse que a conexão nem se estabeleceu. Qualquer
     * outra coisa — inclusive não ter `previous`, que é o caso de um
     * `Http::fake()` genérico — é tratada como INDETERMINADA. O default
     * pessimista é a escolha: errar para o lado de "não sei" custa uma
     * consulta; errar para o lado de "não cobrou" custa uma cobrança dupla.
     */
    private function neverArrived(ConnectionException $e): bool
    {
        return $e->getPrevious() instanceof GuzzleConnectException;
    }
}
