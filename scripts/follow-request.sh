#!/usr/bin/env bash
#
# O instrumento do teste da Fase 1.
#
#   ./scripts/follow-request.sh <request_id>
#
# Dado um request_id, mostra tudo que os quatro processos escreveram sobre
# aquela requisição, em ordem de tempo: nginx (borda), app (php-fpm),
# worker (fila) e gateway (serviço externo).
#
# Se a Fase 1 estiver pronta, as linhas do WORKER aparecem aqui sem você ter
# passado o id para o job em lugar nenhum. Se não aparecerem, a propagação
# para a fila é o que está faltando.
#
# Rode de um terminal do Ubuntu, da raiz do projeto.

set -u
export PATH=/snap/bin:/usr/local/bin:/usr/bin:/bin:$PATH

ID="${1:-}"
SINCE="${2:-15m}"

if [ -z "$ID" ]; then
    echo "uso: $0 <request_id> [janela]      ex: $0 req-teste-1 30m" >&2
    exit 2
fi

cd "$(dirname "$0")/.." || exit 1

echo "== request_id=$ID  (ultimos $SINCE) =="
echo

total=0
for svc in nginx app worker gateway; do
    # --no-log-prefix para a saida ficar limpa; o nome do servico vem do loop.
    hits=$(docker compose logs --since "$SINCE" --no-log-prefix "$svc" 2>/dev/null | grep -F "$ID")
    n=$(printf '%s' "$hits" | grep -c . )
    total=$((total + n))

    printf -- '--- %-8s %s linha(s) ---\n' "$svc" "$n"
    if [ "$n" -gt 0 ]; then
        printf '%s\n' "$hits"
    fi
    echo
done

echo "== total: $total linha(s) =="

if [ "$total" -eq 0 ]; then
    cat <<'MSG'

Nenhuma linha. Possibilidades, na ordem em que vale checar:
  1. o id nao existe (erro de digitacao, ou a requisicao nao chegou)
  2. a janela de tempo e curta demais -> tente uma maior: 1h
  3. o middleware RequestId ainda nao publica o id no Context, entao os logs
     do Laravel saem sem ele (o nginx ainda deve mostrar, porque ele gera o
     proprio id independente do app)
MSG
fi
