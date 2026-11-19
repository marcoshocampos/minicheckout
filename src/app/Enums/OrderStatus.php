<?php

namespace App\Enums;

/**
 * Estados possíveis de um pedido.
 *
 * `declined`, `failed` e `unknown` são três coisas diferentes, e essa
 * distinção é o assunto da Fase 2:
 *
 *   declined -> o gateway respondeu, e a resposta foi "não". Erro ESPERADO.
 *               Retentar não muda nada.
 *   failed   -> não falamos com o gateway, e temos PROVA de que a cobrança
 *               não aconteceu (conexão recusada, ou um 503 em que ele mesmo
 *               diz que não processou). Erro INESPERADO, e repetir é seguro.
 *   unknown  -> a cobrança saiu e não voltou resposta (timeout de leitura),
 *               ou voltou um 500 depois de ele já ter trabalhado. NÃO
 *               SABEMOS se o cartão foi cobrado.
 *
 * FASE 2 — por que `unknown` existe como ESTADO, e não só como resposta HTTP:
 *
 * O caso indeterminado é o que exige idempotência, e ele precisa estar nos
 * DADOS, não apenas no 504 que o cliente recebeu. Se ele ficar guardado como
 * `failed`, um dia alguém escreve "reprocessa todos os failed" e cobra duas
 * vezes quem já tinha sido cobrado; se ficar `pending`, ele é
 * indistinguível de um pedido que nem chegou a tentar.
 *
 * `unknown` é a fila de reconciliação do projeto: a Fase 3 vai consultar
 * esses pedidos no gateway (ou receber o webhook) e resolvê-los para `paid`
 * ou `failed`. Enquanto isso, ele é a prova visível de que existem TRÊS
 * respostas possíveis a uma cobrança, e não duas.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Declined = 'declined';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
