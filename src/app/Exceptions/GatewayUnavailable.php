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
        // FASE 2 — DECISÃO SUA:
        //
        // 502 ou 503? A RFC 9110 separa assim:
        //   502 Bad Gateway         — eu sou intermediário e recebi resposta
        //                             inválida de quem está atrás de mim
        //   503 Service Unavailable — eu não consigo atender AGORA, tente
        //                             depois. É o único que combina com
        //                             `Retry-After`.
        //
        // E o Retry-After: quanto? Valor fixo é simples e sincroniza todos os
        // clientes no mesmo segundo — a avalanche volta, agora vinda deles.
        // Valor com jitter espalha. É a mesma discussão do retry no
        // PaymentGateway, vista do outro lado do fio.
        //
        // Não exponha `attempts`/`gatewayStatus` ao cliente sem pensar: é
        // detalhe da sua infra, e detalhe de infra em contrato público é algo
        // que você não vai poder mudar depois.
        // ------------------------------------------------------------------
        return (new Problem(
            status: 503,
            title: 'Gateway de pagamento indisponível',
            type: '/problems/gateway-unavailable',
            detail: 'Não conseguimos falar com o gateway de pagamento e a '
                . 'cobrança não foi feita. O pedido ficou com status `failed`. '
                . 'Pode repetir a requisição com a MESMA Idempotency-Key.',
        ))
            ->with('order_id', $this->orderId)
            ->withHeader('Retry-After', '5');
    }
}
