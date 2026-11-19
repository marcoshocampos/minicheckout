#!/usr/bin/env bash
#
# O instrumento do teste observavel da Fase 2.
#
#   ./scripts/gateway-down.sh [segundos] [modo]
#
#       segundos   quanto tempo o gateway fica fora       (default 120)
#       modo       fail | timeout | latency               (default fail)
#
#         fail      o gateway responde 500 em toda chamada. Erro INESPERADO,
#                   mas determinado: ele respondeu, nao cobrou.
#         timeout   o gateway dorme mais do que o cliente aguenta. E o caso
#                   INDETERMINADO: nao sabemos se cobrou.
#         latency   o gateway responde certo, 3s mais tarde. Nao e falha, e
#                   degradacao: o caso que so a Fase 5 enxerga bem.
#
# Derruba o gateway fake pelo tempo pedido, manda pedidos durante a queda e
# mostra as TRES vistas do mesmo incidente:
#
#   1. o que o CLIENTE ve     status, content-type, tempo e corpo
#   2. o que o LOG mostra     as linhas do app e do gateway
#   3. o que ACONTECEU        quantas cobrancas o gateway recebeu, e a fila
#
# A diferenca entre antes e depois da Fase 2 esta nas vistas 1 e 3:
#
#   antes   vista 1: 500 em application/json com stack trace
#           vista 3: 1 cobranca por pedido (nao ha retry)
#   depois  vista 1: 503/504 em application/problem+json, com request_id
#           vista 3: N cobrancas por pedido, uma por tentativa  <- e e por
#                    isso que a Fase 3 existe: cada tentativa dessas, num
#                    gateway de verdade, pode ser dinheiro
#
# As requisicoes saem de DENTRO da rede do compose (docker compose exec app
# curl http://nginx/orders) porque a porta publicada no host nao e alcancavel
# de todo WSL. O caminho continua sendo o real: nginx -> php-fpm -> gateway.
#
# Rode de um terminal do Ubuntu, da raiz do projeto.

set -u

DURATION="${1:-120}"
MODE="${2:-fail}"
INTERVAL="${INTERVAL:-10}"

cd "$(dirname "$0")/.." || exit 1

case "$MODE" in
    fail)    CHAOS="GATEWAY_FAIL_RATE=1" ;;
    timeout) CHAOS="GATEWAY_TIMEOUT_RATE=1 GATEWAY_TIMEOUT_SLEEP_MS=8000" ;;
    latency) CHAOS="GATEWAY_LATENCY_MS=3000" ;;
    *)
        echo "modo invalido: $MODE  (use fail, timeout ou latency)" >&2
        exit 2
        ;;
esac

STAMP="$(date +%H%M%S)"
PREFIX="f2-${MODE}-${STAMP}"

dc() { docker compose "$@"; }
in_app() { docker compose exec -T app "$@"; }

echo "==============================================================="
echo " FASE 2  |  gateway fora: modo=$MODE  por ${DURATION}s"
echo " request_id destas requisicoes: ${PREFIX}-N"
echo "==============================================================="
echo

# --- 1. derruba -------------------------------------------------------------
# `up -d` recria o container com a env nova. Efeito colateral util: o
# contador do gateway (/_stats, em /tmp dentro do container) zera junto.
echo "-- derrubando o gateway ($CHAOS) ..."
# `env` e nao `VAR=1 docker ...` porque CHAOS pode ter mais de uma variavel.
# shellcheck disable=SC2086
env $CHAOS docker compose up -d gateway >/dev/null 2>&1

for _ in $(seq 1 30); do
    if in_app curl -fsS -o /dev/null --max-time 3 http://gateway:8080/health 2>/dev/null; then
        break
    fi
    sleep 1
done

in_app curl -sS --max-time 5 http://gateway:8080/health 2>/dev/null | tr -d '\n' | tr -s ' ' | cut -c1-300
echo
echo

# --- 2. a vista do cliente --------------------------------------------------
echo "--------------------------------------------------------------"
echo " VISTA 1: o que o CLIENTE ve"
echo "--------------------------------------------------------------"

END=$(( $(date +%s) + DURATION ))
i=0
IDS=""

while [ "$(date +%s)" -lt "$END" ]; do
    i=$((i + 1))
    RID="${PREFIX}-${i}"
    IDS="$IDS $RID"

    # A Idempotency-Key nao pode CONTER o request_id: o follow-request.sh faz
    # grep na linha inteira, e a chave aparece no log do order.created. Ver a
    # armadilha 4 do docs/fase-1-logs-e-correlacao.md.
    OUT="$(in_app curl -sS \
        -X POST http://nginx/orders \
        -H 'Content-Type: application/json' \
        -H "Idempotency-Key: queda-${STAMP}-${i}" \
        -H "X-Request-Id: ${RID}" \
        -d '{"amount_cents":1990,"currency":"BRL","customer_email":"caos@example.test"}' \
        -w '\n---META---%{http_code}|%{content_type}|%{time_total}\n' 2>&1)"

    META="$(printf '%s\n' "$OUT" | sed -n 's/^---META---//p')"
    BODY="$(printf '%s\n' "$OUT" | grep -v '^---META---' | tr -d '\n' | cut -c1-220)"

    STATUS="$(printf '%s' "$META" | cut -d'|' -f1)"
    CTYPE="$(printf '%s' "$META" | cut -d'|' -f2)"
    TIME="$(printf '%s' "$META" | cut -d'|' -f3)"

    printf '[%s] #%-2s %-22s -> %s  %-28s %ss\n' \
        "$(date +%H:%M:%S)" "$i" "$RID" "${STATUS:-???}" "${CTYPE:--}" "${TIME:--}"
    printf '          %s\n\n' "${BODY:-(corpo vazio)}"

    REMAINING=$(( END - $(date +%s) ))
    [ "$REMAINING" -le 0 ] && break
    sleep $(( REMAINING < INTERVAL ? REMAINING : INTERVAL ))
done

# --- 3. o que aconteceu do outro lado ---------------------------------------
echo "--------------------------------------------------------------"
echo " VISTA 3: o que ACONTECEU no gateway"
echo "--------------------------------------------------------------"
echo "Pedidos enviados pelo cliente: $i"
echo "Cobrancas que o gateway recebeu:"
in_app curl -sS --max-time 5 http://gateway:8080/_stats 2>/dev/null
echo
echo "  attempts_total > pedidos enviados  =>  o retry esta ligado, e cada"
echo "  tentativa foi uma COBRANCA tentada. Sem idempotencia do outro lado"
echo "  (o gateway fake nao tem, de proposito), isso seria cobranca dupla."
echo

# --- 4. levanta de volta ----------------------------------------------------
echo "-- restaurando o gateway a partir do .env da raiz ..."
dc up -d gateway >/dev/null 2>&1
sleep 2
in_app curl -sS --max-time 5 http://gateway:8080/health 2>/dev/null | tr -d '\n' | tr -s ' ' | cut -c1-300
echo
echo

RID="${PREFIX}-ok"
echo "-- requisicao de recuperacao ($RID):"
in_app curl -sS -o /dev/null \
    -X POST http://nginx/orders \
    -H 'Content-Type: application/json' \
    -H "Idempotency-Key: recuperacao-${STAMP}" \
    -H "X-Request-Id: ${RID}" \
    -d '{"amount_cents":1990,"currency":"BRL","customer_email":"caos@example.test"}' \
    -w '   -> %{http_code} em %{time_total}s\n' 2>&1
echo

# --- 5. a fila --------------------------------------------------------------
echo "--------------------------------------------------------------"
echo " A FILA e a DEAD LETTER QUEUE"
echo "--------------------------------------------------------------"
in_app php artisan queue:failed 2>&1 | tail -20
cat <<'MSG'

Se a dead letter estiver vazia, esta certo: com o gateway fora a cobranca nem
aprova, entao o job de notificacao nunca e despachado. O gateway fake so sabe
falhar na COBRANCA.

Para ver a outra metade da fase (tries, backoff, failed(), failed_jobs), a
alavanca e do nosso lado:

    # src/.env
    NOTIFICATION_FAIL_RATE=1
    docker compose restart worker          # o worker nao recarrega sozinho

    # um pedido que APROVA, para o job existir
    docker compose exec -T app curl -sS -X POST http://nginx/orders \
      -H 'Content-Type: application/json' \
      -H 'Idempotency-Key: dlq-1' -H 'X-Request-Id: f2-dlq-1' \
      -d '{"amount_cents":1990,"currency":"BRL","customer_email":"dlq@example.test"}'

    docker compose logs -f --no-log-prefix worker
    ./scripts/dead-letter.sh

MSG

# --- 6. a vista do log ------------------------------------------------------
echo "--------------------------------------------------------------"
echo " VISTA 2: o que o LOG mostra"
echo "--------------------------------------------------------------"
echo "Um request_id por vez, com o instrumento da Fase 1:"
for rid in $IDS "${PREFIX}-ok"; do
    echo "    ./scripts/follow-request.sh $rid 1h"
done
echo
echo "Ou todas as linhas da queda, dos quatro processos:"
echo "    docker compose logs --since 10m --no-log-prefix app gateway worker nginx | grep -F '$PREFIX'"
echo
