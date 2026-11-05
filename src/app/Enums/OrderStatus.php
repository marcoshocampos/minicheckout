<?php

namespace App\Enums;

/**
 * Estados possíveis de um pedido.
 *
 * `declined` e `failed` são coisas diferentes e essa distinção é o assunto da
 * Fase 2:
 *   declined -> o gateway respondeu, e a resposta foi "não". Erro ESPERADO.
 *               Retentar não muda nada.
 *   failed   -> o gateway não respondeu, ou respondeu 500. Erro INESPERADO.
 *               Retentar faz sentido — e é aí que a idempotência passa a ser
 *               obrigatória (Fase 3).
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Declined = 'declined';
    case Failed = 'failed';
}
