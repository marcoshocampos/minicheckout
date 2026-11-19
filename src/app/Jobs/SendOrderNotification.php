<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
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
        // O Context chega hidratado da requisição que despachou o job, mas um
        // job também pode ser despachado por um comando artisan ou re-tentado
        // sem requisição nenhuma. Reafirmar o order_id aqui é barato e faz a
        // linha do worker nunca sair sem ele.
        Context::add('order_id', $this->order->id);

        // FASE 1: esta linha sai com o MESMO request_id da requisição que
        // criou o pedido, em OUTRO processo, sem ninguém ter passado nada.
        //
        // O e-mail do cliente saiu daqui de propósito: log é dado que deixa o
        // processo e vai para um índice de busca, e PII em índice de busca é
        // problema que você não quer descobrir depois. O `order_id` já
        // identifica o pedido e o e-mail está no banco. Se algum dia um dado
        // precisar atravessar para o job SEM aparecer no log, o mecanismo é
        // `Context::addHidden()`.
        Log::info('notification.sending', [
            'attempt' => $this->attempts(),
        ]);

        // Aqui entraria o envio real. Fingimos trabalho de I/O.
        usleep(200 * 1000);

        $this->order->update(['notified_at' => now()]);

        Log::info('notification.sent', [
            'notified_at' => $this->order->notified_at,
        ]);
    }
}
