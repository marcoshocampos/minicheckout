# Fase 1 — Logs estruturados e correlação

Status: **concluída**. O log do projeto deixou de ser texto e passou a ser dado,
e um `request_id` atravessa os quatro processos — inclusive o job assíncrono.

## O que esta fase prova

Dado **um** `request_id`, eu acho as **11 linhas** que aquela requisição
produziu, em quatro processos diferentes:

| Processo | Linhas | O que é |
|---|---|---|
| `nginx` | 2 | log de acesso da cobrança e do webhook |
| `app` | 4 | `order.created`, `order.paid`, `webhook.received`, `webhook.processed` |
| `worker` | 2 | `notification.sending`, `notification.sent` |
| `gateway` | 3 | `charge.received`, `charge.approved`, `webhook.sent` |

As duas linhas do **worker** são a prova da fase: elas saem de outro processo,
de outro container, e ninguém passou o id para o job em lugar nenhum.

## O conceito, em poucas linhas

Um log serve para responder *"o que aconteceu com **esta** requisição?"*. Para
isso precisa de duas coisas:

1. **Estrutura.** A linha é um objeto, não uma frase.
   `{"event":"order.paid","order_id":"01a1f4…"}` é consultável;
   `[14:28] Pedido 01a1f4 pago com sucesso` só é *grep*ável.
2. **Correlação.** Todas as linhas de uma mesma requisição carregam o mesmo
   `request_id`, inclusive as escritas em outro processo, depois, sem
   requisição HTTP nenhuma.

**Sem isso**, investigar é: você tem o horário aproximado, abre quatro logs,
filtra por janela de tempo e adivinha quais linhas pertencem à mesma história.
Com 1 requisição por segundo é chato; com 100, é impossível. O `request_id`
troca "procurar" por "consultar".

## O caminho do id

```
cliente
  │  X-Request-Id: req-fase1-depois        (opcional)
  ▼
nginx ──── map $http_x_request_id: respeita o que veio, ou usa $request_id
  │        fastcgi_param HTTP_X_REQUEST_ID
  ▼
App\Http\Middleware\RequestId (global, prepend)
  │  valida  →  descarta e gera um ULID se não presta
  │  Context::add('request_id', $id)
  │  $request->headers->set(...)           ← para quem ler o request
  │  $response->headers->set(...)          ← inclusive em 4xx e 5xx
  ├──────────────► logs do app      (ContextLogProcessor põe em `extra`)
  ├──────────────► gateway fake     (header X-Request-Id, lido do Context)
  │                   │
  │                   └── webhook de volta com o MESMO id
  └──────────────► job na fila
                      Context::dehydrate() vai no payload do Redis,
                      JobProcessing faz hydrate() no worker
```

Duas peças do framework fazem o trabalho pesado, e vale saber os nomes:

- **`Illuminate\Log\Context\ContextLogProcessor`** é empurrado em *todo* canal
  de log (`LogManager.php:150`) e injeta o Context no campo `extra` do registro
  do Monolog. É por isso que nenhum `Log::info()` repete o `request_id`.
- **`Illuminate\Log\Context\ContextServiceProvider`** registra um
  `Queue::createPayloadUsing` que grava `Context::dehydrate()` na chave
  `illuminate:log:context` do payload do job, e um listener de `JobProcessing`
  que faz `hydrate()` do outro lado. A travessia para a fila é automática.

## O que mudou

| Arquivo | O quê |
|---|---|
| `src/.env` | `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter` e `LOG_SOURCE` |
| `src/config/logging.php` | correção do canal `stderr` (ver armadilha 1), `formatter_with`, a chave `logging.source` e o `tap` ligado |
| `src/app/Http/Middleware/RequestId.php` | lê, valida, gera, publica no Context e devolve no response |
| `src/app/Logging/FormatLogsAsJson.php` | o tap: processor com `source` e `host`, e troca do formatter |
| `src/app/Logging/LogLineFormatter.php` | **novo**: a forma da linha |
| `src/app/Http/Controllers/OrderController.php` | `Context::add('order_id')`; os três logs pararam de repetir o id |
| `src/app/Jobs/SendOrderNotification.php` | reafirma `order_id` no Context; o e-mail do cliente saiu do log |
| `src/app/Services/PaymentGateway.php` | o id propagado vem do Context, não de `request()` |
| `src/app/Http/Controllers/PaymentWebhookController.php` | `order_id` e `event_id` no Context |
| `src/tests/Feature/RequestIdTest.php` | **novo**: 13 casos do contrato do middleware |
| `src/tests/Unit/LogLineFormatterTest.php` | **novo**: 6 casos da forma da linha |
| `docker-compose.yml` | `LOG_SOURCE` por container (`app` / `worker`) |
| `docs/api/openapi.yaml` | `X-Request-Id` declarado nas 10 respostas |

## A forma da linha

```json
{"ts":"2026-11-19T14:28:40.494903+00:00",
 "source":"worker",
 "level":"info",
 "event":"notification.sending",
 "request_id":"req-fase1-depois",
 "order_id":"01a1f435-9517-728d-af83-ef39b2defb0d",
 "host":"54d8a958a4c7",
 "context":{"attempt":1}}
```

Quatro decisões aí dentro:

- **`ts`, `source`, `event`, `request_id` na raiz** — é a mesma forma que o log
  de acesso do nginx e o do gateway fake já usavam. Com os quatro processos
  iguais, **uma** consulta vale para todos. Sem o `LogLineFormatter`, o
  `JsonFormatter` puro deixaria o id em `extra.request_id`, e a consulta seria
  `extra_request_id` aqui e `request_id` lá.
- **O payload da chamada fica aninhado em `context`** — são as chaves que cada
  `Log::info('evento', [...])` escolhe. Achatar faria duas linhas com o mesmo
  `event` terem formatos diferentes, e um `status` de gateway poderia
  sobrescrever um campo da linha. O formatter também ignora valor de Context
  que tente usar um nome reservado (`ts`, `source`, `level`, `event`,
  `context`): **a forma da linha não depende de quem loga**.
- **`level` é a palavra, não o número** — o Grafana reconhece `level` quando o
  valor é `info`/`error`/`warning` e colore o log por isso. O 200/400 do
  Monolog não serve para nada aqui.
- **`source` e `host` vêm do processo, não do código** — `app` e `worker`
  rodam a *mesma imagem* e o *mesmo código*; o papel do processo só pode vir de
  quem o subiu (`LOG_SOURCE` no compose). O `host` é o id do container, que é
  como se distinguem duas réplicas do worker quando houver mais de uma.

## O teste observável da Fase 1

```bash
# o worker é um processo longo: não recarrega código sozinho
docker compose restart worker

curl -i -XPOST http://localhost:8010/orders \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: pedido-de-teste-da-fase-1' \
  -H 'X-Request-Id: req-fase1-depois' \
  -d '{"amount_cents": 1990, "currency": "BRL", "customer_email": "fase1@example.test"}'
```

O response tem que trazer o header de volta:

```
HTTP/1.1 201 Created
X-Request-Id: req-fase1-depois
```

E então, o instrumento da fase:

```bash
./scripts/follow-request.sh req-fase1-depois
```

```
== request_id=req-fase1-depois  (ultimos 15m) ==

--- nginx    2 linha(s) ---
{"ts":"2026-11-19T14:28:38+00:00","source":"nginx","request_id":"req-fase1-depois","method":"POST","path":"/index.php","status":201,"duration_ms":10.817,...}
{"ts":"2026-11-19T14:28:40+00:00","source":"nginx","request_id":"req-fase1-depois","method":"POST","path":"/index.php","status":200,"duration_ms":2.687,...}

--- app      4 linha(s) ---
{"ts":"...35.227217+00:00","source":"app","level":"info","event":"order.created","request_id":"req-fase1-depois","order_id":"01a1f435-…","host":"32ae49b00fab","context":{"amount_cents":1990,"idempotency_key":"pedido-de-teste-da-fase-1"}}
{"ts":"...37.997256+00:00","source":"app","level":"info","event":"order.paid","request_id":"req-fase1-depois","order_id":"01a1f435-…","host":"32ae49b00fab","context":{"payment_id":"pay_783bc66ee3b1ea2c"}}
{"ts":"...39.830829+00:00","source":"app","level":"info","event":"webhook.received","request_id":"req-fase1-depois","order_id":"01a1f435-…","event_id":"evt_e054cd7c087f6564","host":"32ae49b00fab","context":{"type":"payment.succeeded","delivery":"1/1"}}
{"ts":"...40.456523+00:00","source":"app","level":"info","event":"webhook.processed","request_id":"req-fase1-depois","order_id":"01a1f435-…","event_id":"evt_e054cd7c087f6564","host":"32ae49b00fab","context":{"new_status":"paid"}}

--- worker   2 linha(s) ---
{"ts":"...40.494903+00:00","source":"worker","level":"info","event":"notification.sending","request_id":"req-fase1-depois","order_id":"01a1f435-…","host":"54d8a958a4c7","context":{"attempt":1}}
{"ts":"...40.708773+00:00","source":"worker","level":"info","event":"notification.sent","request_id":"req-fase1-depois","order_id":"01a1f435-…","host":"54d8a958a4c7","context":{"notified_at":"2026-11-19T14:28:40.000000+00:00"}}

--- gateway  3 linha(s) ---
{"ts":"...37+00:00","source":"gateway","event":"charge.received","request_id":"req-fase1-depois","traceparent":null,...}
{"ts":"...37+00:00","source":"gateway","event":"charge.approved","request_id":"req-fase1-depois","payment_id":"pay_783bc66ee3b1ea2c","event_id":"evt_e054cd7c087f6564","webhooks":1}
{"ts":"...40+00:00","source":"gateway","event":"webhook.sent","request_id":"req-fase1-depois","event_id":"evt_e054cd7c087f6564","delivery":"1/1","status":200,...}

== total: 11 linha(s) ==
```

Leia a coluna `ts` de cima a baixo: dá a história inteira, com os tempos.
`order.created` 14:28:35, o gateway responde 14:28:37 (2,7 s de cobrança
síncrona), o webhook chega 14:28:39, o job roda 14:28:40. **Isto é o que um
trace vai mostrar desenhado na Fase 4** — e é também por isso que a Fase 4 não
é redundante: aqui a ordem e a duração eu reconstruo na cabeça, lendo
`ts`.

### A suíte de testes

```bash
docker compose exec app php vendor/bin/phpunit
# OK (21 tests, 77 assertions)
```

O que está coberto, além do caminho feliz: id com `\n` dentro (log injection),
id com espaço, aspas, barra, acento, vazio, um byte acima do limite, o limite
exato, o header no **500** e o header no **404**.

## Armadilhas que custaram tempo

### 1. `LOG_STDERR_FORMATTER=` vazio derruba o canal, em silêncio

O esqueleto da fase deixou a env declarada e **vazia**, como "alavanca
comentada". O `env()` do Laravel devolve **string vazia**, não `null`, e o
`LogManager` decide assim:

```php
if (! isset($config['formatter'])) { /* LineFormatter padrão */ }
elseif ($config['formatter'] !== 'default') {
    $handler->setFormatter($this->app->make($config['formatter']));  // make('')
}
```

`isset('')` é `true`. O `make('')` lança `BindingResolutionException`, o
`LogManager` **captura a exceção** e troca o canal pelo *emergency logger*, que
escreve em `storage/logs/laravel.log` **dentro do container**:

```
[2026-11-18 15:11:23] laravel.EMERGENCY: Unable to create configured logger.
Using emergency logger. (BindingResolutionException: Target class [] does not exist.)
```

Resultado: **zero linha** da aplicação no `docker compose logs`, e nada no
output dizendo por quê. Isso aconteceu 13 vezes antes de alguém olhar o arquivo
certo.

A correção é `env('LOG_STDERR_FORMATTER') ?: null`, que trata ausência e vazio
igual. A lição é maior que o bug: **a infraestrutura de observabilidade também
falha, e quando ela falha calada você fica cego sem saber.**

### 2. O worker não recarrega código

`queue:work` é um processo longo: ele carregou as classes na memória quando
subiu. Mudança em job, em `config/logging.php` ou no formatter **não** vale
para ele até um `docker compose restart worker`. O `app` (php-fpm com
`opcache.validate_timestamps=1`) recarrega a cada requisição, o worker não.
Sintoma: o app loga no formato novo e o worker continua no antigo.

### 3. Atrás do nginx, o gerador do app quase nunca roda

O `map` do nginx já gera um id quando o cliente não manda. O middleware
**respeita** o que chega, então o que você vê no response é o id do nginx —
32 caracteres hex — e não o ULID de 26 do `Str::ulid()`:

```
# sem mandar o header
X-Request-Id: 71a2071932d484cb96e5a34d8417cf17     <- 32 chars, é do nginx
```

O gerador do middleware é o **fallback** para quando não há nginx na frente
(teste, fila, outro entrypoint). Ele não é código morto, mas também não é o
caminho comum — e é bom saber disso antes de passar meia hora procurando por
que "o ULID não aparece".

### 4. `follow-request.sh` pode dar falso positivo

O script faz `grep -F` na **linha inteira**. Num teste anterior a
`Idempotency-Key` era `infra-req-infra-001304`, que contém o `request_id`, e a
linha `order.created` casou por causa da chave — não por correlação. A
contagem mentiu "app 1 linha" quando o certo era zero.

Para o teste da fase valer, use uma `Idempotency-Key` que **não** contenha o
`request_id`.

### 5. `<env>` do phpunit.xml não vence o ambiente do container

O teste do 500 faz o handler **reportar** a exceção, e a linha de log cai no
meio da saída do PHPUnit. A correção óbvia — `<env name="LOG_CHANNEL"
value="null"/>` no `phpunit.xml` — **não funciona dentro do container**, nem com
`force="true"`: o compose define `LOG_CHANNEL` no ambiente de verdade, o PHPUnit
escreve em `$_ENV`/`putenv`, e o `Env` do Laravel lê `$_SERVER` **primeiro**.

Resolvido com `Log::spy()` no teste: local, explícito e funciona nos dois
lugares.

## Decisões que valem registrar

### ULID, e não UUIDv4

ULID é ordenável por tempo: ajuda quem indexa o log e dá uma dica de *quando*
só de olhar o id. `Str::uuid7()` serviria igual; `Str::uuid()` (v4) é aleatório
e não ordena.

### Allowlist estreita, e não sanitização

Um id com `\n` dentro injetaria no log uma linha falsa, escrita pelo cliente —
log injection de verdade. Aqui o JSON escaparia o `\n`, mas o **mesmo id** vai
para o log de acesso do nginx e para o gateway, e manter a conta de quem escapa
o quê em cada saída é caro. Mais barato aceitar só o que é inofensivo em
qualquer saída: `[A-Za-z0-9._:-]`, até 200 bytes (o mesmo `maxLength` da spec).

### Id inválido é descartado, não corrigido

Um id truncado, ou com caracteres removidos, seria um id que o cliente não
reconhece — pior que um id novo, porque *parece* o dele. O response diz qual id
valeu.

### O id volta para o `$request`

Sem `$request->headers->set(...)`, o `PaymentGateway` mandaria ao gateway o
valor cru que acabamos de rejeitar, e os dois lados da chamada ficariam com ids
diferentes. Quem lê o Context e quem lê o request têm que achar o mesmo id.

### O `PaymentGateway` lê do Context, não de `request()`

Duas razões: o Context tem o valor já **validado**, e o retry desta chamada na
Fase 2 pode acontecer **dentro de um job**, onde `request()` não existe.

### O e-mail do cliente saiu do log

Log é dado que deixa o processo e vai para um índice de busca, e PII em índice
de busca é problema que você não quer descobrir depois. O `order_id` já
identifica o pedido e o e-mail está no banco. Para dado que precisa atravessar
para o job **sem** aparecer no log existe `Context::addHidden()`.

### `request_id` é id de FLUXO, não de requisição HTTP

O gateway devolve o id que recebeu, e tanto o `map` do nginx quanto o
middleware respeitam o header que chega. Então a cobrança e o webhook —
**duas** requisições HTTP, com status e latências próprios — compartilham um
id.

É cômodo para investigar e é impreciso. A alternativa seria id novo no webhook
com `parent_request_id` apontando para o original, ao custo de o nginx e o app
discordarem na mesma requisição. A distinção certa tem nome — *trace* é a
história toda, *span* é cada etapa — e chega na Fase 4.

### Os logs ainda não vão para o Loki

Esta fase para no `docker compose logs`. Mandar para o Loki é da Fase 4/5, e a
forma da linha já foi escolhida pensando nisso: campos no topo, `level` como
palavra, e espaço para o `trace_id` entrar sem mudar nada.

## O que ficou como TODO de propósito

| Arquivo | Fase | O que falta |
|---|---|---|
| `bootstrap/app.php` | 2 | `dontReport`, `render` de exceção de domínio |
| `app/Services/PaymentGateway.php` | 2 | `timeout()`, `retry()` seletivo, backoff com jitter |
| `app/Jobs/SendOrderNotification.php` | 2, 3 | `tries`, `backoff()`, `failed()`; job idempotente |
| `app/Http/Middleware/Idempotency.php` | 3 | hash do payload, lock, máquina de estados da chave |
| `app/Http/Controllers/PaymentWebhookController.php` | 3 | `UNIQUE(event_id)`, evento fora de ordem |
| migrations | 3 | tabelas `idempotency_keys` e `webhook_events` |
| `app/Logging/LogLineFormatter.php` | 4 | `trace_id` e `span_id` entram na raiz da linha |

## Critério de "entendi a Fase 1"

Você deve conseguir responder sem olhar o código:

1. Por que o `request_id` aparece no log do job, se ninguém o passou para o
   job? Quais duas peças do framework fazem isso?
2. Qual a diferença entre o que cai em `context` e o que cai em `extra` numa
   linha do Monolog, e por que o `LogLineFormatter` trata os dois de forma
   diferente?
3. Por que validar um header que *você* vai escrever no log? O que acontece se
   não validar?
4. Por que o `PaymentGateway` lê o id do Context e não de `request()`?
5. O webhook do gateway tem o mesmo `request_id` da requisição que criou o
   pedido. Em que isso ajuda, em que isso mente, e o que a Fase 4 resolve?
6. Seu log para de aparecer no `docker compose logs` e nada reclama. Onde você
   olha primeiro?
