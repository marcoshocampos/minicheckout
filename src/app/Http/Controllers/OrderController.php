<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Requests\StoreOrderRequest;
use App\Jobs\SendOrderNotification;
use App\Models\Order;
use App\Services\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OrderController extends Controller
{
    public function __construct(private readonly PaymentGateway $gateway)
    {
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        $order = Order::create([
            'status' => OrderStatus::Pending,
            'amount_cents' => $data['amount_cents'],
            'currency' => strtoupper($data['currency']),
            'customer_email' => $data['customer_email'],
        ]);

        // FASE 1: uma linha, e `order_id` passa a sair em TODO log desta
        // requisição — e nos logs do job que ela dispara. Nenhum Log::info()
        // daqui para baixo repete o id.
        Context::add('order_id', $order->id);

        Log::info('order.created', [
            'amount_cents' => $order->amount_cents,
            'idempotency_key' => $idempotencyKey,
        ]);

        // -------------------------------------------------------------------
        // FASE 2: esta chamada tem DOIS caminhos de saída, não um.
        //
        // Com o retry ligado (ver PaymentGateway), ela devolve a resposta —
        // inclusive a malsucedida, por causa do `throw: false` — ou LANÇA
        // `Illuminate\Http\Client\ConnectionException`, que o `throw: false`
        // não suprime: não há resposta para devolver.
        //
        // Então falta um try/catch aqui, e a decisão de qual exceção de
        // domínio cada caso vira:
        //
        //     try {
        //         $response = $this->gateway->charge($order, $idempotencyKey);
        //     } catch (ConnectionException $e) {
        //         $order->update(['status' => OrderStatus::Failed]);
        //         // TODO: connect timeout / conexão recusada -> a cobrança
        //         //       NÃO aconteceu           -> GatewayUnavailable
        //         // TODO: timeout de leitura      -> pode ter acontecido
        //         //                               -> PaymentUnconfirmed
        //         throw ...;
        //     }
        //
        // Hoje ninguém captura: o pedido fica `pending` para sempre (nem
        // `failed`, porque a linha do update está depois) e o cliente recebe
        // um 500 com o stack trace inteiro.
        //
        // Veja acontecer: GATEWAY_TIMEOUT_RATE=1 no .env da raiz e
        // `docker compose up -d gateway`.
        // -------------------------------------------------------------------
        $response = $this->gateway->charge($order, $idempotencyKey);

        // --- recusa: erro ESPERADO -------------------------------------
        if ($response->status() === 402) {
            $order->update([
                'status' => OrderStatus::Declined,
                'payment_id' => $response->json('payment_id'),
                'decline_reason' => $response->json('decline_code'),
            ]);

            Log::info('order.declined', [
                'decline_code' => $response->json('decline_code'),
            ]);

            // FASE 2: 201 com status `declined` ou 402 para o cliente? A regra
            // é sua, mas tem que ser consciente: o PEDIDO foi criado, o
            // PAGAMENTO foi negado. E recusa não vira alerta de plantão.
            //
            // Se a escolha for 402, a linha abaixo vira
            //
            //     throw new PaymentDeclined(
            //         orderId: $order->id,
            //         declineCode: $response->json('decline_code'),
            //         paymentId: $response->json('payment_id'),
            //     );
            //
            // e `App\Exceptions\PaymentDeclined` já sabe se apresentar em
            // Problem Details. Note que lançar exceção DEPOIS do `update()`
            // funciona porque não há transação aberta aqui — se um dia
            // houver, a exceção desfaria a gravação do `declined`. Esse é o
            // tipo de detalhe que faz "usar exceção para fluxo esperado" ser
            // uma escolha, não um automatismo.
            return $this->present($order, 201);
        }

        // --- aprovado ---------------------------------------------------
        if ($response->successful()) {
            $order->update([
                'status' => OrderStatus::Paid,
                'payment_id' => $response->json('payment_id'),
                'paid_at' => now(),
            ]);

            Log::info('order.paid', [
                'payment_id' => $order->payment_id,
            ]);

            // FASE 1: nada é passado para o job, e ainda assim ele loga com o
            // mesmo request_id e order_id. O Context é serializado no payload
            // do job e re-hidratado no worker, pelo framework.
            // FASE 3: rodar este job duas vezes não pode notificar duas vezes.
            SendOrderNotification::dispatch($order);

            return $this->present($order, 201);
        }

        // --- 5xx: erro INESPERADO ---------------------------------------
        $order->update(['status' => OrderStatus::Failed]);

        Log::error('order.payment_failed', [
            'gateway_status' => $response->status(),
            'gateway_body' => $response->json(),
        ]);

        // FASE 2: isto vira
        //
        //     throw new GatewayUnavailable(
        //         orderId: $order->id,
        //         attempts: config('services.gateway.tries'),
        //         gatewayStatus: $response->status(),
        //     );
        //
        // ...mas só depois de o PaymentGateway ter retentado. E repare que o
        // `attempts` acima é um palpite: o cliente HTTP do Laravel não conta
        // as tentativas para quem chamou. Se esse número tem que ser exato,
        // quem conta é o `when` do retry — mais um motivo para logar lá.
        //
        // Hoje é um 500 cru do Laravel: `message`, `exception`, `file`,
        // `line` e o trace inteiro no corpo da resposta, por causa do
        // APP_DEBUG=true. É exatamente o que o cliente não deveria ver, e é o
        // que esta fase conserta.
        throw new RuntimeException("gateway respondeu {$response->status()}");
    }

    public function show(Order $order): JsonResponse
    {
        return $this->present($order);
    }

    private function present(Order $order, int $status = 200): JsonResponse
    {
        return response()->json([
            'id' => $order->id,
            'status' => $order->status,
            'amount_cents' => $order->amount_cents,
            'currency' => $order->currency,
            'customer_email' => $order->customer_email,
            'payment_id' => $order->payment_id,
            'decline_reason' => $order->decline_reason,
            'paid_at' => $order->paid_at,
            'notified_at' => $order->notified_at,
            'created_at' => $order->created_at,
        ], $status);
    }
}
