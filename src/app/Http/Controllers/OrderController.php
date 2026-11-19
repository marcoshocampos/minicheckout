<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Exceptions\GatewayUnavailable;
use App\Exceptions\PaymentDeclined;
use App\Exceptions\PaymentUnconfirmed;
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
        // FASE 2 — o `charge()` já retentou e já classificou (ver
        // PaymentGateway). O que sobra para o controller é a única coisa que
        // o serviço HTTP não pode saber: o que o ESTADO DO PEDIDO vira em
        // cada caso.
        //
        // E a distinção que quase todo sistema perde está exatamente aqui:
        //
        //   failed   temos prova de que não cobrou   -> repetir é seguro
        //   unknown  pode ter cobrado                -> NÃO repita; consulte
        //
        // Guardar os dois como `failed` é o atalho que transforma um
        // incidente de disponibilidade em um incidente financeiro: amanhã
        // alguém escreve um script que "reprocessa os failed".
        //
        // Nota de concorrência: estes `update()` só são seguros porque não há
        // transação aberta aqui. Dentro de uma transação, a exceção lançada
        // em seguida desfaria a gravação do status, e o pedido ficaria
        // `pending` — exatamente o bug que esta fase remove.
        // -------------------------------------------------------------------
        try {
            $response = $this->gateway->charge($order, $idempotencyKey);
        } catch (GatewayUnavailable $e) {
            $order->update(['status' => OrderStatus::Failed]);

            // warning, e não error: o cliente recebeu um 503 limpo, nada foi
            // cobrado e repetir resolve. A gravidade do log acompanha a do
            // incidente — ver `level()` em bootstrap/app.php.
            Log::warning('order.payment_failed', [
                'attempts' => $e->attempts,
                'gateway_status' => $e->gatewayStatus,
            ]);

            throw $e;
        } catch (PaymentUnconfirmed $e) {
            $order->update(['status' => OrderStatus::Unknown]);

            // critical: pode haver dinheiro cobrado sem pedido pago. É o tipo
            // de linha que merece alerta na Fase 5, e é o que a reconciliação
            // da Fase 3 vai consumir.
            Log::critical('order.payment_unconfirmed', [
                'attempts' => $e->attempts,
            ]);

            throw $e;
        }

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

            // FASE 2 — DECISÃO TOMADA: 402, e não 201 com `status: declined`.
            //
            // O 201 se defende (o PEDIDO foi criado; o pagamento é um estado
            // do recurso), mas ele premia o cliente preguiçoso com a resposta
            // errada: quem olha só o código HTTP conclui que deu certo. O 402
            // acerta de graça para quem trata 4xx como erro, e o pedido
            // continua consultável em `GET /orders/{id}`.
            //
            // O preço: é mudança de CONTRATO. Spec no mesmo commit.
            //
            // E repare que isto é erro esperado usando o mecanismo de
            // exceção. Funciona, mas tem custo: `dontReport` em
            // bootstrap/app.php é obrigatório, senão 5% de recusa num dia de
            // promoção dispara o alerta de taxa de erro da Fase 5.
            throw new PaymentDeclined(
                orderId: $order->id,
                declineCode: $response->json('decline_code'),
                paymentId: $response->json('payment_id'),
            );
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

        // --- o que não deveria existir: BUG NOSSO -----------------------
        //
        // O PaymentGateway já converteu 5xx e 408/429/503 em exceção de
        // domínio, e o 402 foi tratado acima. Chegar aqui é o gateway dizendo
        // 400/404/405: payload errado, rota errada, contrato quebrado. Não é
        // falha de infraestrutura e não é recusa — é código nosso errado.
        //
        // Por isso é RuntimeException crua, de propósito: ela DEVE ser
        // reportada com stack trace (é o que a gente precisa para corrigir) e
        // o cliente DEVE receber um 500 genérico com o `request_id`, sem
        // detalhe interno. Quem faz essa tradução é o `respond()` de
        // bootstrap/app.php — é a terceira categoria da fase, a que não tem
        // classe de domínio porque não tem nada a dizer ao cliente.
        $order->update(['status' => OrderStatus::Failed]);

        Log::error('order.gateway_rejected_request', [
            'gateway_status' => $response->status(),
            'gateway_body' => $response->json(),
        ]);

        throw new RuntimeException("gateway respondeu {$response->status()}: requisicao invalida");
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
