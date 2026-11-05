<?php

namespace App\Http\Middleware;

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
            // FASE 2: isto vira Problem Details (RFC 9457) de verdade, com
            // Content-Type: application/problem+json e um `type` que aponta
            // para a documentação do erro.
            return response()->json([
                'type' => 'about:blank',
                'title' => 'Idempotency-Key obrigatório',
                'status' => 400,
                'detail' => 'Esta rota exige o header Idempotency-Key para que você possa '
                    . 'repetir a requisição com segurança depois de um timeout.',
            ], 400);
        }

        return $next($request);
    }
}
