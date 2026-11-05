# Mini Checkout — projeto de estudo

Observabilidade, rastreabilidade, tratamento de erros e idempotência num domínio
minúsculo. O roteiro das fases está no [CLAUDE.md](CLAUDE.md).

Cada fase tem um commit e um documento em [`docs/`](docs/), com o que foi feito,
como replicar do zero e as armadilhas do caminho:

- [Fase 0 — Setup](docs/fase-0-setup.md)

## Onde o Docker está

Não há Docker Desktop no Windows: o Docker roda **dentro do WSL (Ubuntu)**.
Abra um terminal do Ubuntu e trabalhe daqui:

```bash
cd /mnt/c/Users/nicko/Projetos/minicheckout
```

Se preferir rodar do Windows (Git Bash ou PowerShell), prefixe cada comando:

```bash
wsl -d Ubuntu -- bash -lc "cd /mnt/c/Users/nicko/Projetos/minicheckout && docker compose ps"
```

O `-lc` importa: o `docker` do snap fica em `/snap/bin`, que só entra no PATH
num shell de login.

**A VM do WSL desliga quando fica ociosa.** Quando isso acontece o Docker para
junto, e os containers voltam sozinhos na próxima vez que você usar o WSL
(todos têm `restart: unless-stopped`). Se te incomodar ver a stack
"reiniciada", deixe um terminal do Ubuntu aberto enquanto estiver testando.

## Subir

```bash
docker compose up -d --build --wait
```

Na primeira vez a imagem do app é construída e o `composer install` roda dentro
do container, então demora. `--wait` só volta quando todos os healthchecks
passam — se ele falhar, o healthcheck te diz qual serviço não ficou de pé.

| Serviço | URL / porta no host |
|---|---|
| App (nginx) | http://localhost:8010 |
| Gateway fake | http://localhost:8011 |
| Grafana | http://localhost:3010 |
| OTLP (gRPC / HTTP) | 4317 / 4318 |
| Postgres | localhost:55432 |
| Redis | localhost:56379 |

As portas 8000 e 3000 ficaram de fora porque já estão ocupadas por outro
projeto seu. Tudo é configurável no [.env](.env) da raiz.

## Os dois `.env`

| Arquivo | Para quê |
|---|---|
| `.env` (raiz) | Docker Compose: portas, senha do Postgres, caos do gateway |
| `src/.env` | Laravel: `APP_KEY`, `APP_DEBUG`, e o que for da aplicação |

**Topologia** (`DB_HOST`, `REDIS_HOST`, `QUEUE_CONNECTION`, `GATEWAY_URL`…) é
injetada pelo compose como variável de ambiente. O Dotenv do Laravel nunca
sobrescreve variável que já existe no ambiente, então **o compose sempre ganha
de `src/.env`**. Se mudar algo em `src/.env` e nada acontecer, é porque o
compose está definindo aquela chave.

## O teste observável da Fase 0

```bash
# 1. pedido criado
curl -i -XPOST http://localhost:8010/orders \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -H 'Idempotency-Key: teste-001' \
  -d '{"amount_cents": 1990, "currency": "BRL", "customer_email": "eu@example.test"}'
```

```bash
# 2. sem Idempotency-Key -> 400
curl -i -XPOST http://localhost:8010/orders \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"amount_cents": 1990}'
```

```bash
# 3. o gateway falhando por header, sem restart
curl -i -XPOST http://localhost:8011/charge \
  -H 'Content-Type: application/json' -H 'X-Chaos-Fail-Rate: 1' \
  -d '{"order_id": "manual", "amount_cents": 500}'
```

```bash
# 4. o pedido no banco e o job no log do worker
docker compose exec db psql -U minicheckout -d minicheckout -c 'table orders'
docker compose logs --tail=30 worker
```

Grafana abre em http://localhost:3010 (se pedir login, `admin` / `admin`). Na
Fase 0 ele está vazio de propósito: nada está instrumentado ainda.

## Ligando o caos

Permanente, para todas as chamadas — edite o `.env` da raiz e recarregue só o
gateway:

```bash
docker compose up -d gateway
```

Pontual, numa chamada só, sem restart — mande o header. O app repassa? Não:
estes headers são para você bater **direto** no gateway:

| Header | Efeito |
|---|---|
| `X-Chaos-Fail-Rate: 1` | 500 garantido |
| `X-Chaos-Decline-Rate: 1` | 402 (recusa) garantido |
| `X-Chaos-Timeout-Rate: 1` | dorme `GATEWAY_TIMEOUT_SLEEP_MS` |
| `X-Chaos-Latency-Ms: 3000` | 3s de latência |
| `X-Chaos-Duplicate-Webhook: 2` | manda o webhook 3x (mesmo `event_id`) |

Quantas cobranças o gateway recebeu:

```bash
curl -s http://localhost:8011/_stats
```

```bash
curl -sXPOST http://localhost:8011/_reset
```

## Carga

```bash
docker compose run --rm k6 run /scripts/smoke.js
```

```bash
docker compose run --rm k6 run /scripts/idempotency.js
```

O `idempotency.js` **tem que falhar** na Fase 0: 20 requisições com a mesma
chave geram 20 pedidos. Esse número é a foto do antes.

## Comandos do dia a dia

```bash
docker compose logs -f app worker gateway
```

```bash
docker compose exec app php artisan migrate:fresh
```

```bash
docker compose exec app php artisan tinker
```

```bash
docker compose exec db psql -U minicheckout -d minicheckout
```

```bash
docker compose exec redis redis-cli
```

```bash
docker compose down          # para tudo (mantém os dados)
docker compose down -v       # apaga Postgres e Grafana também
```

## Notas

- `src/` é bind mount: editar PHP tem efeito imediato, sem rebuild. Só mexer no
  `Dockerfile` ou no `composer.json` pede `--build`.
- `vendor/` fica no disco do Windows via `/mnt/c`, que é lento. Se incomodar, a
  gente move `vendor` para um volume nomeado (custo: o editor no Windows perde o
  autocomplete das classes do framework).
- O `worker` roda `queue:work --tries=1` de propósito: a Fase 2 é que vai
  configurar `tries`, `backoff` e `failed()`.
