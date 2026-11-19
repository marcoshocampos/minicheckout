<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * FASE 1 — correlação.
 *
 * Toda requisição tem um id, e esse id aparece em TODO log que ela produzir —
 * inclusive nos logs do job que ela disparar, que roda em outro processo,
 * segundos depois, sem requisição HTTP nenhuma.
 *
 * ---------------------------------------------------------------------------
 * O QUE ACONTECE AQUI, na ordem
 *
 * 1. LÊ o header. Se o cliente mandou um id, ele é respeitado: é o que
 *    permite ele dizer "deu erro, o id é X" e alguém achar a requisição.
 * 2. VALIDA (ver `acceptable()`): isto é entrada de usuário que vai parar num
 *    índice de log.
 * 3. GERA um ULID quando não veio nada aproveitável.
 * 4. PUBLICA no Context, e devolve o valor para o próprio `$request`.
 * 5. DEVOLVE no response, sempre — inclusive em 4xx e 5xx.
 *
 * ---------------------------------------------------------------------------
 * POR QUE O Context É A PEÇA CENTRAL
 *
 * `Context::add()` não é um array global qualquer. Duas coisas acontecem de
 * graça por causa dele:
 *
 *  a) `Illuminate\Log\Context\ContextLogProcessor` é empurrado em TODO canal
 *     de log e injeta o Context no campo `extra` do registro do Monolog. Ou
 *     seja: nenhum `Log::info()` precisa repetir o `request_id`. Ele entra
 *     sozinho, até nas linhas que o próprio framework escreve.
 *
 *  b) `ContextServiceProvider` registra um `Queue::createPayloadUsing` que
 *     grava `Context::dehydrate()` na chave `illuminate:log:context` do
 *     payload do job, e um listener de `JobProcessing` que faz `hydrate()` do
 *     outro lado. A travessia HTTP -> Redis -> worker é AUTOMÁTICA: ninguém
 *     passa o id para o job, ele chega junto.
 *
 * Quem tira esses valores de `extra` e põe na raiz da linha de log é o
 * `App\Logging\LogLineFormatter`, para a consulta ser `request_id` e não
 * `extra_request_id`.
 *
 * ---------------------------------------------------------------------------
 * AS DECISÕES DESTA CLASSE, E O PREÇO DE CADA UMA
 *
 * - **ULID, não UUIDv4.** O ULID é ordenável por tempo: ajuda quem indexa o
 *   log e dá uma dica de "quando" só de olhar o id. `Str::uuid7()` serviria
 *   igual; `Str::uuid()` (v4) é aleatório e não ordena.
 *
 * - **Allowlist estreita em vez de sanitização.** Um id com `\n` dentro
 *   injetaria no log uma linha falsa, escrita pelo cliente — log injection de
 *   verdade. Neste projeto o JSON escaparia o `\n`, mas o mesmo id também vai
 *   para o log de acesso do nginx e para o gateway, e manter a conta de quem
 *   escapa o quê em cada saída é caro. Mais barato: só aceitar o que é
 *   inofensivo em qualquer saída.
 *
 * - **Id inválido é DESCARTADO, não corrigido.** Um id truncado ou com
 *   caracteres removidos seria um id que o cliente não reconhece, e isso é
 *   pior que um id novo: parece o dele. O response diz qual id valeu.
 *
 * - **O valor volta para o `$request`.** Sem isso, o `PaymentGateway`
 *   mandaria ao gateway o valor cru que acabamos de rejeitar, e os dois lados
 *   da chamada ficariam com ids diferentes.
 *
 * - **O webhook herda o id da requisição original**, porque o `map` do nginx
 *   também respeita o header que chega, e o gateway fake devolve o id que
 *   recebeu. Isso faz do `request_id` um id de FLUXO, não de requisição HTTP:
 *   a cobrança e a confirmação do gateway compartilham um id. É cômodo para
 *   investigar e é impreciso — são duas requisições, com latências e status
 *   próprios. A distinção certa tem nome (trace e span) e chega na Fase 4.
 *
 * ---------------------------------------------------------------------------
 * ONDE ISTO ESTÁ LIGADO
 *
 * `bootstrap/app.php` faz `$middleware->prepend(RequestId::class)`: global e
 * primeiro da fila. Global porque um 500 que estoura antes do roteamento
 * também precisa de id; primeiro porque tudo que logar depois dele já enxerga
 * o contexto.
 *
 * E o header sai no response mesmo quando a rota lança exceção: o
 * `Illuminate\Routing\Pipeline` captura o Throwable DENTRO do pipeline e o
 * renderiza em resposta, então o código depois do `$next()` roda. É por isso
 * que o 500 também volta com id — justamente o caso em que o cliente mais
 * precisa dele.
 */
class RequestId
{
    public const HEADER = 'X-Request-Id';

    /**
     * Limite de bytes do id que aceitamos do cliente. Um id de 10 KB enche o
     * disco de quem armazena o log. O valor casa com o `maxLength` declarado
     * em docs/api/openapi.yaml: se um mudar, o outro muda.
     */
    public const MAX_BYTES = 200;

    /**
     * Letras, dígitos, ponto, dois-pontos, hífen e sublinhado. Cobre ULID,
     * UUID, o `traceparent` do W3C e ids com prefixo (`req-fase1-teste`), e
     * não cobre espaço, quebra de linha, aspas nem byte de controle.
     */
    private const SHAPE = '/^[A-Za-z0-9._:-]+$/';

    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->resolve($request->header(self::HEADER));

        // Para o resto da aplicação, `request()->header()` e o Context têm que
        // concordar: quem lê um ou outro tem que achar o MESMO id.
        $request->headers->set(self::HEADER, $id);

        Context::add('request_id', $id);

        $response = $next($request);

        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    private function resolve(mixed $incoming): string
    {
        if (is_string($incoming) && $this->acceptable($incoming)) {
            return $incoming;
        }

        return (string) Str::ulid();
    }

    private function acceptable(string $incoming): bool
    {
        return $incoming !== ''
            && strlen($incoming) <= self::MAX_BYTES
            && preg_match(self::SHAPE, $incoming) === 1;
    }
}
