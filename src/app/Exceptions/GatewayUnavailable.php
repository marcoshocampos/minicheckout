<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Não foi possível falar com o gateway, e nós sabemos que a cobrança NÃO
 * aconteceu: conexão recusada, DNS, connect timeout, ou um 503 com que o
 * próprio gateway diz "estou fora, não processei nada".
 *
 * Erro INESPERADO:
 *   - não faz parte do caminho normal do negócio
 *   - retentar faz sentido (e o PaymentGateway já retentou antes de chegar
 *     aqui)
 *   - alguém precisa saber: é `report`, e vira alerta na Fase 5
 *
 * A diferença entre esta e PaymentUnconfirmed é a pergunta mais importante da
 * fase: a requisição CHEGOU a ser processada do outro lado?
 *
 *   aqui                  não chegou  -> repetir é seguro
 *   PaymentUnconfirmed    não sei     -> repetir pode cobrar duas vezes
 *
 * É por isso que o cliente pode receber `Retry-After` neste caso e não no
 * outro.
 */
class GatewayUnavailable extends RuntimeException implements ProvidesProblem
{
    public function __construct(
        public readonly string $orderId,
        public readonly int $attempts = 1,
        public readonly ?int $gatewayStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "gateway indisponivel apos {$attempts} tentativa(s)"
                . ($gatewayStatus !== null ? " (ultimo status {$gatewayStatus})" : ''),
            previous: $previous,
        );
    }

    public function problem(): Problem
    {
        // ------------------------------------------------------------------
        // FASE 2 — DECISÃO TOMADA: 503 com `Retry-After`.
        //
        // A RFC 9110 separa assim:
        //   502 Bad Gateway         — eu sou intermediário e recebi resposta
        //                             inválida de quem está atrás de mim
        //   503 Service Unavailable — eu não consigo atender AGORA, tente
        //                             depois. É o único que combina com
        //                             `Retry-After`.
        //
        // Ganhou o 503 porque é o único que diz ao cliente o que fazer. E ele
        // só pode dizer "tente de novo" porque esta classe significa
        // exatamente "não cobramos" — o caso indeterminado é o
        // PaymentUnconfirmed, e aquele NÃO leva Retry-After.
        //
        // O valor é fixo (5s) e por isso sincroniza: todos os clientes que
        // comeram 503 no mesmo segundo voltam juntos no mesmo segundo
        // seguinte, e a avalanche volta — agora vinda deles. O jitter que o
        // PaymentGateway aplica nas NOSSAS tentativas é a mesma ideia, vista
        // do outro lado do fio; aqui ele depende de o cliente respeitar o
        // header. Um `Retry-After: 4..7` aleatório seria melhor, e é um bom
        // primeiro exercício desta fase.
        //
        // `attempts` e `gatewayStatus` ficam FORA do corpo de propósito: são
        // detalhe da nossa infra, e detalhe de infra em contrato público é
        // algo que você não vai poder mudar depois. Eles vão para o log.
        // ------------------------------------------------------------------
        return (new Problem(
            status: 503,
            title: 'Gateway de pagamento indisponível',
            type: '/problems/gateway-unavailable',
            detail: 'Não conseguimos falar com o gateway de pagamento e a '
                . 'cobrança não foi feita. O pedido ficou com status `failed`. '
                . 'Pode repetir a requisição com a MESMA Idempotency-Key.',
            instance: "/orders/{$this->orderId}",
        ))
            ->with('order_id', $this->orderId)
            ->withHeader('Retry-After', '5');
    }
}
