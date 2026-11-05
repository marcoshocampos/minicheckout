<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Requests\StoreOrderRequest;
use App\Jobs\SendOrderNotification;
use App\Models\Order;
use App\Services\PaymentGateway;
use Illuminate\Http\JsonResponse;
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

        // FASE 1: este array vira contexto automático.
        // Hoje você repete order_id em todo log na mão; com a facade Context,
        // request_id e order_id entram sozinhos em todos os logs da requisição
        // E nos logs do job que ela dispara.
        Log::info('order.created', [
            'order_id' => $order->id,
            'amount_cents' => $order->amount_cents,
            'idempotency_key' => $idempotencyKey,
        ]);

        // FASE 2: esta chamada pode lançar ConnectionException (timeout ou
        // conexão recusada). Hoje ninguém captura: o pedido fica `pending`
        // para sempre e o cliente recebe um 500 sem explicação.
        // Ligue GATEWAY_LATENCY_MS=35000 e veja esse caso acontecer.
        $response = $this->gateway->charge($order, $idempotencyKey);

        // --- recusa: erro ESPERADO -------------------------------------
        if ($response->status() === 402) {
            $order->update([
                'status' => OrderStatus::Declined,
                'payment_id' => $response->json('payment_id'),
                'decline_reason' => $response->json('decline_code'),
            ]);

            Log::info('order.declined', [
                'order_id' => $order->id,
                'decline_code' => $response->json('decline_code'),
            ]);

            // FASE 2: 201 com status `declined` ou 402 para o cliente? A regra
            // é sua, mas tem que ser consciente: o PEDIDO foi criado, o
            // PAGAMENTO foi negado. E recusa não vira alerta de plantão.
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
                'order_id' => $order->id,
                'payment_id' => $order->payment_id,
            ]);

            // FASE 1: o trace_id/request_id tem que atravessar esta fronteira.
            // FASE 3: rodar este job duas vezes não pode notificar duas vezes.
            SendOrderNotification::dispatch($order);

            return $this->present($order, 201);
        }

        // --- 5xx: erro INESPERADO ---------------------------------------
        $order->update(['status' => OrderStatus::Failed]);

        Log::error('order.payment_failed', [
            'order_id' => $order->id,
            'gateway_status' => $response->status(),
            'gateway_body' => $response->json(),
        ]);

        // FASE 2: isto vira uma exceção de domínio (ex. GatewayUnavailable),
        // renderizada como Problem Details e com retry ANTES de desistir.
        // Hoje é um 500 cru do Laravel, que é exatamente o que o cliente não
        // deveria ver.
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
