# Fase 0 — Setup

> Uma fase, um commit, um documento. Este arquivo explica **o que foi feito**,
> **por quê** e **como refazer do zero**.

## O que esta fase prova

Nada de observabilidade, idempotência ou tracing ainda. A Fase 0 existe para
que **todas as fronteiras do sistema sejam reais** antes de você estudar
qualquer conceito.

É uma condição necessária, não um detalhe de infraestrutura:

| Se a fronteira é falsa | O conceito fica impossível de estudar |
|---|---|
| Gateway é uma função no mesmo processo | Não existe timeout, nem erro de conexão, nem retry |
| Worker roda no mesmo container do web | Não há como matar o worker no meio de um job |
| Servidor atende 1 requisição por vez | Condição de corrida nunca aparece |
| Log vai para arquivo dentro do container | Você nunca olha, e "não tem log" |

Fim da fase: o fluxo completo roda e você **vê** cada etapa acontecer.

## O fluxo que está de pé

```
   curl
     │  POST /orders  (Idempotency-Key obrigatório)
     ▼
  ┌───────┐   fastcgi   ┌─────┐   HTTP    ┌─────────┐
  │ nginx │ ──────────► │ app │ ────────► │ gateway │  (falha de propósito)
  └───────┘             └─────┘           └─────────┘
                           │                   │
                 fila      │                   │ webhook (às vezes 3x,
                (redis)    ▼                   │  às vezes atrasado)
                       ┌────────┐              │
                       │ worker │              │
                       └────────┘              ▼
                                        POST /webhooks/payments
                                              (volta para o nginx)
```

Os sete serviços e o motivo de cada um existir **separado**:

| Serviço | Imagem | Porta no host | Existe para |
|---|---|---|---|
| `nginx` | `nginx:1.27-alpine` | 8010 | Dar **concorrência real** ao PHP |
| `app` | build própria (php 8.4-fpm) | — | Rodar o Laravel |
| `worker` | a mesma imagem do `app` | — | Ser um processo matável e o 2º processo que o `trace_id` atravessa |
| `db` | `postgres:17-alpine` | 55432 | Estado durável e as **constraints** da Fase 3 |
| `redis` | `redis:7-alpine` | 56379 | Fila, cache e `Cache::lock` |
| `gateway` | build própria (php 8.4-cli) | 8011 | Ser um HTTP de saída que falha sob comando |
| `lgtm` | `grafana/otel-lgtm` | 3010 | Grafana + Loki + Tempo + Prometheus + Collector |

(`k6` existe sob `profiles: ["tools"]`, então não sobe no `up` normal.)

## Como replicar do zero

Partindo de uma pasta vazia. Os comandos assumem um terminal do **Ubuntu no
WSL**, onde o Docker roda (ver "Armadilha 1").

### 1. Árvore de pastas

```bash
mkdir -p docker/{app,nginx,gateway} gateway/{public,bin} k6 src
```

A decisão: o Laravel vai em `src/`, não na raiz. O repositório tem mais de um
serviço, e misturar `app/`, `config/`, `routes/` do Laravel com `docker/`,
`gateway/`, `k6/` deixa a raiz ilegível.

### 2. Instalar o Laravel sem ter PHP na máquina

```bash
docker run --rm -e COMPOSER_ALLOW_SUPERUSER=1 -v "$PWD/src":/app -w /app \
  composer:2 create-project laravel/laravel . --no-interaction --prefer-dist
```

Veio o Laravel 13.17 (PHP ≥ 8.3). Observação: o `post-create-project-cmd` do
Laravel cria e migra um `database/database.sqlite`. Como vamos usar Postgres,
apague — senão ele vira uma fonte de confusão ("migrei e não apareceu nada no
Postgres").

```bash
rm -f src/database/database.sqlite
```

### 3. Os arquivos de infraestrutura

Nesta ordem, porque cada um depende do anterior:

1. **`.env` da raiz** — portas, senha do Postgres e o caos do gateway
2. **`docker/app/Dockerfile`** — `php:8.4-fpm` + extensões via
   `install-php-extensions` (`pdo_pgsql`, `redis`, `pcntl`, `bcmath`, `intl`,
   `opcache`, `@composer`)
3. **`docker/app/entrypoint.sh`** — espera o banco, roda `composer install` se
   faltar `vendor/`, gera `APP_KEY` se faltar `.env`, roda as migrations e só
   então `exec php-fpm`
4. **`docker/nginx/default.conf`** — `fastcgi_pass app:9000`, log de acesso em
   JSON e o `X-Request-Id`
5. **`docker/gateway/Dockerfile`** — `php:8.4-cli` + `php -S`
6. **`gateway/public/index.php`** — o gateway fake
7. **`docker-compose.yml`** — amarra tudo com healthchecks

O `entrypoint.sh` é **idempotente de propósito** (pode rodar em restart, em
`scale`, quantas vezes for): é o primeiro lugar do projeto onde essa
propriedade importa, bem antes da Fase 3.

### 4. Apontar o Laravel para a stack

Três edições no que o `create-project` gerou:

**`src/bootstrap/app.php`** — registrar `routes/api.php` com prefixo vazio,
para a rota ser `POST /orders` e não `POST /api/orders`:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    apiPrefix: '',
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
```

Sem o prefixo `/api`, o `shouldRenderJsonWhen` padrão (que testa `api/*`) deixa
de pegar as nossas rotas. Por isso ele lista as rotas explicitamente — senão o
gateway, que não manda `Accept: application/json`, receberia HTML de erro.

**`src/config/services.php`** — a URL do gateway:

```php
'gateway' => ['url' => env('GATEWAY_URL', 'http://gateway:8080')],
```

**`src/.env`** — Postgres, Redis, `LOG_CHANNEL=stderr`. Guardando o `APP_KEY`
que o `create-project` já gerou.

### 5. Domínio mínimo

| Arquivo | Papel |
|---|---|
| `app/Enums/OrderStatus.php` | `pending`, `paid`, `declined`, `failed` |
| `app/Models/Order.php` | UUID como chave, `status` como enum |
| `database/migrations/..._create_orders_table.php` | A tabela, e os comentários do que a Fase 3 vai somar |
| `app/Http/Requests/StoreOrderRequest.php` | Validação (erro esperado) |
| `app/Http/Middleware/Idempotency.php` | Hoje só exige o header |
| `app/Services/PaymentGateway.php` | Cliente HTTP deliberadamente ingênuo |
| `app/Http/Controllers/OrderController.php` | O fluxo |
| `app/Http/Controllers/PaymentWebhookController.php` | Recebe o webhook |
| `app/Jobs/SendOrderNotification.php` | O job na fila |
| `routes/api.php` | As três rotas |

`declined` e `failed` são estados **separados** desde a primeira migration, e
isso não é preciosismo: é a distinção que a Fase 2 inteira trata.

- `declined` — o gateway respondeu, e a resposta foi "não". Erro **esperado**.
  Retentar não muda nada. Não acorda ninguém de madrugada.
- `failed` — o gateway não respondeu, ou respondeu 500. Erro **inesperado**.
  Retentar faz sentido — e é exatamente aí que a idempotência deixa de ser
  teoria.

### 6. Subir

```bash
docker compose up -d --build --wait
```

Na primeira vez demora: a imagem compila extensões do PHP e o
`composer install` roda dentro do container (~6 min aqui). O `--wait` só
retorna quando **todos** os healthchecks passam, então ele é o teste de que a
stack está de pé, não só "rodando".

## O teste observável da Fase 0

### 1. Pedido criado, cobrado, notificado

```bash
curl -i -XPOST http://localhost:8010/orders \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -H 'Idempotency-Key: fase0-001' -H 'X-Request-Id: req-fase0-001' \
  -d '{"amount_cents": 1990, "currency": "BRL", "customer_email": "eu@example.test"}'
```

Esperado: `201` com `"status":"paid"` e um `payment_id`.

```bash
docker compose logs app worker nginx | grep -E 'order\.|webhook\.|notification\.'
```

O que apareceu aqui:

```
nginx   request_id=req-fase0-001  POST 201 (4.4ms)
app     order.created    order_id=01a1a9bf…
app     order.paid       payment_id=pay_65a9f1b7…
worker  notification.sending      <- OUTRO PROCESSO
worker  notification.sent
app     webhook.received  delivery=1/1
app     webhook.processed new_status=paid
nginx   request_id=req-fase0-001  POST 200   <- o webhook, com o MESMO id
```

Repare na última linha. O `X-Request-Id` saiu do `curl`, passou pelo app, foi
para o gateway, o gateway o devolveu no webhook, e ele reapareceu no log do
nginx. Isso **na borda** já funciona. O que ainda **não** funciona é esse id
estar nos logs do Laravel e nos logs do job — é literalmente o trabalho da
Fase 1.

### 2. As três formas de recusar a requisição

```bash
# sem Idempotency-Key -> 400
curl -i -XPOST http://localhost:8010/orders \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"amount_cents": 1990, "currency": "BRL", "customer_email": "eu@example.test"}'
```

```bash
# payload inválido -> 422
curl -i -XPOST http://localhost:8010/orders \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -H 'Idempotency-Key: qualquer' \
  -d '{"amount_cents": 0, "currency": "XXX"}'
```

### 3. O gateway falhando sob comando

Sem reiniciar nada, por header:

```bash
curl -i -XPOST http://localhost:8011/charge \
  -H 'Content-Type: application/json' -H 'X-Chaos-Fail-Rate: 1' \
  -d '{"order_id": "manual", "amount_cents": 500}'     # -> 500
```

```bash
curl -i -XPOST http://localhost:8011/charge \
  -H 'Content-Type: application/json' -H 'X-Chaos-Decline-Rate: 1' \
  -d '{"order_id": "manual", "amount_cents": 500}'     # -> 402
```

### 4. Quantas cobranças o gateway recebeu

```bash
curl -s http://localhost:8011/_stats
```

Este contador é a **régua da Fase 3**. O gateway não é idempotente de
propósito: ele cobra toda vez que é chamado. Quando o teste das 20 requisições
simultâneas disser "1 pedido e 1 cobrança", o `1` da cobrança sai daqui.

### 5. A foto do antes

```bash
docker compose run --rm k6 run /scripts/idempotency.js
```

20 requisições simultâneas com a **mesma** `Idempotency-Key`. Hoje isto gera 20
pedidos e 20 cobranças. Anote os dois números: eles são o "antes" que dá
sentido à Fase 3.

## Armadilhas que custaram tempo

Vale ler, porque nenhuma delas se anuncia pelo que é.

### 1. `docker: command not found` com o daemon rodando

Não há Docker Desktop nesta máquina: o Docker roda **dentro do WSL**. E o
binário do snap fica em `/snap/bin`, que só entra no `PATH` num shell de
**login**.

```bash
wsl -d Ubuntu -- bash -lc "docker ps"   # funciona
wsl -d Ubuntu -- bash    script.sh      # docker: command not found
```

Em script não interativo, exporte o PATH na primeira linha.

### 2. `nginx` *unhealthy* com o nginx perfeitamente são

O healthcheck era `wget -qO- http://localhost/up`. O `wget` do busybox resolve
`localhost` para `::1` primeiro, e o server block só escutava IPv4 —
`Connection refused`. Pior: o `nginx:alpine` tem um script de entrypoint
(`10-listen-on-ipv6-by-default.sh`) que normalmente corrigiria isso, mas o
nosso `default.conf` está montado **read-only**, então ele falhou calado:

```
10-listen-on-ipv6-by-default.sh: info: can not modify /etc/nginx/conf.d/default.conf (read-only file system?)
```

Correção: `listen [::]:80;` no server block **e** `127.0.0.1` no healthcheck.

Lição que vale para o projeto inteiro: **healthcheck ruim é pior que
healthcheck nenhum**, porque ele acusa um componente saudável e manda você
investigar o lugar errado.

### 3. `Undefined constant "STDERR"` no gateway

Sob `php -S` o SAPI é `cli-server`, que **não** define `STDIN`/`STDOUT`/`STDERR`
(só o CLI puro define). O gateway morria com erro fatal na primeira linha de
log. Use `file_put_contents('php://stderr', ...)`.

Esse bug rendeu uma prévia da Fase 2 de graça: o gateway respondeu 500, e o app
reagiu exatamente como está escrito hoje — pedido `failed`, `order.payment_failed`
no log e um `RuntimeException` cru de 500, com stack trace, na cara do cliente.
É esse 500 feio que a Fase 2 vai transformar em Problem Details.

### 4. A VM do WSL desliga quando fica ociosa

O Docker para junto, e os containers voltam sozinhos na próxima vez que você
usar o WSL (todos têm `restart: unless-stopped`). O sintoma é ver a stack
"reiniciada" sem motivo. Deixar um terminal do Ubuntu aberto durante os testes
resolve.

### 5. Dois `.env`, e o do compose ganha

| Arquivo | Para quê |
|---|---|
| `.env` (raiz) | Docker Compose: portas, senha do Postgres, caos do gateway |
| `src/.env` | Laravel: `APP_KEY`, `APP_DEBUG` e o que for da aplicação |

O Dotenv do Laravel **não sobrescreve** variável que já existe no ambiente.
Como o compose injeta a topologia (`DB_HOST`, `REDIS_HOST`,
`QUEUE_CONNECTION`, `GATEWAY_URL`) como variável de ambiente, ela sempre ganha
de `src/.env`. Sintoma clássico: mudar `src/.env` e nada acontecer.

## Decisões que valem registrar

### `nginx` + `php-fpm`, não `php artisan serve`

O servidor embutido do PHP atende **uma requisição por vez**. O teste que fecha
a Fase 3 — 20 requisições simultâneas com a mesma chave — passaria com
*qualquer* código, porque as requisições seriam serializadas pelo servidor.
Você só descobriria a corrida em produção.

### `PHP_CLI_SERVER_WORKERS=10` no gateway

O mesmo problema, do outro lado da fronteira. O gateway roda `php -S`; sem essa
variável ele serializaria as 20 cobranças e esconderia metade do problema.

### Postgres, não MySQL

Três motivos concretos para as próximas fases: `ON CONFLICT DO NOTHING` para o
webhook idempotente, índice único **parcial** (se "um pagamento aprovado por
pedido" virar regra) e `jsonb` para guardar a resposta salva da chave de
idempotência.

### Redis sem persistência

`--save "" --appendonly no`. Na Fase 6, derrubar o Redis precisa doer: os jobs
na fila têm que sumir de verdade.

### `LOG_CHANNEL=stderr`

Log em arquivo dentro de container é log que ninguém lê. Em `stderr`, ele sai
no `docker compose logs` e, na Fase 4, é por onde o Collector o recolhe.

### Caos por env **e** por header

`.env` define o default permanente; os headers `X-Chaos-*` sobrescrevem numa
chamada só, sem restart. Testar "esta chamada aqui dá timeout" sem reiniciar o
container muda o ritmo do estudo.

## O que ficou como TODO de propósito

Cada conceito tem o esqueleto no lugar, a explicação no arquivo e **nenhuma**
lógica:

| Arquivo | Fase | O que falta |
|---|---|---|
| `bootstrap/app.php` | 1, 2 | Middleware de request id; `dontReport`/`render` |
| `config/logging.php` | 1 | Formatter JSON do Monolog |
| `app/Services/PaymentGateway.php` | 2 | `timeout()`, `retry()` seletivo, backoff com jitter |
| `app/Jobs/SendOrderNotification.php` | 2, 3 | `tries`, `backoff()`, `failed()`; job idempotente |
| `app/Http/Middleware/Idempotency.php` | 3 | Hash do payload, lock, máquina de estados da chave |
| `app/Http/Controllers/PaymentWebhookController.php` | 3 | `UNIQUE(event_id)`, fora de ordem |
| migration de `orders` | 3 | Tabelas `idempotency_keys` e `webhook_events` |

## Critério de "entendi a Fase 0"

Você deve conseguir responder sem olhar o código:

1. Por que o worker está num container separado, e não num `&` dentro do `app`?
2. Por que `php artisan serve` inviabilizaria o teste da Fase 3?
3. Qual a diferença entre um pedido `declined` e um `failed`, e por que ela
   existe antes de qualquer tratamento de erro estar escrito?
4. Se você mudar `DB_HOST` em `src/.env`, o que acontece? Por quê?
5. O `X-Request-Id` já atravessa app → gateway → webhook. Então o que,
   exatamente, falta para a Fase 1 estar pronta?
