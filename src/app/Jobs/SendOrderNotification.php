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
use RuntimeException;
use Throwable;

/**
 * "Envia a notificação" do pedido pago. Não envia nada de verdade: só grava
 * `notified_at` e loga. O que importa é ele ser um processo SEPARADO.
 *
 * FASE 0: nenhuma proteção. Rode a mão duas vezes e ele notifica duas vezes.
 *
 * ===========================================================================
 * FASE 2 — TRATAMENTO DE ERRO NA FILA
 *
 * Erro em job é diferente de erro em requisição HTTP, e a diferença é que não
 * há ninguém esperando a resposta. Numa requisição, falhar rápido é uma
 * virtude: o cliente recebe o 503 e decide o que fazer. Num job, ninguém
 * decide nada — se ele falhar e desistir, o efeito simplesmente não acontece,
 * em silêncio. O pedido fica pago e o cliente nunca é avisado.
 *
 * Por isso job tem retry por padrão e requisição não.
 *
 *     public $tries = 5;
 *
 *     public function backoff(): array
 *     {
 *         return [1, 5, 15, 60];   // segundos entre as tentativas
 *     }
 *
 *     public function failed(?Throwable $e): void
 *     {
 *         // a última palavra: o job morreu. Ver item 4 abaixo.
 *     }
 *
 * ---------------------------------------------------------------------------
 * 1. QUEM GANHA: `$tries` do job ou `--tries` do worker?
 *
 *    O job. O worker lê `$job->maxTries()` e só usa o `--tries` da linha de
 *    comando quando o job não declara nada
 *    (Illuminate\Queue\Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts).
 *
 *    O docker-compose sobe o worker com `--tries=1` de propósito: o default
 *    explícito é "não insista", e quem quiser insistir declara. Não precisa
 *    mudar o compose para esta fase funcionar.
 *
 *    ARMADILHA: `$tries` e `backoff()` são serializados no PAYLOAD, no momento
 *    do DISPATCH. Job que já está na fila carrega os valores ANTIGOS. Mudou o
 *    número e reiniciou o worker, e nada mudou? Os jobs em voo são os de
 *    antes. Esvazie a fila ou despache de novo.
 *
 * 2. BACKOFF: por que não tentar de novo imediatamente
 *
 *    Se a causa da falha dura mais que microssegundos — e quase toda causa
 *    real dura mais —, as 5 tentativas em sequência são 5 falhas no mesmo
 *    estado, gastas em um segundo. Você não retentou: você só desistiu cinco
 *    vezes.
 *
 *    `backoff()` pode devolver int (sempre o mesmo intervalo) ou array (um
 *    por tentativa). Detalhe que não está óbvio na documentação: o array é
 *    serializado como "1,5,15,60" e o worker usa
 *    `$backoff[$attempts - 1] ?? last($backoff)` — ou seja, **o último valor
 *    se repete** para as tentativas seguintes. Com `[1, 5]` e `$tries = 5`,
 *    os intervalos são 1, 5, 5, 5.
 *
 *    Jitter aqui é menos crítico que no retry do gateway (a fila já espalha
 *    naturalmente), mas se mil jobs falharem no mesmo segundo por causa do
 *    mesmo banco fora, eles voltam no mesmo segundo.
 *
 * 3. QUANTAS TENTATIVAS, OU ATÉ QUANDO
 *
 *    `$tries = 5` conta tentativas; `retryUntil()` dá um PRAZO:
 *
 *        public function retryUntil(): \DateTime
 *        {
 *            return now()->addMinutes(10);
 *        }
 *
 *    A diferença importa quando a notificação tem valor perecível. Avisar o
 *    cliente 4 horas depois pode ser pior que não avisar. Prazo é sobre o
 *    valor do efeito; contagem é sobre o custo da tentativa. (Se os dois
 *    existirem, o prazo ganha.)
 *
 * 4. `failed()`: A DEAD LETTER QUEUE
 *
 *    `failed()` roda UMA vez, depois da última tentativa (ou na hora, se a
 *    exceção estiver no `dontRetry` de bootstrap/app.php). O framework grava
 *    a linha em `failed_jobs` — payload completo, exceção e horário.
 *
 *    Duas coisas valem saber:
 *
 *    a) O Context da Fase 1 JÁ está hidratado aqui. O worker dispara
 *       `JobProcessing` (que re-hidrata o Context) ANTES de checar as
 *       tentativas, então a linha que você logar no `failed()` sai com o
 *       `request_id` da requisição que criou o pedido, horas antes, em outro
 *       processo. É daí que sai o fecho da Fase 1 com a Fase 2: da linha da
 *       dead letter você volta para a requisição inteira.
 *
 *    b) `failed()` é a sua última chance de fazer algo com o efeito perdido:
 *       marcar o pedido, avisar um humano, enfileirar compensação. Faça o
 *       MÍNIMO: se o `failed()` também falhar, não há terceira rede.
 *
 *    E a pergunta que o roteiro faz: `failed_jobs` é uma DLQ, mas **quem olha
 *    essa tabela, e quando?** Job que falha 5 vezes e ninguém olha é
 *    exatamente igual a job perdido — só com mais disco usado. As ferramentas:
 *
 *        docker compose exec app php artisan queue:failed
 *        docker compose exec app php artisan queue:retry <uuid>|all
 *        ./scripts/dead-letter.sh          <- e daqui para o follow-request.sh
 *
 *    Na Fase 5 isto vira métrica e alerta: "tamanho da DLQ > 0 por 5 minutos".
 *
 * 5. E O QUE RETENTAR JÁ TRAZ DE VOLTA
 *
 *    Retentar um job que falhou no MEIO é rodar de novo a parte que já deu
 *    certo. Aqui o trabalho é só um UPDATE, mas num job que manda e-mail de
 *    verdade a tentativa 2 manda o segundo e-mail. É o mesmo problema do
 *    retry do gateway, agora dentro de casa — e é a Fase 3.
 * ===========================================================================
 *
 * ---------------------------------------------------------------------------
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

    /**
     * FASE 2 — quatro tentativas.
     *
     * Esta linha é o que faz o worker IGNORAR o `--tries=1` do
     * docker-compose: `Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts` lê
     * `$job->maxTries()` primeiro e só cai no `--tries` da linha de comando
     * quando o job não diz nada.
     *
     * ARMADILHA: `$tries` e `backoff()` são serializados no PAYLOAD, no
     * momento do DISPATCH. Job que já está na fila carrega os valores
     * ANTIGOS. Mudou o número, reiniciou o worker e nada mudou? Os jobs em
     * voo são os de antes — esvazie a fila ou despache de novo.
     */
    public int $tries = 4;

    public function __construct(public Order $order)
    {
    }

    /**
     * FASE 2 — os intervalos entre as tentativas, em segundos: 2, 8, 8.
     *
     * São dois valores para quatro tentativas (três intervalos) porque o
     * ÚLTIMO VALOR SE REPETE. O detalhe não está óbvio na documentação: o
     * array é serializado como "2,8" e o worker faz
     * `$backoff[$attempts - 1] ?? last($backoff)`
     * (`Illuminate\Queue\Worker::calculateBackoff`). Com `[2, 8]` e
     * `$tries = 4`, os intervalos são 2, 8 e 8.
     *
     * Por que não zero: se a causa da falha dura mais que microssegundos — e
     * quase toda causa real dura mais —, as quatro tentativas em sequência
     * são quatro falhas no mesmo estado, gastas em um segundo. Isso não é
     * retentar: é desistir quatro vezes.
     *
     * Jitter aqui importa menos que no retry do gateway (a fila já espalha),
     * mas mil jobs que falharam pelo mesmo banco fora voltam no mesmo
     * segundo. `return [2 + random_int(0, 2), 8 + random_int(0, 4)];`
     * resolveria — ao custo de o teste observável ficar menos previsível.
     *
     * A alternativa é `retryUntil()`, que dá um PRAZO em vez de uma
     * contagem:
     *
     *     public function retryUntil(): \DateTime { return now()->addMinutes(10); }
     *
     * Prazo é sobre o VALOR do efeito (avisar o cliente 4 horas depois pode
     * ser pior que não avisar); contagem é sobre o CUSTO da tentativa. Se os
     * dois existirem, o prazo ganha.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [2, 8];
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

        // --- a alavanca de caos do nosso lado (config/chaos.php) -----------
        //
        // NOTIFICATION_FAIL_RATE=1 em src/.env + `docker compose restart
        // worker`. Falha DEPOIS do trabalho de I/O e ANTES de gravar
        // `notified_at`: é o caso que dói, o do efeito que começou e não
        // terminou.
        //
        // RuntimeException de propósito, e não uma exceção de domínio: isto é
        // o erro INESPERADO. Ele deve ser reportado, retentado e, no fim,
        // virar linha na dead letter queue.
        $failRate = (float) config('chaos.notification.fail_rate');

        if ($failRate > 0 && mt_rand() / mt_getrandmax() < $failRate) {
            Log::warning('notification.failing_on_purpose', [
                'attempt' => $this->attempts(),
                'fail_rate' => $failRate,
            ]);

            throw new RuntimeException('falhei de proposito (NOTIFICATION_FAIL_RATE)');
        }

        $this->order->update(['notified_at' => now()]);

        Log::info('notification.sent', [
            'notified_at' => $this->order->notified_at,
        ]);
    }

    /**
     * FASE 2 — a última palavra do job.
     *
     * Roda UMA vez, depois da última tentativa (ou na hora, se a exceção
     * estiver no `dontRetry` de bootstrap/app.php). O framework grava a linha
     * em `failed_jobs` com payload completo, exceção e horário; o que ele NÃO
     * faz é dizer no log do worker, em linguagem de negócio, que este pedido
     * não foi notificado e não vai ser. Sem este método, a morte do job é
     * silenciosa para quem lê o log.
     *
     * Duas coisas que valem saber:
     *
     * a) O Context da Fase 1 JÁ está hidratado aqui. O worker dispara
     *    `JobProcessing` (que re-hidrata o Context) ANTES de checar as
     *    tentativas, então esta linha sai com o `request_id` da requisição
     *    HTTP que criou o pedido — horas antes, em outro processo. É o fecho
     *    da Fase 1 com a Fase 2: `./scripts/dead-letter.sh` tira esse mesmo
     *    id de dentro do payload e te devolve o `follow-request.sh` pronto.
     *
     * b) Faça o MÍNIMO. Se o `failed()` também falhar, não há terceira rede —
     *    e o que estava para ser gravado na dead letter se perde. Por isso
     *    aqui só tem log: nada de novo UPDATE, nada de HTTP, nada de
     *    re-despachar (re-despachar daqui é um loop infinito com passos
     *    extras).
     *
     * E a pergunta que o roteiro faz, e que nenhum código responde: QUEM olha
     * essa tabela, e QUANDO? Job que falha 4 vezes e ninguém olha é
     * exatamente igual a job perdido, só com mais disco usado. Na Fase 5 isto
     * vira métrica e alerta ("tamanho da DLQ > 0 por 5 minutos").
     */
    public function failed(?Throwable $e): void
    {
        Log::error('notification.dead_letter', [
            'attempts' => $this->attempts(),
            // A CLASSE antes da mensagem: é por ela que se agrupa no gráfico
            // e é ela que diz se foi erro nosso ou do mundo.
            'exception' => $e === null ? null : $e::class,
            'reason' => $e?->getMessage(),
            // O efeito que não aconteceu, dito em linguagem de negócio. É a
            // linha que um humano procura, e não "Job failed".
            'effect_lost' => 'o cliente nao foi notificado do pedido pago',
        ]);
    }
}
