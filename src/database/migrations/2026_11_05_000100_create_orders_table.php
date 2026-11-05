<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 3)->default('BRL');
            $table->string('customer_email');

            // Id da cobrança no gateway. Nulo até o pagamento ser tentado.
            $table->string('payment_id')->nullable();
            $table->string('decline_reason')->nullable();

            $table->timestamp('paid_at')->nullable();

            // Marca que a notificação já foi enviada. Na Fase 3 é um dos
            // caminhos para o job idempotente: só notifica quem está NULL,
            // num UPDATE condicional (e não com um `if` antes do envio).
            $table->timestamp('notified_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });

        // ------------------------------------------------------------------
        // FASE 3 — o que ainda não existe aqui, de propósito:
        //
        // 1) tabela `idempotency_keys`
        //      key (string, UNIQUE), request_hash, status, response_code,
        //      response_body (jsonb), order_id, locked_at, expires_at
        //    O UNIQUE é o que faz a garantia. Sem ele, duas requisições
        //    simultâneas passam as duas pelo `select` antes de qualquer
        //    `insert` acontecer.
        //
        // 2) tabela `webhook_events`
        //      event_id (string, UNIQUE), type, payload (jsonb), processed_at
        //    O gateway reenvia o MESMO event_id. Quem rejeita a segunda
        //    entrega é o banco (violação de UNIQUE), não um `if (já existe)`.
        //
        // 3) possivelmente um UNIQUE parcial em orders:
        //      "um pagamento aprovado por pedido"
        //    para a regra não depender só do código da aplicação.
        // ------------------------------------------------------------------
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
