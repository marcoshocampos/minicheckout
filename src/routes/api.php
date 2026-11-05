<?php

use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Middleware\Idempotency;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas de API
|--------------------------------------------------------------------------
| Registrado em bootstrap/app.php com apiPrefix: '' — então as rotas são
| POST /orders e não POST /api/orders.
|
| Por que api.php e não web.php: aqui não tem sessão nem CSRF, e os erros já
| voltam em JSON. Webhook em rota com CSRF seria 419 na cara do gateway.
*/

Route::post('/orders', [OrderController::class, 'store'])
    ->middleware(Idempotency::class);

Route::get('/orders/{order}', [OrderController::class, 'show']);

// O gateway fake chama esta rota. Pode chamar várias vezes, com o mesmo
// event_id e fora de ordem: é o trabalho da Fase 3 sobreviver a isso.
Route::post('/webhooks/payments', PaymentWebhookController::class);
