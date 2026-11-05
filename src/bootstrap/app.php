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
        // FASE 1: aqui entra o middleware de X-Request-Id, aplicado ao grupo
        // inteiro — ele tem que rodar ANTES de qualquer coisa que logue.
        //   $middleware->prepend(\App\Http\Middleware\RequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sem o prefixo /api, o Laravel só devolve JSON quando o cliente pede
        // (Accept: application/json). O gateway fake não pede, então listamos
        // as rotas que são sempre JSON.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->expectsJson()
                || $request->is('api/*', 'orders', 'orders/*', 'webhooks/*'),
        );

        // --------------------------------------------------------------
        // FASE 2 — é AQUI que o tratamento de erros mora:
        //
        //   $exceptions->dontReport([
        //       PaymentDeclined::class,     // recusa é resposta, não incidente
        //   ]);
        //
        //   $exceptions->render(function (PaymentDeclined $e, Request $r) {
        //       return response()->json([...], 402);   // RFC 9457
        //   });
        //
        //   $exceptions->report(function (GatewayUnavailable $e) { ... });
        //
        // A pergunta que separa os dois casos: "alguém precisa acordar de
        // madrugada por causa disto?" Se não, é `dontReport` + render.
        // --------------------------------------------------------------
    })->create();
