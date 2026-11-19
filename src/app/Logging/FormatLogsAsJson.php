<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\LogRecord;

/**
 * FASE 1, passo 2 — o "tap" do canal `stderr`.
 *
 * Um tap recebe o Logger depois de o canal ser criado e deixa mexer no Monolog
 * por baixo: trocar o formatter, empilhar processors. É o jeito nativo do
 * Laravel de customizar um canal sem escrever um driver.
 *
 * Ligado em `config/logging.php`, no canal `stderr`. Comente a linha do `tap`
 * ali e o log volta para o `JsonFormatter` puro da env `LOG_STDERR_FORMATTER`
 * (o passo 1), que também é JSON, só com outra forma.
 *
 * ---------------------------------------------------------------------------
 * O QUE ELE FAZ
 *
 * 1. Empilha um processor com o que é fixo do PROCESSO: `source` e `host`.
 *    Isso responde a primeira pergunta de qualquer investigação séria: "quem
 *    escreveu esta linha, o web ou o worker?". Os dois containers rodam a
 *    MESMA imagem e o MESMO código, então o papel do processo não está no
 *    código — vem de quem o subiu (`LOG_SOURCE` no docker-compose, exposto
 *    como `logging.source`).
 *
 * 2. Troca o formatter de cada handler por `App\Logging\LogLineFormatter`,
 *    que é quem define a forma da linha.
 *
 * ---------------------------------------------------------------------------
 * DUAS COISAS QUE NÃO SÃO ÓBVIAS
 *
 * **Ordem dos processors.** O `LogManager` empurra o `ContextLogProcessor`
 * DEPOIS de rodar este tap (LogManager.php:150), e `pushProcessor` do Monolog
 * é uma PILHA: o último empurrado roda primeiro. Então, quando o processor
 * daqui executa, o Context já foi aplicado e `$record->extra` já contém
 * `request_id`. É isso que torna possível o formatter achatar `extra` na raiz.
 *
 * **`source` e `host` são resolvidos uma vez**, na criação do canal, e não a
 * cada linha: são constantes do processo. `gethostname()` dentro do container
 * devolve o id do container, que é como você distingue duas réplicas do worker
 * quando houver mais de uma.
 *
 * ---------------------------------------------------------------------------
 * CUIDADO
 *
 * Log é dado que sai do processo e fica armazenado. Não ponha aqui o que você
 * não quer num índice de busca: e-mail de cliente, token, cartão. Para dado
 * que precisa atravessar para o job mas NÃO deve ir ao log existe
 * `Context::addHidden()`.
 *
 * FASE 4 vai somar `trace_id` e `span_id`. Eles não entram aqui: entram no
 * Context (ou num processor do pacote do OpenTelemetry) e o formatter já os
 * leva para a raiz da linha sem mudança nenhuma.
 */
class FormatLogsAsJson
{
    public function __invoke(Logger $logger): void
    {
        $source = (string) config('logging.source', 'app');
        $host = gethostname() ?: 'unknown';

        $logger->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(extra: [
            ...$record->extra,
            'source' => $source,
            'host' => $host,
        ]));

        foreach ($logger->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(new LogLineFormatter());
            }
        }
    }
}
