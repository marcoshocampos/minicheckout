# Fase 2 — Tratamento de erros

> Conceito: erro **esperado** vs **inesperado** — e o terceiro caso, o
> **indeterminado**, que é o que exige idempotência.

## O que esta fase prova

Rodando `./scripts/gateway-down.sh 120 down`, um cliente recebe isto:

```
[16:10:36] #1  f2-down-160906-1  -> 503  application/problem+json  8.73s
{"type":"/problems/gateway-unavailable","title":"Gateway de pagamento
 indisponível","status":503,"detail":"Não conseguimos falar com o gateway
 de pagamento e a cobrança não foi feita. O pedido ficou com status
 `failed`. Pode repetir a requisição com a MESMA Idempotency-Key.",
 "instance":"/orders/01a1f539-...","order_id":"01a1f539-...",
 "request_id":"f2-down-160906-1"}
```

Antes da fase, a mesma situação devolvia um `500` em `application/json` com
`exception`, `file`, `line` e o stack trace inteiro — e o pedido ficava
`pending` para sempre, porque a linha que marcava `failed` estava depois da
chamada que estourava.

Três coisas mudaram, e são as três perguntas da fase:

| | antes | depois |
|---|---|---|
| o cliente vê | stack trace | Problem Details com `request_id` |
| nós retentamos | nunca | só quando há **prova** de que não cobrou |
| o pedido fica | `pending` | `failed` (não cobrou) ou `unknown` (não sabemos) |

## O conceito, em poucas linhas

Todo sistema tem dois tipos de falha, e tratá-los igual é o bug:

| | erro **esperado** | erro **inesperado** |
|---|---|---|
| exemplo | cartão recusado, payload inválido | gateway fora, banco caiu, bug |
| faz parte do negócio? | sim | não |
| retentar muda algo? | não | às vezes |
| alguém acorda de madrugada? | não | sim |
| o cliente precisa de… | uma resposta clara | uma resposta clara **e** um id |

O que a fase ensina de verdade é que **responder** e **reportar** são eixos
independentes. Recusa de cartão: responde, não reporta. Gateway fora:
responde **e** reporta.

Sem essa separação acontecem as duas coisas que o projeto tinha: a recusa de
cartão polui o log de erro (e num dia de 5% de recusa dispara o alerta de
"erro acima de 5%" por causa do sistema funcionando certo), e a falha de
verdade vaza stack trace para o cliente. Treina-se o time a ignorar alerta e o
cliente a abrir chamado sem nenhuma informação útil.

## As três respostas possíveis a uma cobrança (e não duas)

É o coração da fase. Depois de chamar o gateway, existe um terceiro estado que
quase nenhum código modela:

```
sucesso        o gateway respondeu "cobrei"      -> paid
falha          o gateway respondeu "não cobrei"  -> declined / failed
INDETERMINADO  não obtivemos resposta conclusiva -> unknown
```

A regra que o `PaymentGateway` implementa, e é a única regra:

> **Só retenta quando há prova de que a cobrança não foi processada.**

| o que aconteceu | retenta? | vira | HTTP | pedido |
|---|---|---|---|---|
| conexão recusada / DNS / connect timeout | **sim** | `GatewayUnavailable` | 503 + `Retry-After` | `failed` |
| 408, 429, 503 ("não processei") | **sim** | `GatewayUnavailable` | 503 + `Retry-After` | `failed` |
| timeout de leitura (foi, não voltou) | não | `PaymentUnconfirmed` | 504, **sem** `Retry-After` | `unknown` |
| 500, 502, 504 (respondeu depois de trabalhar — ou não) | não | `PaymentUnconfirmed` | 504 | `unknown` |
| 402 recusa | não | `PaymentDeclined` | 402 | `declined` |
| 400/404/405 (payload errado) | não | bug nosso | 500 genérico | `failed` |

A linha do `500` é a mais frustrante: o retry resolveria 9 de 10 casos, e a
gente não liga porque o décimo é cobrança dupla. **É isso que a idempotência
compra.** Quando o gateway honrar a `Idempotency-Key` (Fase 3), aquelas duas
linhas do meio viram "sim" e o `504` quase desaparece.

### Como se distingue "não chegou" de "não sei"

O Laravel entrega toda falha de rede como uma `ConnectionException` só, o que
empurra para o mesmo `catch` dois casos de risco opostos. Mas a informação não
se perde: a exceção do Guzzle fica no `getPrevious()`, e o Guzzle 8 tem uma
taxonomia precisa —

| classe do Guzzle | errno típico | significa |
|---|---|---|
| `ConnectException` | 6, 7, 35 | nem conectou → **não chegou** |
| `ConnectTimeoutException` | 28 no aperto de mão | **não chegou** |
| `NetworkTimeoutException` | 28 depois de conectar | **não sei** |
| `ResponseTimeoutException` | 28 com resposta parcial | **não sei** |
| `NetworkException` | 52, 55, 56 | **não sei** |

`ConnectTimeoutException extends ConnectException`, então um `instanceof` em
`ConnectException` cobre os dois casos seguros. E o default — sem `previous`,
ou classe desconhecida — é o **pessimista**: não sei. Errar para o lado de
"não sei" custa uma consulta; errar para o lado de "não cobrou" custa uma
cobrança dupla.

## O que mudou

| Arquivo | O que faz |
|---|---|
| `app/Exceptions/Problem.php` | o documento da RFC 9457: `application/problem+json`, membros de extensão, `request_id` em todo erro |
| `app/Exceptions/ProvidesProblem.php` | a interface que permite **um** `render()` central |
| `app/Exceptions/PaymentDeclined.php` | esperado → 402, `dontReport` |
| `app/Exceptions/GatewayUnavailable.php` | não cobrou → 503 + `Retry-After` |
| `app/Exceptions/PaymentUnconfirmed.php` | não sabemos → 504, sem `Retry-After` |
| `bootstrap/app.php` | a política: `dontReport`, `level`, `throttle`, `dontRetry`, `render`, `respond` |
| `app/Services/PaymentGateway.php` | timeout, retry seletivo, backoff com jitter, classificação |
| `app/Http/Controllers/OrderController.php` | lança as exceções e decide o estado do pedido |
| `app/Jobs/SendOrderNotification.php` | `$tries`, `backoff()`, `failed()` |
| `app/Http/Middleware/Idempotency.php` | o 400 agora é Problem Details de verdade |
| `app/Enums/OrderStatus.php` | novo estado `unknown` |
| `config/services.php`, `src/.env` | as alavancas: timeout, tentativas, backoff base |
| `config/chaos.php`, `src/.env` | `NOTIFICATION_FAIL_RATE`, para falhar o job de propósito |
| `gateway/public/index.php` | `GATEWAY_FAIL_STATUS`: 500 ou 503, que exigem decisões opostas |
| `scripts/gateway-down.sh` | o instrumento: 5 modos, 3 vistas do mesmo incidente |
| `scripts/dead-letter.sh` | a DLQ lida de um jeito que serve para investigar |
| `tests/bootstrap.php` | **a suíte parou de apagar o banco de desenvolvimento** |

## A política, num lugar só

`bootstrap/app.php` responde cinco perguntas independentes:

```
1. isto é incidente?        -> dontReport / report / throttle
2. o que o cliente vê?      -> render / respond
3. com que gravidade loga?  -> level
4. o job deve insistir?     -> dontRetry
5. o que acompanha o log?   -> context  (a Fase 1 já resolveu)
```

O mapa que saiu disso:

| exceção | responde | reporta? | nível | retenta job? |
|---|---|---|---|---|
| `ValidationException` | 422 | não | — | não |
| `PaymentDeclined` | 402 | **não** | — | não |
| `GatewayUnavailable` | 503 | sim | `warning` | sim |
| `PaymentUnconfirmed` | 504 | sim | `critical` | **não** |
| qualquer outra | 500 | sim | `error` | sim |

Leia a coluna do meio. `PaymentDeclined` responde um 4xx e não é incidente;
`GatewayUnavailable` responde 503 **e** é incidente. A gravidade segue o
**dano**, não a causa técnica: 503 significa que nada foi cobrado e repetir
resolve (`warning`); 504 significa que pode haver dinheiro cobrado sem pedido
pago (`critical`).

E o funil: `respond()` recebe a resposta **já renderizada** e garante que o
404 do route binding, o 405, o 429 e o 500 de um bug também saiam em
`application/problem+json`. Sem ele, a API volta a ter quatro formatos de erro
— que é de onde a fase partiu.

## O teste observável

### 1. Gateway fora por 2 minutos (o teste do roteiro)

```bash
INTERVAL=30 ./scripts/gateway-down.sh 120 down
```

O container do gateway é **parado**: a conexão é recusada, nada sai.

```
[16:10:36] #1  f2-down-160906-1  -> 503  application/problem+json  8.73s
[16:11:12] #2  f2-down-160906-2  -> 503  application/problem+json  5.70s
[16:11:49] #3  f2-down-160906-3  -> 503  application/problem+json  6.98s
[16:12:23] #4  f2-down-160906-4  -> 503  application/problem+json  3.70s

Status no banco:  failed|5   paid|6   unknown|2

-- requisicao de recuperacao (f2-down-160906-ok):
   -> 201 em 5.12s
```

As três respostas do roteiro:

- **o que o cliente vê**: 503 em `application/problem+json`, com `detail`
  dizendo que nada foi cobrado, `Retry-After: 5` e o `request_id`.
- **o que o log mostra**: duas linhas `gateway.retrying`, uma
  `gateway.not_retrying` com `reason: tentativas esgotadas`, uma
  `order.payment_failed` em `warning` e o report da exceção, também em
  `warning` (por causa do `level()`).
- **o que acontece com os jobs**: nada — e está certo. Sem aprovação, o job de
  notificação nunca é despachado. A dead letter fica vazia, e é por isso que
  existe a segunda metade do teste.

Repare no tempo: 3,7s para uma conexão **recusada**. O `connectTimeout` de 1s
é gasto por tentativa, e com o container parado o nome `gateway` deixa de
resolver no DNS do Docker — "nada aconteceu" custa tempo real, e é esse tempo
que o orçamento de retry tem que caber.

### 2. O retry visível, e o que ele custa

O modo `down` não mostra quantas cobranças foram tentadas (o gateway está
parado e, quando volta, o contador zera). Para ver o preço do retry:

```bash
./scripts/gateway-down.sh 30 unavailable
```

```
sonda direta no gateway -> 503  (esperado: 503)

[16:05:56] #1  f2-unavailable-160434-1  -> 503  application/problem+json  8.15s

Pedidos enviados pelo cliente: 1
Status no banco:  failed|1  paid|4

{
    "attempts_total": 3,
    "failed_total": 3,
    "by_order": {
        "01a1f533-7b9e-7256-9416-a0ff88745b33": { "attempts": 3, "failed": 3 }
    }
}
```

**Uma requisição do cliente, três cobranças tentadas no mesmo pedido.** O
gateway fake ignora a `idempotency_key` de propósito; num gateway de verdade,
que cobra mesmo, isso é dinheiro. É a Fase 3 inteira resumida em um JSON.

### 3. O caso indeterminado

```bash
./scripts/gateway-down.sh 5 fail      # 500: "explodi"
./scripts/gateway-down.sh 5 timeout   # dorme mais que o nosso timeout
```

```
[16:06:52] #1  f2-fail-160630-1  -> 504  application/problem+json  3.35s
{"type":"/problems/payment-unconfirmed","title":"Pagamento sem confirmação",
 "status":504,"detail":"Enviamos a cobrança e não obtivemos uma resposta
 conclusiva do gateway. NÃO sabemos se o pagamento foi feito; o pedido ficou
 com status `unknown`. Consulte o pedido antes de tentar pagar de novo.",
 "instance":"/orders/01a1f534-..."}

    "attempts_total": 1,
```

Uma tentativa, e nenhuma retentativa — de propósito. Sem `Retry-After`, e com
o `instance` apontando para o pedido: em vez de "tente de novo", a resposta
diz "descubra o que aconteceu".

### 4. O erro esperado: responde 4xx e **não** aparece no log de erro

```bash
env GATEWAY_DECLINE_RATE=1 docker compose up -d gateway
```

```
HTTP/1.1 402 Payment Required
Content-Type: application/problem+json
{"type":"/problems/payment-declined","title":"Pagamento recusado",
 "status":402,"detail":"O gateway recusou a cobrança. O pedido foi criado e
 está com status `declined`...","instance":"/orders/01a1f541-...",
 "decline_code":"insufficient_funds","payment_id":"pay_05e918a599155f31",
 "request_id":"f2-recusa-1"}
```

E o log inteiro daquela requisição:

```
level=info  event=order.created   request_id=f2-recusa-1
level=info  event=order.declined  request_id=f2-recusa-1
```

**Duas linhas, as duas `info`.** Nenhum `error`, nenhum `warning`, nenhum
report do handler — e a resposta ao cliente foi um 4xx completo, com o
`decline_code` que ele usa para decidir o que dizer ao usuário final.

Isso é o `dontReport` funcionando, e é a lição da fase em cinco linhas de
saída: responder e reportar são eixos independentes. Num dia de promoção com
5% de recusa, estas linhas não movem a taxa de erro nem acordam ninguém.

### 5. A fila e a dead letter

```bash
# src/.env
NOTIFICATION_FAIL_RATE=1
docker compose restart worker       # o worker não recarrega sozinho
```

Um pedido que **aprova** (para o job existir) e o worker mostra as quatro
tentativas:

```
19:14:30  notification.sending        attempt 1
19:14:30  notification.failing_on_purpose
19:14:33  notification.sending        attempt 2   <- 2s depois
19:14:42  notification.sending        attempt 3   <- 8s depois
19:14:51  notification.sending        attempt 4   <- 8s depois (o último se repete)
19:14:51  notification.dead_letter    attempts=4  effect_lost="o cliente nao foi notificado do pedido pago"
```

Os intervalos são `2, 8, 8`: `backoff()` devolve `[2, 8]` e **o último valor se
repete** para as tentativas seguintes.

E então o fecho da Fase 1 com a Fase 2:

```bash
./scripts/dead-letter.sh
```

```
== 1 job(s) na dead letter queue ==

  App\Jobs\SendOrderNotification
    falhou em   2026-11-19 19:14:51
    uuid        a1950aad-5ca6-46e2-9f1b-1279168abbd2
    tentativas  3   (no payload; a ultima nao entra)
    order_id    01a1f53b-46f9-72de-bb8e-4d2bc7c6d7a7
    request_id  f2-dlq-2
    excecao     RuntimeException: falhei de proposito (NOTIFICATION_FAIL_RATE)
    -> ./scripts/follow-request.sh f2-dlq-2 24h
```

O `request_id` saiu de **dentro do payload do job**, onde o `Context` da Fase 1
o deixou no momento do dispatch. Da linha da dead letter você volta para a
requisição HTTP inteira — outro processo, possivelmente horas antes.

Não esqueça de voltar `NOTIFICATION_FAIL_RATE=0` e reiniciar o worker.

### A suíte de testes

```bash
docker compose exec app php vendor/bin/phpunit
# OK (37 tests, 164 assertions)
```

Os 16 testes novos (`tests/Feature/TratamentoDeErrosTest.php`) travam a
**política**, não o código: que recusa não é incidente, que 500 não é
retentado, que o 504 não leva `Retry-After`, que o trace nunca sai no corpo.
São decisões que dá para tomar errado sem nenhum teste reclamar — e que só
aparecem em produção, no dia ruim.

## Armadilhas que custaram tempo

### 1. A suíte apagava o banco de desenvolvimento

O pior bug da fase, e ele não dá erro: **passa**.

1. o compose injeta `DB_CONNECTION=pgsql` em todo container (topologia vem do
   compose, por decisão do projeto);
2. o `phpunit.xml` declara `sqlite`/`:memory:`;
3. dentro do container, o Postgres de desenvolvimento ganhava;
4. o primeiro teste com `RefreshDatabase` roda `migrate:fresh`, que dá **DROP
   em todas as tabelas**.

Doze pedidos da Fase 1 morreram para esta descoberta.

E `force="true"` no `<env>` **não resolve**: o phpunit escreve em `putenv()` e
em `$_ENV`; o `Illuminate\Support\Env` lê por adaptadores, e o primeiro da fila
é o `ServerConstAdapter`, que lê `$_SERVER`. No CLI do PHP, `$_SERVER` já vem
com as variáveis do ambiente — então o valor do container continua ganhando, em
silêncio. É a armadilha 5 da Fase 1, agora com consequência destrutiva.

Resolvido em `tests/bootstrap.php`, que escreve nos **três** lugares
(`putenv`, `$_ENV` e `$_SERVER`) antes de o Laravel subir.

### 2. O `when` do retry é consultado também na última tentativa

Na primeira versão, um cenário com 3 tentativas produzia **três** linhas
`gateway.retrying` — e só duas retentativas aconteceram.

O motivo está em `PendingRequest::send`: quando a falha é uma **resposta**, o
`tap` interno chama o callback `when` antes de olhar o contador de tentativas;
quando a falha é de **conexão**, quem decide é o `retry()` de `helpers.php`,
que checa o contador primeiro e nem chama o callback. Ou seja: "vou retentar"
dito dentro do `when` não é a mesma coisa que "vai haver outra tentativa".

A correção é o `$attempt` entrar na decisão. Devolver `false` na última
tentativa não muda comportamento nenhum (com `throw: false` a resposta é
devolvida de qualquer jeito) — muda o log. Três linhas para duas retentativas é
o tipo de erro que só se descobre contando.

### 3. Um instrumento que engole o stderr mede o cenário errado

O `gateway-down.sh` fazia `env $CHAOS docker compose up -d gateway >/dev/null
2>&1`. Numa rodada o `up -d` falhou (a API do Docker estava indisponível por um
instante), o erro foi para `/dev/null`, o health check passou contra o container
**antigo** — e o script mediu um gateway saudável por 40 segundos, imprimindo
`201` e chamando aquilo de resultado.

Agora o script tem uma **sonda**: uma cobrança direta no gateway, antes de
qualquer medição, cujo status tem que ser exatamente o esperado do modo. Se não
for, aborta. Instrumento que não prova que ligou o caos produz resultado bonito
e errado.

(A sonda conta como tentativa no `/_stats`, então o script chama `/_reset`
depois dela.)

### 4. `attempts` da dead letter está um a menos

`failed_jobs.payload` guarda o payload da última vez que o job foi **devolvido
para a fila** — a tentativa que o matou não está contada ali. Com `$tries = 4`,
a tabela mostra `3`.

O número real está no log, na linha `notification.dead_letter`, que o
`failed()` escreve com `$this->attempts()`. E ele só funciona porque o
framework passa o job para o `failed()` (`Jobs\Job::failed` → 4º argumento →
`setJobInstanceIfNecessary`).

### 5. O caminho de reportar erro passou a depender do Redis

`$exceptions->throttle()` usa o `RateLimiter`, que usa o cache, que aqui é o
Redis. Derrubar o Redis (Fase 6) agora afeta não só a fila e os locks, mas
também o que acontece **enquanto** uma exceção está sendo reportada.

Vale o preço — sem `throttle`, dois minutos de gateway fora a 50 req/s são 6.000
exceções idênticas reportadas, e a cota do Sentry estourada esconde o erro
diferente que apareceu no meio. Mas é uma dependência nova em um caminho que
precisa funcionar justamente quando as coisas estão ruins.

Efeito colateral: linha de log descartada é linha que não existe. Sob carga,
não procure "todas" as ocorrências — procure a **taxa**. Isso é métrica, não
log, e é a Fase 5.

### 6. O orçamento de retry tem um teto, e ele não é seu

```
pior caso = tries x timeout + soma dos backoffs = 3 x 3s + ~0,3s = ~9,3s
```

O nginx deste projeto corta em `fastcgi_read_timeout 60s`
(`docker/nginx/default.conf:64`). Se o orçamento passar disso, o nginx devolve
um 504 **em HTML**: o Problem Details nunca é montado e o cliente recebe uma
página de erro. Retry que não cabe no tempo de quem te chama não existe — ele
só consome worker de php-fpm e atrasa todo mundo.

Teste de propósito: `GATEWAY_TIMEOUT_RATE=1` com
`GATEWAY_TIMEOUT_SLEEP_MS=30000` e `GATEWAY_HTTP_TRIES=3`.

## Decisões que valem registrar

### Recusa é 402, não 201

Os dois se defendiam: o 201 porque o **pedido** foi criado (pagamento negado é
um estado do recurso), o 402 porque a **intenção** do cliente não se realizou.

Ganhou o 402 porque o modo de falhar dele é barulhento — o cliente vê um erro
que talvez não considere erro — e o do 201 é silencioso: quem olha só o código
HTTP acha que vendeu. Entre duas opções defensáveis, escolha a que falha alto.

Custo: é mudança de contrato, e a spec mudou no mesmo commit.

### 504 não leva `Retry-After`, e isso é o ponto

`Retry-After` é um convite a repetir, e repetir é exatamente o que pode cobrar
duas vezes enquanto a idempotência não existe. Então a resposta indeterminada
entrega outra coisa: o `instance`, apontando para `GET /orders/{id}`. Em vez de
"tente de novo", ela diz "descubra o que aconteceu".

O inverso também é verdade: o 503 **pode** convidar, porque ali há prova de que
nada foi cobrado.

### `unknown` é estado no banco, não só status HTTP

Guardar o indeterminado como `failed` faz alguém escrever, um dia, "reprocessa
todos os failed" — e cobrar de novo quem já foi cobrado. Guardar como `pending`
o torna indistinguível de um pedido que nem tentou.

`unknown` é a fila de reconciliação do projeto: a Fase 3 resolve esses pedidos
consultando o gateway ou recebendo o webhook.

### O trace nunca vai para o cliente; em debug sai um resumo

Três caminhos existiam: (a) nunca expor nada, (b) expor um resumo quando
`APP_DEBUG`, (c) confiar que produção tem `APP_DEBUG=false`. A escolha foi (b):
o trace — que tem caminhos de arquivo e argumentos — nunca sai; em debug sai o
membro `debug` com classe, mensagem e `arquivo:linha`.

(c) é o que quase todo mundo faz, e é o que vaza quando alguém esquece uma env.
E nem (b) é inofensivo: `getMessage()` de uma exceção de banco pode trazer
credencial. Em produção, nada disso sai.

### A validação virou Problem, mas manteve `errors`

A RFC permite membros de extensão (§3.2). Jogar a lista de campos fora para
"seguir a RFC" pioraria a vida do cliente, e a RFC existe para melhorar.

### `GATEWAY_FAIL_STATUS` no gateway fake

O gateway só sabia falhar com `500`. Mas `500` e `503` exigem decisões
**opostas** de quem chama, e sem o `503` não havia como observar o retry
acontecendo. Três linhas no gateway fake, e o cenário mais importante da fase
passou a ser reproduzível.

### Uma linha de log de negócio **e** o report da exceção

O report tem a mensagem e o trace; a linha de negócio
(`order.payment_unconfirmed`) tem nome de evento, que é por onde se busca e se
agrega. As duas existem, com o mesmo nível, de propósito.

## O que ficou como TODO de propósito

- **`Retry-After` com jitter.** Valor fixo sincroniza todos os clientes no
  mesmo segundo: a avalanche volta, agora vinda deles.
- **Jitter no `backoff()` do job.** Mil jobs que falharam pelo mesmo banco fora
  voltam no mesmo segundo. Ficou fixo para o teste observável ser previsível.
- **Honrar o `Retry-After` do gateway** no 429/503, em vez do nosso backoff.
- **Circuit breaker.** Retry sozinho não protege um dependente que já caiu:
  continua batendo nele a cada requisição nova. Com 2 minutos de queda e
  tráfego real, o certo é parar de tentar por um tempo.
- **Reconciliação dos `unknown`.** Hoje eles ficam lá. É Fase 3.
- **Alerta de DLQ.** `failed_jobs` é uma dead letter queue, mas **quem olha
  essa tabela, e quando?** Job que falha 4 vezes e ninguém olha é igual a job
  perdido, só com mais disco usado. Fase 5: "tamanho da DLQ > 0 por 5 minutos".
- **O webhook.** `PaymentWebhookController` não foi tocado nesta fase; ele
  ganha Problem Details pela validação, e o resto é Fase 3.

## Critério de "entendi a Fase 2"

Sem consultar:

1. A diferença entre um erro que se **reporta** e um que se apenas
   **responde** — e um exemplo de cada que responde 4xx.
2. Por que `503` leva `Retry-After` e `504` não.
3. Por que o estado indeterminado precisa existir **no banco**, e não só na
   resposta HTTP.
4. O que o `fastcgi_read_timeout` do nginx tem a ver com o número de
   tentativas do cliente HTTP.
5. Por que retry sem idempotência é perigoso — e qual linha da tabela de
   decisão vira "sim" quando a Fase 3 existir.
6. Por que recusa de cartão no log de erro quebra o alerta da Fase 5.
