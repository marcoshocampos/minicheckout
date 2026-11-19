<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
        // Sem o prefixo /api, o Laravel só devolve JSON quando o cliente pede
        // (Accept: application/json). O gateway fake não pede, então listamos
        // as rotas que são sempre JSON.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->expectsJson()
                || $request->is('api/*', 'orders', 'orders/*', 'webhooks/*'),
        );

        // =====================================================================
        // FASE 2 — A POLÍTICA DE ERRO DO PROJETO MORA AQUI
        //
        // Este arquivo responde CINCO perguntas independentes. A confusão mais
        // comum em tratamento de erro é achar que são uma só:
        //
        //   1. isto é incidente?        -> dontReport / report / throttle
        //   2. o que o cliente vê?      -> render / respond
        //   3. com que gravidade loga?  -> level
        //   4. o job deve insistir?     -> dontRetry
        //   5. o que acompanha o log?   -> context
        //
        // Um 422 de validação: não é incidente (1), o cliente vê a lista de
        // campos (2). Um gateway fora: É incidente (1) E o cliente vê um 503
        // limpo (2). Responder e reportar são eixos separados.
        //
        // As exceções de domínio já existem em app/Exceptions/ e sabem se
        // apresentar (ProvidesProblem::problem()). Falta: a política abaixo, e
        // alguém LANÇAR as exceções (OrderController, PaymentGateway).
        // ---------------------------------------------------------------------

        // --- 1. o que NÃO é incidente ----------------------------------------
        //
        // `dontReport` = não escreve no log de erro, não vai para o Sentry, não
        // conta na taxa de erro, não acorda ninguém. Continua respondendo ao
        // cliente normalmente.
        //
        // O Laravel já ignora um punhado por padrão (ValidationException,
        // AuthenticationException, HttpException, ModelNotFoundException...) —
        // veja `Handler::$internalDontReport`. Ou seja: o 422 e o 404 desta API
        // JÁ não são reportados. Declarar aqui é documentar a intenção; o que
        // você precisa acrescentar é o que é SEU:
        //
        //   $exceptions->dontReport([
        //       \App\Exceptions\PaymentDeclined::class,
        //   ]);
        //
        // Pergunta que separa os dois lados, e é a única que importa: "alguém
        // precisa acordar de madrugada por causa disto?" Se a resposta é não, é
        // dontReport. Recusa de cartão com 5% de taxa num dia de promoção não
        // pode ser erro no log, senão o alerta da Fase 5 dispara por causa do
        // sistema funcionando.
        //
        // O inverso também existe: `stopIgnoring(HttpException::class)` se um
        // dia você QUISER ver os 404 (ex. suspeita de varredura).

        // --- 2. o que o cliente vê -------------------------------------------
        //
        // Um handler só, porque as exceções implementam a interface:
        //
        //   $exceptions->render(
        //       fn (\App\Exceptions\ProvidesProblem $e, Request $request)
        //           => $e->problem()->toResponse($request),
        //   );
        //
        // O `render` casa por `is_a`, então interface funciona e toda exceção
        // de domínio nova já entra renderizada sem mexer aqui.
        //
        // Mas isso cobre só o que é NOSSO. O 422 do FormRequest, o 404 do
        // route binding, o 405, o 500 de um bug — esses continuam no formato
        // do Laravel, e aí a API volta a ter três formatos de erro. O funil
        // final é o `respond`, que recebe a resposta JÁ renderizada e pode
        // reescrevê-la:
        //
        //   $exceptions->respond(function (
        //       \Symfony\Component\HttpFoundation\Response $response,
        //       Throwable $e,
        //       Request $request,
        //   ) {
        //       // TODO: se já é problem+json, devolva como está.
        //       // TODO: traduza ValidationException para um problem com
        //       //       membro de extensão `errors` (a RFC permite extensão,
        //       //       e jogar os campos fora pioraria a vida do cliente).
        //       // TODO: traduza HttpException (404/405/429) usando
        //       //       $response->getStatusCode().
        //       // TODO: o resto é 500 — title genérico, SEM detalhe interno.
        //       return $response;
        //   });
        //
        // A decisão difícil do 500: com APP_DEBUG=true o Laravel devolve
        // `exception`, `file`, `line` e o trace inteiro. Isso é ótimo para
        // você e é vazamento de informação para o cliente. Três caminhos:
        //   a) nunca expor: o trace fica só no log, e o cliente recebe o
        //      `request_id` para abrir chamado (é a graça da Fase 1)
        //   b) expor só quando APP_DEBUG, como membro de extensão
        //   c) deixar como está e confiar que produção tem APP_DEBUG=false
        // (c) é o que quase todo mundo faz, e é o que vaza quando alguém
        // esquece uma env.

        // --- 3. a gravidade do log -------------------------------------------
        //
        //   $exceptions->level(
        //       \App\Exceptions\GatewayUnavailable::class,
        //       \Psr\Log\LogLevel::WARNING,
        //   );
        //
        // "Gateway caiu" é error ou warning? Depende de quem liga para isso: se
        // o retry resolveu e o cliente nem viu, warning basta. Se o cliente
        // comeu um 503, é error. O mesmo evento tem gravidades diferentes
        // dependendo de ter sido absorvido ou não — e é por isso que `level`
        // existe separado de `report`.

        // --- 4. o job deve insistir? -----------------------------------------
        //
        //   $exceptions->dontRetry([
        //       \App\Exceptions\PaymentDeclined::class,
        //   ]);
        //
        // Isto é do lado da FILA: a exceção estoura dentro de um job e o worker
        // falha o job na hora, sem gastar as 5 tentativas. Faz todo sentido
        // para erro que não muda de resultado: 5 tentativas de uma recusa de
        // cartão são 5 chamadas inúteis ao gateway e 4 linhas de ruído.

        // --- 5. o contexto que acompanha o log --------------------------------
        //
        //   $exceptions->context(fn () => ['order_id' => Context::get('order_id')]);
        //
        // Na prática a Fase 1 já resolveu isto: o ContextLogProcessor injeta o
        // Context em TODA linha, inclusive na do handler de exceção. O
        // `context()` aqui é para dado que só faz sentido em erro.

        // --- e o que ninguém lembra: a avalanche de reports -------------------
        //
        //   $exceptions->throttle(fn (Throwable $e) => $e instanceof
        //       \App\Exceptions\GatewayUnavailable
        //           ? \Illuminate\Cache\RateLimiting\Limit::perMinute(10)
        //           : null);
        //
        // O teste observável desta fase é "gateway fora por 2 minutos". Com 50
        // req/s, isso são 6.000 exceções IDÊNTICAS reportadas. Se houver
        // Sentry, você estourou a cota e perdeu o erro diferente que apareceu
        // no meio. `throttle` com Limit::perMinute() mantém a amostra e joga o
        // resto fora; `dontReportDuplicates()` é a versão grosseira (só o
        // primeiro de cada tipo, por requisição).
        //
        // Isto é observabilidade sobre observabilidade: o seu tratamento de
        // erro também tem limite de capacidade.
        // =====================================================================
    })->create();
