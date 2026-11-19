<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A cobrança foi enviada e o gateway não respondeu a tempo. Ou respondeu 500
 * DEPOIS de já ter trabalhado. Em qualquer dos dois casos: nós não sabemos se
 * o cartão foi cobrado.
 *
 * Esta é a exceção mais importante do projeto, e a que normalmente não existe
 * nos sistemas de verdade — ali o timeout do gateway e o "gateway fora" caem
 * no mesmo `catch`, e então alguém retenta um POST que pode ter cobrado.
 *
 * Três estados, não dois:
 *
 *   sucesso        o gateway respondeu "cobrei"      -> paid
 *   falha          o gateway respondeu "não cobrei"  -> declined / failed
 *   INDETERMINADO  o gateway não respondeu           -> ???
 *
 * O terceiro estado é o que exige idempotência. Se você retenta um timeout
 * sem que o OUTRO lado deduplique pela chave, você cobra duas vezes — e o
 * gateway fake deste projeto não deduplica de propósito, para você ver isso
 * acontecer no contador de `/_stats`. É a ponte para a Fase 3.
 *
 * Por isso o `detail` do problema não diz "tente de novo": diz "consulte o
 * pedido". Mandar o cliente repetir uma cobrança que pode ter dado certo é
 * transformar um incidente de disponibilidade em um incidente financeiro.
 */
class PaymentUnconfirmed extends RuntimeException implements ProvidesProblem
{
    public function __construct(
        public readonly string $orderId,
        public readonly int $attempts = 1,
        public readonly ?int $timeoutSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "pagamento indeterminado apos {$attempts} tentativa(s): o gateway nao respondeu",
            previous: $previous,
        );
    }

    public function problem(): Problem
    {
        // ------------------------------------------------------------------
        // FASE 2 — DECISÃO TOMADA: 504, SEM `Retry-After`.
        //
        // 504 (Gateway Timeout) descreve a causa e é o palpite óbvio; 502 é
        // mais genérico; 409 (Conflict) seria defensável ("não posso concluir
        // sem saber em que estado isto está"), mas 409 convida o cliente a
        // resolver o conflito, e aqui não há nada que ele possa resolver.
        //
        // O que NÃO pode, em nenhuma das três: mandar `Retry-After`. Ele é um
        // convite a repetir, e repetir é justamente o que pode cobrar duas
        // vezes enquanto a idempotência não existir. O `instance` abaixo é o
        // oposto do Retry-After: em vez de "tente de novo", ele entrega a URI
        // de onde o cliente DESCOBRE o que aconteceu.
        //
        // Quando a Fase 3 estiver pronta e o replay da MESMA Idempotency-Key
        // for garantido, este `detail` muda e o `Retry-After` passa a poder
        // existir — e a spec muda junto, no mesmo commit.
        // ------------------------------------------------------------------
        return (new Problem(
            status: 504,
            title: 'Pagamento sem confirmação',
            type: '/problems/payment-unconfirmed',
            // O texto serve para os DOIS caminhos que chegam aqui: o gateway
            // que não respondeu e o que respondeu 5xx depois de já ter
            // trabalhado. Dizer "não respondeu no tempo limite" seria mentira
            // no segundo caso — e detalhe de erro que mente custa chamado.
            detail: 'Enviamos a cobrança e não obtivemos uma resposta '
                . 'conclusiva do gateway. NÃO sabemos se o pagamento foi '
                . 'feito; o pedido ficou com status `unknown`. Consulte o '
                . 'pedido antes de tentar pagar de novo.',
            instance: "/orders/{$this->orderId}",
        ))
            ->with('order_id', $this->orderId);
    }
}
