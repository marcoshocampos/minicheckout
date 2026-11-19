<?php

namespace App\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * FASE 1 — uma linha JSON por evento, no MESMO formato dos outros processos.
 *
 * O projeto tem quatro coisas escrevendo log: o nginx (log de acesso), o app
 * (php-fpm), o worker (fila) e o gateway fake. Os dois últimos já escrevem
 * `{"ts":...,"source":...,"event":...,"request_id":...}`. Este formatter põe o
 * Laravel na mesma forma, e isso é o que permite UMA consulta valer para os
 * quatro — em vez de `request_id` num lugar e `extra_request_id` no outro.
 *
 * ---------------------------------------------------------------------------
 * A FORMA DA LINHA
 *
 *     {"ts":"2026-11-19T03:13:10.506155+00:00",
 *      "source":"worker",
 *      "level":"info",
 *      "event":"notification.sent",
 *      "request_id":"01KA7Z…",
 *      "order_id":"01a1f1cb-…",
 *      "host":"3f9c0a1b2c3d",
 *      "context":{"notified_at":"2026-11-19T03:13:16+00:00"}}
 *
 * Três decisões aí dentro:
 *
 * 1. **O Context sobe para a raiz.** `request_id`, `order_id` (e o `trace_id`
 *    da Fase 4) chegam dentro de `extra`, porque é lá que o
 *    `ContextLogProcessor` os põe. São chaves NOSSAS, de nome estável, então
 *    viram campos de primeira classe.
 *
 * 2. **O payload da chamada continua aninhado em `context`.** São as chaves
 *    que cada `Log::info('evento', [...])` escolhe: achatar isso faria duas
 *    linhas com o mesmo `event` terem formatos diferentes, e um `status` de
 *    gateway poderia sobrescrever um campo da linha.
 *
 * 3. **`level` é a palavra, não o número.** O Monolog manda 200/400; o
 *    Grafana reconhece `level` quando o valor é `info`/`error`/`warning` e
 *    colore o log por isso. O número não serve para nada aqui.
 *
 * Campos que saíram: `level_name` (virou `level`), `channel` (era sempre o
 * nome do ambiente, e `source` responde melhor "quem escreveu isto"), e
 * `message` (virou `event`, porque o valor é um nome de evento — `order.paid`
 * — e não uma frase).
 *
 * ---------------------------------------------------------------------------
 * POR QUE ESTENDER O JsonFormatter E NÃO ESCREVER DO ZERO
 *
 * `normalizeRecord()` (herdado) é que resolve a parte chata e perigosa:
 * exceções em `context['exception']` viram array com class/message/file/line,
 * objetos e datas viram escalares, profundidade e tamanho têm limite, e
 * `toJson()` não explode com UTF-8 inválido. Reescrever isso seria recriar
 * bug conhecido — e a Fase 2 vai mandar exceção para o log de propósito.
 */
class LogLineFormatter extends JsonFormatter
{
    /**
     * Os campos fixos da linha. Um valor do Context com um destes nomes é
     * ignorado: o formato da linha não pode depender de quem loga.
     */
    private const RESERVED = ['ts', 'source', 'level', 'event', 'context'];

    public function __construct()
    {
        parent::__construct(
            batchMode: self::BATCH_MODE_NEWLINES,
            appendNewline: true,
            ignoreEmptyContextAndExtra: true,
        );

        // Microssegundos. Duas linhas da mesma requisição caem no mesmo
        // segundo com frequência, e aí a ordem no log viraria sorteio.
        $this->dateFormat = 'Y-m-d\TH:i:s.uP';
    }

    public function format(LogRecord $record): string
    {
        $normalized = $this->normalizeRecord($record);

        /** @var array<string, mixed> $extra */
        $extra = $normalized['extra'] ?? [];
        /** @var array<string, mixed> $context */
        $context = $normalized['context'] ?? [];

        $line = [
            'ts' => $normalized['datetime'],
            // Vem do processor em App\Logging\FormatLogsAsJson. O default só
            // existe para o caso de alguém usar este formatter sem o tap.
            'source' => $extra['source'] ?? 'app',
            'level' => strtolower((string) $normalized['level_name']),
            'event' => $normalized['message'],
        ];

        foreach ($extra as $key => $value) {
            if (! in_array($key, self::RESERVED, true)) {
                $line[$key] = $value;
            }
        }

        if ($context !== []) {
            $line['context'] = $context;
        }

        return $this->toJson($line, true).($this->appendNewline ? "\n" : '');
    }
}
