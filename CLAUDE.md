# Mini Checkout: projeto de estudo em Laravel

## Contexto

Este é um projeto **exclusivamente de estudo**. O objetivo não é ter um produto, e sim aprender na prática, "de uma vez por todas", quatro conceitos:

1. **Observabilidade**: logs estruturados, métricas, dashboards e alertas
2. **Rastreabilidade**: correlation IDs e tracing distribuído (OpenTelemetry)
3. **Tratamento de erros**: erros esperados vs inesperados, retries, timeouts, dead letter
4. **Idempotência**: requisições, webhooks e jobs que podem rodar mais de uma vez sem efeito duplicado

O domínio é propositalmente pequeno. A complexidade deve estar nas fronteiras (HTTP de entrada, banco, HTTP de saída, fila, webhook), que é onde esses conceitos importam.

## Como trabalhar comigo (importante)

- **Eu quero implementar os conceitos.** Você monta infraestrutura e esqueleto, explica e revisa. A lógica de cada conceito estudado deve ficar como `TODO` com uma explicação do que fazer, a menos que eu peça a implementação.
- Antes de cada fase, explique o conceito em poucas linhas: o problema que ele resolve e o que acontece sem ele.
- Ao revisar meu código, aponte o que falta, os casos de borda e as condições de corrida. Não reescreva tudo.
- Prefira recursos nativos do Laravel antes de pacotes externos, e diga quando um pacote é realmente necessário.
- Cada fase termina com um **teste observável**: algo que eu rodo e vejo acontecer (no log, no trace, no dashboard ou no banco).
- Responda em português.

## Domínio: Mini Checkout

Fluxo único:

1. `POST /orders` cria um pedido. O header `Idempotency-Key` é obrigatório.
2. O pedido chama um **gateway de pagamento fake**, que é uma rota ou um container separado sob nosso controle e que falha de propósito: latência, erro 500, timeout, resposta duplicada. As falhas são configuráveis por variáveis de ambiente.
3. Se o pagamento for aprovado, dispara um **job na fila** para "enviar notificação".
4. O gateway fake envia um **webhook** de confirmação, às vezes duplicado e às vezes fora de ordem.

## Stack

Tudo em Docker Compose:

- Laravel (versão atual) com PHP-FPM ou `php artisan serve`
- Postgres ou MySQL
- Redis, para fila, cache e locks
- Worker de fila (`queue:work`) em container próprio
- Gateway fake (container próprio ou rota isolada)
- `grafana/otel-lgtm`: OpenTelemetry Collector, Grafana, Loki (logs), Tempo (traces) e Prometheus/Mimir (métricas) numa imagem só
- k6 (ou um script) para testes de carga e concorrência

## Roteiro por fases

Uma fase por vez. Só avançamos quando o conceito estiver **visível** funcionando.

### Fase 0: Setup
- Docker Compose com toda a stack subindo
- Projeto Laravel com o domínio mínimo (model `Order`, migration, rota `POST /orders`)
- Gateway fake com falhas configuráveis

### Fase 1: Logs estruturados e correlação
- Logs em JSON (formatter do Monolog em `config/logging.php`)
- Middleware que lê ou gera `X-Request-Id` e o devolve no response
- Facade `Context` anexando `request_id` e `order_id`, propagada automaticamente para os jobs
- **Teste:** dado um `request_id`, encontrar todos os logs da requisição *e* do job que ela disparou

### Fase 2: Tratamento de erros
- Separar erros esperados (pagamento recusado, validação) de inesperados (bug, banco fora)
- Exceções de domínio e configuração central em `bootstrap/app.php` com `withExceptions` (`report` vs `render`, `dontReport`)
- Respostas de erro no formato Problem Details (RFC 9457)
- `Http::timeout()` e `retry()` para o gateway: retentar timeout e 503, nunca 400
- Jobs com `$tries`, `backoff` exponencial e `failed()`; `failed_jobs` como dead letter queue
- **Teste:** gateway fora por 2 minutos. O que o cliente vê, o que o log mostra, o que acontece com os jobs?

### Fase 3: Idempotência (o coração do projeto)
- Middleware de `Idempotency-Key` com tabela `idempotency_keys` (chave, hash do payload, status, response salvo)
- Mesma chave e mesmo payload: devolve a resposta salva sem reprocessar
- Mesma chave e payload diferente: `422`
- Mesma chave ainda em processamento: `409`, com `Cache::lock` para a concorrência
- Webhook idempotente: `UNIQUE` no `event_id`, garantido pelo banco e não por um `if`
- Job idempotente: rodar duas vezes não notifica duas vezes (`ShouldBeUnique`, ou checagem de estado mais constraint)
- **Teste:** 20 requisições simultâneas com a mesma chave devem gerar exatamente 1 pedido e 1 cobrança

### Fase 4: Rastreabilidade (tracing distribuído)
- `ext-opentelemetry` e `open-telemetry/opentelemetry-auto-laravel`
- Spans manuais de negócio (`checkout.charge`, `checkout.notify`)
- Propagação do `traceparent` (W3C) até o gateway fake e até o job
- `trace_id` nos logs, para pular do log ao trace no Grafana
- **Teste:** um único trace no Tempo mostrando HTTP → SQL → gateway → retry → job, com os tempos de cada etapa

### Fase 5: Métricas e alertas
- Métricas RED: taxa de requisições, taxa de erro, latência (p50/p95/p99)
- Métricas de negócio: pedidos criados, pagamentos recusados, replays de idempotência
- Dashboard no Grafana e um alerta (erro acima de 5% por 5 minutos)
- **Teste:** só pelo dashboard, perceber que o gateway degradou antes de olhar qualquer log

### Fase 6: Caos
Ligar falhas e investigar usando só as ferramentas de observabilidade:
- Gateway com 3s de latência
- Redis derrubado no meio do teste
- Webhook enviado 3 vezes
- Worker morto com jobs em andamento
- Deploy no meio de uma requisição

## Critério de "aprendi"

No fim, eu devo conseguir explicar sem consultar:
- A diferença entre log, métrica e trace, e qual usar para cada pergunta
- Por que retry sem idempotência é perigoso
- Por que idempotência precisa de constraint no banco, e não só de um `if`
- Como um `trace_id` atravessa um processo assíncrono (fila)
- A diferença entre um erro que se *reporta* e um que se apenas *responde*

## Primeiro passo

Começar pela **Fase 0**: proponha a estrutura de pastas e o `docker-compose.yml`, explique cada serviço e só então gere os arquivos.
