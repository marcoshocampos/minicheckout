# Mini Checkout — projeto de estudo

Observabilidade, rastreabilidade, tratamento de erros e idempotência num domínio
minúsculo. O roteiro das fases está no [CLAUDE.md](CLAUDE.md).

## Documentação

Tudo neste projeto é documentado. Em três lugares, com papéis diferentes:

| Onde | O quê |
|---|---|
| [`docs/fase-*.md`](docs/) | Uma por fase: o que foi feito, como replicar do zero, as armadilhas |
| [`docs/api/*.yaml`](docs/api/) | O contrato das APIs em OpenAPI 3.1, escrito à mão |
| Os próprios arquivos de código | O porquê de cada decisão, e o `TODO` de cada conceito ainda não implementado |

Documentos de fase:

- [Fase 0 — Setup](docs/fase-0-setup.md)
- [Fase 1 — Logs estruturados e correlação](docs/fase-1-logs-e-correlacao.md)
- [Fase 2 — Tratamento de erros](docs/fase-2-tratamento-de-erros.md)

### As APIs (Swagger)

```bash
docker compose up -d swagger
```

Abre em **http://localhost:8012**, com duas specs no seletor do topo:

| Spec | O que documenta |
|---|---|
| Mini Checkout API | `POST /orders`, `GET /orders/{id}`, `POST /webhooks/payments` |
| Fake Payment Gateway | `POST /charge`, os headers `X-Chaos-*` e o webhook que ele emite |

As specs são **escritas à mão** e são a fonte da verdade: nada é gerado a
partir do código. O motivo é que o que mais precisa de documentação aqui não é
inferível — que o `Idempotency-Key` é obrigatório, o que `409` vai significar,
por que o mesmo payload devolve a resposta salva, e que o webhook pode chegar
duplicado e fora de ordem. Escrever isso à mão obriga a decidir a semântica
antes de implementar.

O custo dessa escolha é o drift: a spec pode mentir se você mudar o código e
não mexer nela. A regra que compensa isso é **mudança de contrato e mudança de
spec no mesmo commit da fase**. Onde o contrato ainda vai mudar, a spec já diz
qual fase vai mudá-lo.

O botão **Try it out** funciona de verdade: `config/cors.php` libera a origem
do Swagger UI para as rotas do projeto e expõe o `X-Request-Id` no response.

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
| **Swagger UI** | **http://localhost:8012** |
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

Grafana abre em http://localhost:3010 (se pedir login, `admin` / `admin`). Ele
está vazio de propósito até a Fase 4: o log da Fase 1 sai no
`docker compose logs`, e mandá-lo para o Loki vem junto com o tracing.

## O teste observável da Fase 1

```bash
# o worker é processo longo: não recarrega código sozinho
docker compose restart worker

curl -i -XPOST http://localhost:8010/orders \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: pedido-de-teste-da-fase-1' \
  -H 'X-Request-Id: req-fase1-depois' \
  -d '{"amount_cents": 1990, "currency": "BRL", "customer_email": "fase1@example.test"}'
```

O response volta com `X-Request-Id: req-fase1-depois`. Agora ache tudo que
aquela requisição escreveu, nos quatro processos:

```bash
./scripts/follow-request.sh req-fase1-depois
```

Tem que dar **11 linhas**: 2 do nginx, 4 do app, 2 do **worker** e 3 do
gateway. As do worker são o ponto da fase — outro processo, outro container, e
ninguém passou o id para o job.

> Use uma `Idempotency-Key` que **não** contenha o `request_id`: o script faz
> `grep` na linha inteira e a chave daria falso positivo.

```bash
# contrato do middleware e forma da linha de log
docker compose exec app php vendor/bin/phpunit
```

O como e o porquê estão em
[docs/fase-1-logs-e-correlacao.md](docs/fase-1-logs-e-correlacao.md).

## O teste observável da Fase 2

Gateway fora por 2 minutos, com as três vistas do mesmo incidente (o que o
cliente vê, o que o log mostra, o que aconteceu de verdade):

```bash
INTERVAL=30 ./scripts/gateway-down.sh 120 down
```

São cinco modos, e cada um exerce uma linha diferente da tabela de decisão:

| Modo | O gateway | A aplicação | O pedido |
|---|---|---|---|
| `down` | container parado: conexão recusada | retenta 3x → **503** | `failed` |
| `unavailable` | responde `503` ("não processei") | retenta 3x → **503** | `failed` |
| `fail` | responde `500` ("explodi") | **não** retenta → **504** | `unknown` |
| `timeout` | dorme mais que o nosso timeout | **não** retenta → **504** | `unknown` |
| `latency` | responde certo, 3s depois | degradação, não falha | `paid` |

A diferença entre `503` e `504` é a fase inteira: no 503 há **prova** de que
nada foi cobrado, então retentar e repetir são seguros; no 504 a cobrança saiu
e pode ter acontecido.

O modo `unavailable` é o que mostra o preço do retry — `attempts_total: 3` no
`/_stats` do gateway para **uma** requisição do cliente:

```bash
./scripts/gateway-down.sh 30 unavailable
```

A outra metade da fase é a fila. Com `NOTIFICATION_FAIL_RATE=1` em `src/.env`
e `docker compose restart worker`, um pedido aprovado gera 4 tentativas de
notificação (intervalos 2s, 8s, 8s) e vira linha na dead letter queue:

```bash
./scripts/dead-letter.sh
```

Ela imprime o `request_id` que estava **dentro do payload do job** e o comando
`follow-request.sh` pronto: da linha da dead letter você volta para a
requisição HTTP inteira, de horas antes, em outro processo.

O como e o porquê estão em
[docs/fase-2-tratamento-de-erros.md](docs/fase-2-tratamento-de-erros.md).

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
| `X-Chaos-Fail-Rate: 1` | falha garantida, no status abaixo |
| `X-Chaos-Fail-Status: 503` | `500` (default, pode ter cobrado) ou `503` (não processou) |
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
