<?php

namespace App\Logging;

use Illuminate\Log\Logger;

/**
 * FASE 1 — ESQUELETO, e OPCIONAL. Faça o passo 1 antes (a env
 * LOG_STDERR_FORMATTER) e olhe a saída: se ela já te servir, não precisa disto.
 *
 * Um "tap" recebe o Logger depois de o canal ser criado, e deixa você mexer no
 * Monolog por baixo: trocar o formatter, empilhar processors. É o jeito nativo
 * do Laravel de customizar um canal sem reescrever o driver.
 *
 * Ligue descomentando o `tap` no canal `stderr` em config/logging.php.
 *
 * ---------------------------------------------------------------------------
 * POR QUE VOCÊ PODE QUERER ISTO
 *
 * Com o JsonFormatter puro, a saída fica assim:
 *
 *     {"message":"order.created","context":{"amount_cents":1990},
 *      "level":200,"level_name":"INFO","channel":"local",
 *      "datetime":"2026-11-18T03:27:52.123456+00:00",
 *      "extra":{"request_id":"01JC..."}}
 *
 * Note duas coisas:
 *   - `request_id` está dentro de `extra` (é onde o ContextLogProcessor põe)
 *   - o que você passou no `Log::info('...', [...])` está dentro de `context`
 *
 * Isso FUNCIONA — Loki indexa aninhado. Mas se você quiser consultar por
 * `request_id` sem prefixo, ou achatar `context`/`extra` na raiz, é aqui.
 *
 * ---------------------------------------------------------------------------
 * O QUE IMPLEMENTAR
 *
 * 1. Trocar o formatter em cada handler do canal:
 *
 *        foreach ($logger->getHandlers() as $handler) {
 *            $handler->setFormatter(new JsonFormatter(
 *                batchMode: JsonFormatter::BATCH_MODE_NEWLINES,
 *                appendNewline: true,
 *                ignoreEmptyContextAndExtra: true,   // menos ruído
 *            ));
 *        }
 *
 *    Ou escrever um formatter próprio: estenda
 *    `Monolog\Formatter\NormalizerFormatter` e implemente `format(LogRecord)`
 *    devolvendo a string JSON na forma que você quiser.
 *
 * 2. Empilhar um processor com o que é fixo do processo. Isto responde uma
 *    pergunta que você VAI ter na primeira investigação séria: "quem escreveu
 *    esta linha, o web ou o worker?"
 *
 *        $logger->pushProcessor(function (LogRecord $record) {
 *            return $record->with(extra: [
 *                ...$record->extra,
 *                'service' => ...,   // 'app' ou 'worker'
 *                'host'    => gethostname(),
 *            ]);
 *        });
 *
 *    De onde vem o 'service'? O container `app` roda php-fpm e o `worker`
 *    roda `queue:work` — a MESMA imagem, o mesmo código. Decida como
 *    diferenciar (`php_sapi_name()`? `App::runningInConsole()`? uma env no
 *    compose?) e por quê.
 *
 * 3. FASE 4 vai somar `trace_id` e `span_id` aqui. É o que permite pular do
 *    log para o trace no Grafana. Deixe espaço para isso.
 *
 * ---------------------------------------------------------------------------
 * CUIDADO
 *
 * Log é dado que sai do processo e fica armazenado. Não coloque aqui o que
 * você não quer num índice de busca: e-mail de cliente, token, cartão. Para
 * dado que precisa atravessar para o job mas NÃO deve ir ao log, existe
 * `Context::addHidden()`.
 */
class FormatLogsAsJson
{
    public function __invoke(Logger $logger): void
    {
        // TODO: 1 e 2 acima.
    }
}
