<?php

use App\Exceptions\GatewayUnavailable;
use App\Exceptions\PaymentDeclined;
use App\Exceptions\PaymentUnconfirmed;
use App\Exceptions\Problem;
use App\Exceptions\ProvidesProblem;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // apiPrefix vazio: as rotas ficam em POST /orders, e não /api/orders,
        // como o roteiro do projeto pede. O grupo `api` continua valendo
        // (sem sessão, sem CSRF, erros em JSON).
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // FASE 1 — já ligado, o corpo do middleware é que está vazio.
        //
        // prepend() e não appendToGroup(): global, porque um 500 que estoura
        // antes do roteamento também precisa de id; e primeiro da fila, para
        // que tudo que logar depois já enxergue o contexto.
        $middleware->prepend(\App\Http\Middleware\RequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // =====================================================================
        // FASE 2 — A POLÍTICA DE ERRO DO PROJETO MORA AQUI
        //
        // Este arquivo responde CINCO perguntas INDEPENDENTES. A confusão mais
        // comum em tratamento de erro é achar que são uma só:
        //
        //   1. isto é incidente?        -> dontReport / report / throttle
        //   2. o que o cliente vê?      -> render / respond
        //   3. com que gravidade loga?  -> level
        //   4. o job deve insistir?     -> dontRetry
        //   5. o que acompanha o log?   -> context
        //
        // O mapa desta API depois da Fase 2:
        //
        //   exceção               responde  reporta?  nível      retenta job?
        //   --------------------  --------  --------  ---------  ------------
        //   ValidationException   422       não       —          não
        //   PaymentDeclined       402       NÃO       —          não
        //   GatewayUnavailable    503       sim       warning    sim
        //   PaymentUnconfirmed    504       sim       critical   NÃO
        //   qualquer outra        500       sim       error      sim
        //
        // Leia a coluna do meio: `PaymentDeclined` responde um 4xx e NÃO é
        // incidente. `GatewayUnavailable` responde 503 E é incidente.
        // Responder e reportar são eixos separados — é a lição da fase.
        // ---------------------------------------------------------------------

        // Sem o prefixo /api, o Laravel só devolve JSON quando o cliente pede
        // (Accept: application/json). O gateway fake não pede, então listamos
        // as rotas que são sempre JSON.
        //
        // A closure fica numa variável porque é usada TRÊS vezes: aqui, no
        // render da validação e no funil do respond. Sem isso, um formulário
        // web (que espera redirect com os erros na sessão) receberia um
        // Problem Details na cara.
        $sempreJson = fn (Request $request): bool => $request->expectsJson()
            || $request->is('api/*', 'orders', 'orders/*', 'webhooks/*');

        $exceptions->shouldRenderJsonWhen($sempreJson);

        // --- 1. o que NÃO é incidente ----------------------------------------
        //
        // `dontReport` = não escreve no log de erro, não vai para o Sentry, não
        // conta na taxa de erro, não acorda ninguém. Continua respondendo ao
        // cliente normalmente (402, com o Problem Details inteiro).
        //
        // O Laravel já ignora um punhado por padrão (ValidationException,
        // AuthenticationException, HttpException, ModelNotFoundException...) —
        // veja `Handler::$internalDontReport`. O 422 e o 404 desta API JÁ não
        // eram reportados; o que precisa ser declarado é o que é NOSSO.
        //
        // A pergunta que separa os dois lados é só uma: "alguém precisa
        // acordar de madrugada por causa disto?" Recusa de cartão com 5% de
        // taxa num dia de promoção não pode ser erro no log, senão o alerta da
        // Fase 5 dispara por causa do sistema funcionando exatamente como
        // deveria. É assim que se treina um time a ignorar alerta.
        //
        // O inverso existe: `stopIgnoring(HttpException::class)` se um dia
        // você QUISER ver os 404 (suspeita de varredura, por exemplo).
        $exceptions->dontReport([
            PaymentDeclined::class,
        ]);

        // --- 2. a gravidade do log -------------------------------------------
        //
        // Mesmo evento, gravidades diferentes, e o critério é o DANO — não a
        // causa técnica:
        //
        //   GatewayUnavailable  warning   o cliente comeu um 503, mas nada foi
        //                                 cobrado e repetir resolve. Dói, não
        //                                 sangra.
        //   PaymentUnconfirmed  critical  pode haver dinheiro cobrado sem
        //                                 pedido pago. Isto é dívida com o
        //                                 cliente até alguém reconciliar.
        //
        // É por isso que `level()` existe separado de `report()`: as duas são
        // reportadas, e só uma merece telefone tocando.
        $exceptions->level(GatewayUnavailable::class, LogLevel::WARNING);
        $exceptions->level(PaymentUnconfirmed::class, LogLevel::CRITICAL);

        // --- 3. a avalanche de reports (o que ninguém lembra) -----------------
        //
        // O teste observável desta fase é "gateway fora por 2 minutos". A 50
        // req/s, isso são 6.000 exceções IDÊNTICAS reportadas. Com Sentry, você
        // estourou a cota e perdeu o erro DIFERENTE que apareceu no meio.
        //
        // `throttle` mantém uma amostra e joga o resto fora. 30/min é o
        // suficiente para você ver que está acontecendo e para o padrão
        // aparecer no gráfico da Fase 5.
        //
        // Dois efeitos colaterais que valem saber:
        //   a) linha de log descartada é linha que não existe. Sob carga alta,
        //      não procure "todas" as ocorrências: procure a taxa (é métrica,
        //      não log — e é por isso que a Fase 5 existe).
        //   b) `throttle` usa o RateLimiter, que usa o cache, que aqui é o
        //      Redis. Ou seja: o caminho de reportar erro passou a depender do
        //      Redis. Derrubar o Redis no meio do caos (Fase 6) testa isso.
        //
        // `dontReportDuplicates()` é a versão grosseira: só o primeiro de cada
        // tipo, por requisição.
        $exceptions->throttle(fn (Throwable $e) => match (true) {
            $e instanceof GatewayUnavailable => Limit::perMinute(30),
            // PaymentUnconfirmed NÃO entra aqui de propósito: cada ocorrência
            // é um pedido em estado desconhecido, e perder uma é perder um
            // pedido. Nunca jogue fora o log do que custa dinheiro.
            default => null,
        });

        // --- 4. o job deve insistir? -----------------------------------------
        //
        // Este é o eixo da FILA: a exceção estoura dentro de um job e o worker
        // falha o job NA HORA, sem gastar as tentativas do `$tries`.
        //
        //   PaymentDeclined     retentar 4 vezes uma recusa de cartão são 4
        //                       chamadas inúteis ao gateway e 3 linhas de
        //                       ruído. O resultado não vai mudar.
        //   PaymentUnconfirmed  aqui é mais sério: retentar é justamente o que
        //                       pode cobrar duas vezes. O caso indeterminado
        //                       NÃO se resolve insistindo; se resolve
        //                       consultando. Enquanto a Fase 3 não existir,
        //                       esta linha é a proteção.
        //
        // `GatewayUnavailable` fica fora: é exatamente o caso em que insistir
        // é seguro e resolve.
        $exceptions->dontRetry([
            PaymentDeclined::class,
            PaymentUnconfirmed::class,
        ]);

        // --- 5. o contexto que acompanha o log --------------------------------
        //
        // Não há `context()` aqui, e é de propósito: a Fase 1 já resolveu. O
        // `ContextLogProcessor` injeta `request_id` e `order_id` em TODA linha,
        // inclusive na que o handler de exceção escreve. `$exceptions->context(
        // fn () => [...])` serve para dado que só faz sentido em erro — se um
        // dia houver, entra aqui.

        // --- 6. o que o cliente vê: as exceções de domínio -------------------
        //
        // Um handler só, para todas, porque elas implementam a interface. O
        // `render` casa por `is_a`, então interface funciona e toda exceção de
        // domínio NOVA já nasce renderizada sem tocar neste arquivo.
        $exceptions->render(
            fn (ProvidesProblem $e, Request $request) => $e->problem()->toResponse($request),
        );

        // --- 7. o que o cliente vê: a validação ------------------------------
        //
        // O 422 do FormRequest vinha no formato do Laravel (`message` +
        // `errors`), que é um quarto formato de erro. Aqui ele vira Problem
        // Details SEM perder os campos: `errors` entra como membro de extensão
        // (RFC 9457 §3.2), porque jogar a lista de campos fora para "seguir a
        // RFC" pioraria a vida do cliente — e a RFC existe para melhorar.
        //
        // Devolver `null` deixa o Laravel seguir com o comportamento padrão:
        // numa rota web, o redirect com os erros na sessão.
        $exceptions->render(function (ValidationException $e, Request $request) use ($sempreJson) {
            if (! $sempreJson($request)) {
                return null;
            }

            return Problem::fromStatus(
                status: 422,
                detail: 'Um ou mais campos não passaram na validação. Veja `errors`.',
                type: '/problems/validation',
            )
                ->with('errors', $e->errors())
                ->toResponse($request);
        });

        // --- 8. O FUNIL: tudo o que sobrou -----------------------------------
        //
        // Os itens 6 e 7 cobrem o que é nosso. Mas o 404 do route binding, o
        // 405, o 500 de um bug e o 429 do rate limiter continuariam cada um no
        // seu formato — e aí a API volta a ter quatro formatos de erro, que é
        // de onde esta fase partiu.
        //
        // `respond()` recebe a resposta JÁ RENDERIZADA, depois de todo o resto,
        // e é o último lugar onde dá para garantir UMA cara só. Por isso ele
        // não decide nada de negócio: só traduz.
        //
        // A DECISÃO DIFÍCIL, e ela está aqui: com `APP_DEBUG=true` o Laravel
        // devolve `exception`, `file`, `line` e o TRACE INTEIRO no corpo.
        // Ótimo para você, vazamento de informação para o cliente. Três
        // caminhos:
        //   a) nunca expor: o trace fica só no log e o cliente leva o
        //      `request_id` para abrir chamado
        //   b) expor um resumo só quando APP_DEBUG, como membro de extensão
        //   c) deixar como está e confiar que produção tem APP_DEBUG=false
        //
        // (c) é o que quase todo mundo faz, e é o que vaza quando alguém
        // esquece uma env. Escolhi (b): o trace NUNCA sai (é o que tem os
        // caminhos de arquivo e os argumentos), e em debug sai um resumo de
        // três campos — o bastante para você não precisar abrir o log a cada
        // tentativa. Note que nem (b) é inofensivo: `getMessage()` de uma
        // exceção de banco pode trazer credencial. Em produção, com
        // APP_DEBUG=false, nada disso sai.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) use ($sempreJson) {
            // Rota não-JSON (formulário, página de erro do Laravel): não é
            // nosso cliente de API, não mexe.
            if (! $sempreJson($request) || $response->getStatusCode() < 400) {
                return $response;
            }

            // Já é Problem Details: veio dos itens 6 ou 7. Não reescreve, ou
            // o `Retry-After` e os membros de extensão seriam perdidos.
            if ($response->headers->get('Content-Type') === 'application/problem+json') {
                return $response;
            }

            $status = $response->getStatusCode();

            if ($status < 500) {
                // 404, 405, 415, 429: o status já diz tudo. `about:blank` é o
                // `type` correto nesse caso (RFC 9457 §4.2.1), e o detalhe
                // fica de fora para não vazar nome de model do Eloquent.
                return Problem::fromStatus($status)->toResponse($request);
            }

            return Problem::fromStatus(
                status: $status,
                detail: 'Erro inesperado do nosso lado. Nada foi perdido do seu '
                    . 'pedido se ele já existe; informe o `request_id` para '
                    . 'investigarmos.',
                type: '/problems/internal',
            )
                ->with('debug', config('app.debug') ? [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'at' => basename($e->getFile()) . ':' . $e->getLine(),
                ] : null)
                ->toResponse($request);
        });
        // =====================================================================
    })->create();
