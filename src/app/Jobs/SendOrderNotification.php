<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * "Envia a notificação" do pedido pago. Não envia nada de verdade: só grava
 * `notified_at` e loga. O que importa é ele ser um processo SEPARADO.
 *
 * FASE 0: nenhuma proteção. Rode a mão duas vezes e ele notifica duas vezes.
 *
 * ---------------------------------------------------------------------------
 * FASE 2 — tratamento de erro no job:
 *   public $tries = 5;
 *   public function backoff(): array { return [1, 5, 15, 60]; }  // exponencial
 *   public function failed(Throwable $e): void { ... }           // dead letter
 *   `failed_jobs` é a sua DLQ: quem olha essa tabela? quando?
 *   Job que falha 5 vezes e ninguém olha é o mesmo que job perdido.
 *
 * FASE 3 — job idempotente. Duas opções, com custos diferentes:
 *
 *   a) implements ShouldBeUnique
 *      public $uniqueFor = 3600;
 *      public function uniqueId(): string { return $this->order->id; }
 *      Barato, mas é um LOCK NO REDIS: se o Redis cair, a garantia cai com
 *      ele. Protege contra despacho duplicado, não contra execução duplicada.
 *
 *   b) estado + constraint no banco:
 *      $affected = Order::whereKey($id)->whereNull('notified_at')
 *          ->update(['notified_at' => now()]);
 *      if ($affected === 0) { return; }   // outro já notificou
 *      O UPDATE condicional é atômico: duas execuções simultâneas, só uma
 *      recebe affected = 1. Aqui a garantia é do banco.
 *
 *   O ideal é (b), e (a) como economia. A pergunta que fecha o assunto:
 *   se o job marca notified_at e DEPOIS o envio do e-mail falha, o que você
 *   prefere — e-mail duplicado ou e-mail nunca enviado?
 * ---------------------------------------------------------------------------
 */
class SendOrderNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public Order $order)
    {
    }

    public function handle(): void
    {
        // FASE 1: este log tem que sair com o MESMO request_id da requisição
        // que criou o pedido, mesmo rodando em outro processo. É o teste que
        // fecha a fase: um request_id, todos os logs — HTTP e job.
        Log::info('notification.sending', [
            'order_id' => $this->order->id,
            'email' => $this->order->customer_email,
            'attempt' => $this->attempts(),
        ]);

        // Aqui entraria o envio real. Fingimos trabalho de I/O.
        usleep(200 * 1000);

        $this->order->update(['notified_at' => now()]);

        Log::info('notification.sent', [
            'order_id' => $this->order->id,
            'notified_at' => $this->order->notified_at,
        ]);
    }
}
