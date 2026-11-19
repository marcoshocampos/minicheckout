#!/usr/bin/env bash
#
# A dead letter queue, lida de um jeito que serve para investigar.
#
#   ./scripts/dead-letter.sh [quantos]        (default 10)
#
# `php artisan queue:failed` mostra uuid, fila, classe e horario. O que falta
# ali e justamente o que voce precisa: QUAL pedido morreu, QUAL request_id
# levou ate ele, e o que a excecao disse.
#
# O request_id esta dentro do payload do job, na chave `illuminate:log:context`
# (cada valor serializado com serialize(), por isso o unserialize abaixo). Ele
# foi posto la pelo Context da Fase 1, no momento do dispatch -- ou seja, e o
# id da requisicao HTTP que criou o pedido, possivelmente horas antes, em
# outro processo.
#
# E e assim que a Fase 1 e a Fase 2 se fecham: da linha da dead letter voce
# volta para a requisicao inteira --
#
#   ./scripts/follow-request.sh <request_id> 24h
#
# A pergunta que o roteiro faz e que nenhum script responde: quem olha esta
# tabela, e quando? Job que falha 5 vezes e ninguem olha e igual a job
# perdido. Na Fase 5 isto vira metrica e alerta.
#
# Rode de um terminal do Ubuntu, da raiz do projeto.

set -u

N="${1:-10}"

cd "$(dirname "$0")/.." || exit 1

SQL="select coalesce(json_agg(t), '[]')::text from (select uuid, failed_at::text as failed_at, payload, exception from failed_jobs order by failed_at desc limit ${N}) t;"

JSON="$(docker compose exec -T db sh -c "psql -qtAX -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -c \"$SQL\"" 2>/dev/null)"

if [ -z "$JSON" ]; then
    echo "nao consegui ler failed_jobs (o container db esta de pe?)" >&2
    exit 1
fi

FORMAT=$(cat <<'PHPCODE'
$rows = json_decode(stream_get_contents(STDIN), true) ?: [];

if ($rows === []) {
    echo "== dead letter queue vazia ==\n\n";
    echo "Para colocar algo nela (de proposito):\n";
    echo "  NOTIFICATION_FAIL_RATE=1 em src/.env + docker compose restart worker\n";
    echo "  e depois um pedido que APROVE, para o job existir.\n";
    exit(0);
}

printf("== %d job(s) na dead letter queue ==\n\n", count($rows));

foreach ($rows as $row) {
    $payload = json_decode($row['payload'] ?? '{}', true) ?: [];
    $context = $payload['illuminate:log:context']['data'] ?? [];

    $unser = static function (?string $raw) {
        if ($raw === null) {
            return null;
        }
        $value = @unserialize($raw);

        return is_scalar($value) ? (string) $value : null;
    };

    printf("  %s\n", $payload['displayName'] ?? '(classe desconhecida)');
    printf("    falhou em   %s\n", $row['failed_at'] ?? '?');
    printf("    uuid        %s\n", $row['uuid'] ?? '?');
    printf("    tentativas  %s\n", $payload['attempts'] ?? '?');
    printf("    order_id    %s\n", $unser($context['order_id'] ?? null) ?? '-');
    printf("    request_id  %s\n", $unser($context['request_id'] ?? null) ?? '-');

    $exception = (string) ($row['exception'] ?? '');
    $first = strtok($exception, "\n");
    printf("    excecao     %s\n", substr((string) $first, 0, 160));

    $rid = $unser($context['request_id'] ?? null);
    if ($rid !== null) {
        printf("    -> ./scripts/follow-request.sh %s 24h\n", $rid);
    }
    echo "\n";
}

echo "Reprocessar (CUIDADO: o job roda de novo, e dedupe e Fase 3):\n";
echo "  docker compose exec app php artisan queue:retry <uuid>\n";
echo "  docker compose exec app php artisan queue:retry all\n";
echo "  docker compose exec app php artisan queue:flush      # apaga a DLQ\n";
PHPCODE
)

printf '%s' "$JSON" | docker compose exec -T app php -r "$FORMAT"
