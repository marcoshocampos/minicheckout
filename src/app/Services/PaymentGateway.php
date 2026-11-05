<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente do gateway fake.
 *
 * FASE 0: deliberadamente ingênuo. Sem timeout, sem retry, sem tratamento.
 * Se o gateway pendurar, esta chamada pendura com ele e o seu cliente também.
 * Ligue `GATEWAY_LATENCY_MS=5000` e veja a requisição inteira travar.
 *
 * ---------------------------------------------------------------------------
 * FASE 2 — o que entra aqui:
 *
 *   Http::timeout(3)          // tempo total da resposta
 *       ->connectTimeout(1)   // tempo só para abrir a conexão
 *       ->retry(
 *           times: 3,
 *           sleepMilliseconds: 200,
 *           when: $retryableOnly,   // só timeout, erro de conexão e 503
 *           throw: false,
 *       )
 *
 * Regras que você vai ter que escolher:
 *   - retentar 500? (é ambíguo: o gateway pode ter cobrado antes de explodir)
 *   - NUNCA retentar 400 e 402: a resposta não vai mudar
 *   - backoff exponencial com jitter, senão 3 retries de 1000 clientes viram
 *     uma avalanche sincronizada no gateway que já está mal
 *   - sem timeout no cliente, o timeout de quem chama você é que estoura
 *
 * E a pergunta central do projeto: toda vez que você retenta um POST que pode
 * ter sido processado, você precisa de idempotência do OUTRO lado. O
 * `idempotency_key` já vai no corpo da chamada abaixo; o gateway fake ignora
 * de propósito, porque o objetivo é você sentir o problema.
 * ---------------------------------------------------------------------------
 */
class PaymentGateway
{
    public function charge(Order $order, string $idempotencyKey): Response
    {
        $url = rtrim((string) config('services.gateway.url'), '/') . '/charge';

        return Http::acceptJson()
            ->withHeaders([
                // FASE 1: propagar o request_id para o gateway é o que permite
                // achar os dois lados da chamada com o mesmo id.
                // FASE 4: aqui também entra o header `traceparent` (W3C).
                'X-Request-Id' => (string) request()->header('X-Request-Id', '-'),
            ])
            ->post($url, [
                'order_id' => $order->id,
                'amount_cents' => $order->amount_cents,
                'currency' => $order->currency,
                'idempotency_key' => $idempotencyKey,
            ]);
    }
}
