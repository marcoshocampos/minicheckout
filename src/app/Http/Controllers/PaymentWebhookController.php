<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * Webhook de confirmação do gateway.
 *
 * FASE 0: processa tudo que chega, sempre. Mande o mesmo evento 3 vezes
 * (`X-Chaos-Duplicate-Webhook: 2`) e veja ele ser processado 3 vezes.
 *
 * ---------------------------------------------------------------------------
 * FASE 3 — o que entra aqui:
 *
 * 1. INSERT em `webhook_events` com UNIQUE(event_id) ANTES de processar.
 *    Se o insert violar o UNIQUE, é entrega repetida: responda 200 e pare.
 *    Quem decide é o banco. Um `if (WebhookEvent::where(...)->exists())` não
 *    resolve: duas entregas simultâneas passam as duas pelo `exists()`.
 *
 * 2. Fora de ordem. O webhook pode chegar:
 *      - ANTES da resposta HTTP do /charge ter sido salva (o pedido ainda
 *        está `pending` e você não tem payment_id)
 *      - DEPOIS de um evento mais novo (`payment.failed` chegando depois de
 *        `payment.succeeded`)
 *    Transição de estado precisa ser monotônica: um pedido `paid` não volta
 *    para `pending`. Compare `occurred_at`, não a hora da chegada.
 *
 * 3. O que responder quando o pedido não existe ainda? 200 e perder o evento,
 *    ou 404/500 para o gateway reentregar? (Dica: reentrega é a favor de quem
 *    processa, desde que o processamento seja idempotente.)
 *
 * 4. Autenticidade: webhook real vem assinado (HMAC). Aqui o gateway é nosso,
 *    mas vale lembrar que endpoint público sem assinatura é porta aberta.
 * ---------------------------------------------------------------------------
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'string', 'max:64'],
            'type' => ['required', 'string', 'max:64'],
            'order_id' => ['required', 'uuid'],
            'payment_id' => ['required', 'string', 'max:64'],
            'status' => ['required', 'string', 'max:20'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        // FASE 1: o que identifica este evento vale para TODAS as linhas daqui
        // para frente, inclusive as que o handler de exceção escrever.
        //
        // O `request_id` desta requisição é o da requisição que criou o
        // pedido: o gateway devolve o id que recebeu, e tanto o nginx quanto o
        // middleware respeitam o header que chega. Ver a nota sobre id de
        // fluxo em App\Http\Middleware\RequestId.
        Context::add('order_id', $data['order_id']);
        Context::add('event_id', $data['event_id']);

        Log::info('webhook.received', [
            'type' => $data['type'],
            // O gateway manda 1/3, 2/3, 3/3 quando duplica: é como você vê a
            // entrega repetida no log sem adivinhar.
            'delivery' => $request->header('X-Gateway-Delivery'),
        ]);

        $order = Order::find($data['order_id']);

        if ($order === null) {
            Log::warning('webhook.order_not_found');

            return response()->json(['status' => 'ignored'], 202);
        }

        // FASE 3: aqui mora a idempotência. Hoje é um UPDATE cego, que roda
        // uma vez por entrega.
        if ($data['status'] === 'approved') {
            $order->update([
                'status' => OrderStatus::Paid,
                'payment_id' => $data['payment_id'],
                'paid_at' => $order->paid_at ?? now(),
            ]);
        } else {
            $order->update([
                'status' => OrderStatus::Declined,
                'payment_id' => $data['payment_id'],
            ]);
        }

        Log::info('webhook.processed', [
            'new_status' => $order->status->value,
        ]);

        return response()->json(['status' => 'ok']);
    }
}
