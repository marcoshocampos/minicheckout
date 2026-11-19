<?php

namespace App\Http\Middleware;

use App\Exceptions\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FASE 0: este middleware só exige que o header exista. Nada mais.
 *
 * É de propósito: hoje você pode mandar a mesma chave 20 vezes e vai criar 20
 * pedidos e 20 cobranças. Rode `k6/idempotency.js` e guarde esse número.
 *
 * ---------------------------------------------------------------------------
 * FASE 3 — o que entra aqui (o coração do projeto):
 *
 * 1. Hash do payload: sha256 do corpo + método + rota. Mesma chave com payload
 *    diferente é erro do cliente, não replay -> 422.
 *
 * 2. Lock antes de qualquer leitura:
 *       Cache::lock("idem:{$key}", 10)->block(...)
 *    Sem lock, duas requisições simultâneas fazem o mesmo `select`, as duas
 *    não encontram nada e as duas processam. O lock serializa; o UNIQUE no
 *    banco é a rede de segurança para quando o lock falhar (Redis caiu).
 *
 * 3. Máquina de estados da chave:
 *       (nada)                -> INSERT status=processing, segue o fluxo
 *       processing            -> 409 Conflict (ainda estou processando)
 *       done + mesmo hash     -> devolve a resposta SALVA, sem reprocessar
 *       done + hash diferente -> 422
 *
 * 4. Gravar a resposta DEPOIS de responder: status, headers relevantes e corpo.
 *    Cuidado: se o processo morrer entre o insert e a gravação, a chave fica
 *    presa em `processing` para sempre. Precisa de expiração (locked_at) e de
 *    uma decisão sobre o que fazer com chave expirada.
 *
 * 5. Decidir o que é "mesma requisição": só o corpo? corpo + usuário? O que
 *    acontece se o cliente reusar a chave em outra rota?
 *
 * Perguntas para responder com código:
 *   - a chave é gravada na MESMA transação que cria o pedido? por quê?
 *   - se o gateway cobrou mas o app caiu antes de salvar a resposta, o replay
 *     devolve o quê?
 *   - quanto tempo uma chave vive? e depois que expira, é replay ou pedido novo?
 * ---------------------------------------------------------------------------
 */
class Idempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (blank($key)) {
            // ---------------------------------------------------------------
            // FASE 2: antes, isto era um Problem Details pela METADE — campos
            // certos, `Content-Type: application/json` errado. Era o único
            // erro da API com essa cara, e por isso um cliente não conseguia
            // escrever UM tratamento de erro só.
            //
            // Agora é a classe, e ela cuida do resto: o content-type da RFC e
            // o `request_id` no corpo, como em todos os outros erros.
            //
            // Repare que isto é RESPOSTA e não exceção: o middleware
            // interrompe a cadeia devolvendo um response. Dá nos dois jeitos;
            // exceção seria obrigatória se esta decisão precisasse acontecer
            // mais fundo, longe de quem monta a resposta.
            //
            // Mudança de CONTRATO (o content-type): spec no mesmo commit.
            // ---------------------------------------------------------------
            return (new Problem(
                status: 400,
                title: 'Idempotency-Key obrigatório',
                type: '/problems/missing-idempotency-key',
                detail: 'Esta rota exige o header Idempotency-Key para que você possa '
                    . 'repetir a requisição com segurança depois de um timeout.',
            ))->toResponse($request);
        }

        return $next($request);
    }
}
