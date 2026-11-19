#!/usr/bin/env bash
#
# O instrumento do teste observavel da Fase 2.
#
#   ./scripts/gateway-down.sh [segundos] [modo]
#
#       segundos   quanto tempo o gateway fica fora       (default 120)
#       modo       down | unavailable | fail | timeout | latency
#                                                         (default down)
#
# Os cinco modos NAO sao variacoes do mesmo teste: cada um exerce uma linha
# diferente da tabela de decisao em app/Services/PaymentGateway.php.
#
#   down         o container e PARADO. Conexao recusada: a cobranca nao saiu.
#                -> retenta  -> 503 GatewayUnavailable  -> pedido `failed`
#                E o "gateway fora por 2 minutos" do roteiro, ao pe da letra.
#
#   unavailable  o gateway responde 503 ("estou fora, nao processei nada").
#                -> retenta  -> 503 GatewayUnavailable  -> pedido `failed`
#                E o unico modo em que o /_stats MOSTRA o retry: 1 requisicao
#                do cliente = N cobrancas tentadas.
#
#   fail         o gateway responde 500 ("explodi"). Pode ter cobrado antes.
#                -> NAO retenta -> 504 PaymentUnconfirmed -> pedido `unknown`
#
#   timeout      o gateway dorme mais do que o cliente aguenta. A cobranca
#                saiu e nao voltou resposta.
#                -> NAO retenta -> 504 PaymentUnconfirmed -> pedido `unknown`
#
#   latency      responde certo, 3s mais tarde, com timeout de 3s do nosso
#                lado. Nao e falha, e DEGRADACAO: as duas coisas podem sair
#                na mesma rodada, e e o caso que so a Fase 5 enxerga bem.
#
# Em todos, mostra as TRES vistas do mesmo incidente:
#
#   1. o que o CLIENTE ve     status, content-type, tempo e corpo
#   2. o que o LOG mostra     as linhas do app e do gateway
#   3. o que ACONTECEU        quantas cobrancas o gateway recebeu, e a fila
#
# A diferenca entre antes e depois da Fase 2 esta nas vistas 1 e 3:
#
#   antes   vista 1: 500 em application/json com stack trace, sempre
#           vista 3: 1 cobranca por pedido, em todos os modos (sem retry)
#   depois  vista 1: 503 ou 504 em application/problem+json, com request_id,
#                    e o status do pedido diz `failed` (nao cobrou) ou
#                    `unknown` (nao sabemos)
#           vista 3: em `unavailable`, N cobrancas por pedido  <- e e por
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
MODE="${2:-down}"
INTERVAL="${INTERVAL:-10}"

cd "$(dirname "$0")/.." || exit 1

STOP_CONTAINER=0
CHAOS=""
# O status que uma cobranca direta no gateway tem que devolver quando o caos
# esta ligado. E a SONDA: um instrumento que nao prova que ligou o caos produz
# resultado bonito e errado (foi o que aconteceu na primeira rodada desta
# fase: o `up -d` falhou, o stderr estava redirecionado, e o script mediu um
# gateway saudavel por 40 segundos).
EXPECTED=""

case "$MODE" in
    down)        STOP_CONTAINER=1 ;;
    unavailable) CHAOS="GATEWAY_FAIL_RATE=1 GATEWAY_FAIL_STATUS=503"; EXPECTED=503 ;;
    fail)        CHAOS="GATEWAY_FAIL_RATE=1 GATEWAY_FAIL_STATUS=500"; EXPECTED=500 ;;
    timeout)     CHAOS="GATEWAY_TIMEOUT_RATE=1 GATEWAY_TIMEOUT_SLEEP_MS=8000"; EXPECTED=504 ;;
    latency)     CHAOS="GATEWAY_LATENCY_MS=3000"; EXPECTED=200 ;;
    *)
        echo "modo invalido: $MODE" >&2
        echo "use: down | unavailable | fail | timeout | latency" >&2
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

# --- 0. espera a aplicacao -------------------------------------------------
# Sem isto, a requisicao #1 e um 502 do nginx (php-fpm ainda subindo) e voce
# passa dez minutos investigando o caos errado.
echo "-- esperando nginx -> php-fpm -> Laravel -> banco ..."
for _ in $(seq 1 60); do
    if in_app curl -fsS -o /dev/null --max-time 3 http://nginx/up >/dev/null 2>&1; then
        break
    fi
    sleep 2
done

if ! in_app curl -fsS -o /dev/null --max-time 3 http://nginx/up >/dev/null 2>&1; then
    echo "a aplicacao nao respondeu em /up: suba a stack antes (docker compose up -d)" >&2
    exit 1
fi

# --- 1. derruba -------------------------------------------------------------
if [ "$STOP_CONTAINER" -eq 1 ]; then
    echo "-- parando o container do gateway ..."
    dc stop gateway >/dev/null

    # Sonda invertida: aqui o SUCESSO e a falha. Se ele responder, nao esta fora.
    if in_app curl -fsS -o /dev/null --max-time 3 http://gateway:8080/health >/dev/null 2>&1; then
        echo "   o gateway continua respondendo: abortando para nao medir errado" >&2
        exit 1
    fi
    echo "   gateway parado: a conexao vai ser RECUSADA (nada sai)."
else
    # `up -d` recria o container com a env nova. Efeito colateral util: o
    # contador do gateway (/_stats, em /tmp dentro do container) zera junto.
    echo "-- derrubando o gateway ($CHAOS) ..."
    # `env` e nao `VAR=1 docker ...` porque CHAOS pode ter mais de uma variavel.
    # E SEM `2>&1 >/dev/null`: engolir o erro deste comando foi o bug que fez
    # este script medir um gateway saudavel e chamar o resultado de "antes".
    # shellcheck disable=SC2086
    if ! env $CHAOS docker compose up -d gateway >/dev/null; then
        echo "   nao consegui recriar o container do gateway: abortando" >&2
        exit 1
    fi

    for _ in $(seq 1 30); do
        if in_app curl -fsS -o /dev/null --max-time 3 http://gateway:8080/health >/dev/null 2>&1; then
            break
        fi
        sleep 1
    done

    in_app curl -sS --max-time 5 http://gateway:8080/health 2>/dev/null | tr -d '\n' | tr -s ' ' | cut -c1-300
    echo

    # A SONDA: uma cobranca direta no gateway, sem passar pelo app. Prova que
    # a alavanca de caos esta ligada ANTES de qualquer medicao.
    PROBE="$(in_app curl -sS -o /dev/null -w '%{http_code}' --max-time 20 \
        -X POST http://gateway:8080/charge \
        -H 'Content-Type: application/json' \
        -H 'X-Request-Id: sonda-do-script' \
        -d '{"order_id":"sonda","amount_cents":1}' 2>/dev/null)"

    echo "   sonda direta no gateway -> ${PROBE:-???}  (esperado: $EXPECTED)"

    if [ "$PROBE" != "$EXPECTED" ]; then
        echo "   o caos NAO esta ligado: abortando para nao medir errado" >&2
        dc up -d gateway >/dev/null 2>&1
        exit 1
    fi

    # A sonda contou como tentativa. Zera, para o /_stats da vista 3 falar so
    # das requisicoes do cliente.
    in_app curl -sS -o /dev/null --max-time 5 -X POST http://gateway:8080/_reset >/dev/null 2>&1
fi
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
    BODY="$(printf '%s\n' "$OUT" | grep -v '^---META---' | tr -d '\n' | tr -s ' ' | cut -c1-300)"

    STATUS="$(printf '%s' "$META" | cut -d'|' -f1)"
    CTYPE="$(printf '%s' "$META" | cut -d'|' -f2)"
    TIME="$(printf '%s' "$META" | cut -d'|' -f3)"

    printf '[%s] #%-2s %-24s -> %s  %-28s %ss\n' \
        "$(date +%H:%M:%S)" "$i" "$RID" "${STATUS:-???}" "${CTYPE:--}" "${TIME:--}"
    printf '          %s\n\n' "${BODY:-(corpo vazio)}"

    REMAINING=$(( END - $(date +%s) ))
    [ "$REMAINING" -le 0 ] && break
    sleep $(( REMAINING < INTERVAL ? REMAINING : INTERVAL ))
done

# --- 3. o que aconteceu do outro lado ---------------------------------------
echo "--------------------------------------------------------------"
echo " VISTA 3: o que ACONTECEU"
echo "--------------------------------------------------------------"
echo "Pedidos enviados pelo cliente: $i"
echo
echo "Status no banco (failed = nao cobrou | unknown = NAO SABEMOS):"
dc exec -T db sh -c 'psql -qtAX -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "select status, count(*) from orders group by status order by status;"' 2>/dev/null \
    | sed 's/^/    /'
echo

if [ "$STOP_CONTAINER" -eq 1 ]; then
    echo "O gateway estava PARADO: nao ha /_stats para ler (e, quando ele"
    echo "voltar, o contador comeca de zero). Quantas tentativas houve so"
    echo "aparece no NOSSO log: as linhas gateway.retrying da vista 2."
else
    echo "Cobrancas que o gateway recebeu:"
    in_app curl -sS --max-time 5 http://gateway:8080/_stats 2>/dev/null
    echo
    echo "  attempts_total > pedidos enviados  =>  o retry esta ligado, e cada"
    echo "  tentativa foi uma COBRANCA tentada. Sem idempotencia do outro lado"
    echo "  (o gateway fake nao tem, de proposito), isso seria cobranca dupla."
    echo "  attempts_total == pedidos enviados =>  nos NAO retentamos, porque"
    echo "  nao havia prova de que a cobranca nao tinha acontecido."
fi
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
echo "O que procurar:"
echo "    gateway.retrying      so aparece quando ha PROVA de que nao cobrou"
echo "    gateway.not_retrying  a decisao de NAO insistir, com o motivo"
echo "    order.payment_failed  warning: nada foi cobrado"
echo "    order.payment_unconfirmed  critical: pode haver dinheiro cobrado"
echo
