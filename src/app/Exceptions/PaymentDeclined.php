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
        // FASE 2 — DECISÃO SUA, e é decisão de CONTRATO (mexe na spec):
        //
        // 402 aqui, ou 201 com `status: declined` no corpo (como está hoje no
        // OrderController)? Os dois se defendem:
        //
        //   201  o PEDIDO foi criado com sucesso; pagamento negado é um
        //        estado do recurso, não uma falha da requisição. Cliente
        //        preguiçoso, que só olha o status HTTP, vai achar que deu
        //        certo.
        //   402  a INTENÇÃO do cliente (pagar) não se realizou. Cliente que
        //        trata 4xx como erro acerta de graça. Mas 402 é status pouco
        //        usado e há proxy que trata mal.
        //
        // Se escolher 201, esta exceção não precisa ser lançada: fica aqui só
        // como documentação do que é erro esperado. Escolher é obrigatório;
        // ficar com as duas coisas ao mesmo tempo, não.
        // ------------------------------------------------------------------
        return (new Problem(
            status: 402,
            title: 'Pagamento recusado',
            type: '/problems/payment-declined',
            detail: 'O gateway recusou a cobrança. O pedido foi criado e está '
                . 'com status `declined`. Repetir a mesma requisição não muda '
                . 'o resultado: use outro meio de pagamento.',
        ))
            ->with('order_id', $this->orderId)
            // `decline_code` é o campo que o cliente usa para decidir o que
            // dizer ao usuário final ("sem limite" vs "cartão expirado").
            ->with('decline_code', $this->declineCode)
            ->with('payment_id', $this->paymentId);
    }
}
