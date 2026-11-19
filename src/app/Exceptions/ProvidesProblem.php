<?php

namespace App\Exceptions;

/**
 * Exceção que sabe se apresentar como um documento da RFC 9457.
 *
 * Por que uma interface, e não um `render()` em cada exceção: o Laravel
 * aceita os dois, mas o roteiro desta fase pede a configuração CENTRAL em
 * bootstrap/app.php. Com a interface, lá existe UM handler só —
 *
 *     $exceptions->render(fn (ProvidesProblem $e, Request $r) => ...);
 *
 * — e toda exceção nova de domínio já nasce renderizada, sem tocar no
 * bootstrap. Quando a política de erro está espalhada em `render()` dentro de
 * cada exceção, a pergunta "o que esta API responde quando falha?" não tem um
 * lugar onde ser respondida.
 *
 * A divisão de trabalho que esta interface cria é o assunto da fase:
 *
 *   a exceção   diz O QUE aconteceu (dado) e COMO CONTAR ao cliente (problem)
 *   o bootstrap diz se aquilo é INCIDENTE (report) ou não (dontReport)
 *
 * São perguntas diferentes, e a resposta de uma não implica a da outra: uma
 * recusa de cartão é resposta ao cliente e não é incidente; um gateway fora é
 * resposta ao cliente E é incidente.
 */
interface ProvidesProblem
{
    public function problem(): Problem;
}
