<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * O gateway respondeu, e a resposta foi "não".
 *
 * Esta é a cara de um erro ESPERADO:
 *   - acontece no caminho normal do negócio (cartão sem limite existe)
 *   - retentar não muda o resultado
 *   - ninguém precisa acordar de madrugada por causa dela
 *   - o cliente precisa de uma resposta clara, não de um incidente
 *
 * Em bootstrap/app.php ela vai para o `dontReport`. Se ficar fora, um dia de
 * promoção com 5% de recusa enche o log de erro e o alerta de "taxa de erro
 * acima de 5%" dispara por causa do sistema funcionando exatamente como
 * deveria. É assim que se treina um time a ignorar alerta.
 */
class PaymentDeclined extends RuntimeException implements ProvidesProblem
{
    public function __construct(
        public readonly string $orderId,
        public readonly ?string $declineCode = null,
        public readonly ?string $paymentId = null,
    ) {
        // A mensagem da exceção é para o LOG e para o `failed_jobs`, nunca
        // para o cliente — quem fala com o cliente é o problem() abaixo.
        parent::__construct("pagamento recusado: {$declineCode}");
    }

    public function problem(): Problem
    {
        // ------------------------------------------------------------------
        // FASE 2 — DECISÃO TOMADA: 402, e não 201 com `status: declined`.
        //
        // Os dois se defendiam:
        //
        //   201  o PEDIDO foi criado com sucesso; pagamento negado é um
        //        estado do recurso, não uma falha da requisição. Mas premia o
        //        cliente preguiçoso com a resposta errada: quem olha só o
        //        código HTTP conclui que deu certo.
        //   402  a INTENÇÃO do cliente (pagar) não se realizou. Quem trata
        //        4xx como erro acerta de graça. Custo: é status pouco usado,
        //        e existe proxy que trata mal.
        //
        // Ganhou o 402 porque o modo de falhar dele é barulhento (o cliente
        // vê um erro que não era erro) e o do 201 é silencioso (o cliente
        // acha que vendeu). Entre as duas, escolha sempre a que falha alto.
        //
        // O `instance` aponta para o pedido: o recurso existe, está
        // consultável, e é lá que está o `decline_reason` persistido.
        // ------------------------------------------------------------------
        return (new Problem(
            status: 402,
            title: 'Pagamento recusado',
            type: '/problems/payment-declined',
            detail: 'O gateway recusou a cobrança. O pedido foi criado e está '
                . 'com status `declined`. Repetir a mesma requisição não muda '
                . 'o resultado: use outro meio de pagamento.',
            instance: "/orders/{$this->orderId}",
        ))
            ->with('order_id', $this->orderId)
            // `decline_code` é o campo que o cliente usa para decidir o que
            // dizer ao usuário final ("sem limite" vs "cartão expirado").
            ->with('decline_code', $this->declineCode)
            ->with('payment_id', $this->paymentId);
    }
}
