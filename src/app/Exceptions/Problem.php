<?php

namespace App\Exceptions;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;

/**
 * Um documento de problema da RFC 9457 — "Problem Details for HTTP APIs".
 *
 * Este arquivo é o TIJOLO da fase, e por isso vem implementado: serializar um
 * objeto de cinco campos não é o conceito que a Fase 2 ensina. O que é seu é
 * decidir QUAL problema cada falha vira — e isso mora nas exceções
 * (PaymentDeclined, GatewayUnavailable, PaymentUnconfirmed) e em
 * bootstrap/app.php.
 *
 * ---------------------------------------------------------------------------
 * O problema que a RFC resolve
 *
 * Hoje esta API responde erro em TRÊS formatos diferentes:
 *
 *   {"message":"...","errors":{...}}                     <- validação (422)
 *   {"type":"...","title":"...","status":400,...}        <- o 400 do Idempotency
 *   {"message":"...","exception":"...","trace":[...]}    <- o 500 cru, com
 *                                                           stack trace
 *
 * Um cliente que queira tratar erro de forma programática tem que adivinhar
 * qual dos três chegou. A RFC 9457 define UM formato e um content-type
 * próprio, `application/problem+json`: o cliente sabe que é erro estruturado
 * olhando só o header, sem inspecionar o corpo.
 *
 * Os cinco campos padrão (§3.1), e para que serve cada um:
 *
 *   type      URI que identifica o TIPO do problema. É a chave que o cliente
 *             usa no `if`. Tem que ser ESTÁVEL: mudar o `type` é quebra de
 *             contrato. O default `about:blank` significa "não há nada além
 *             do que o status HTTP já diz".
 *   title     resumo legível do tipo. É do TIPO, não da ocorrência: não
 *             mude de requisição para requisição.
 *   status    o mesmo status da resposta HTTP. Duplicado de propósito: o
 *             corpo pode ser guardado, logado ou encaminhado longe do
 *             response.
 *   detail    o que aconteceu NESTA ocorrência. Para humano ler, não para
 *             código comparar.
 *   instance  URI que identifica esta ocorrência específica.
 *
 * E a RFC permite membros de EXTENSÃO (§3.2) — é por essa porta que entra o
 * `request_id` do projeto, que é o que transforma "deu erro" em "deu erro, o
 * id é X" e liga o cliente às 11 linhas de log da Fase 1.
 *
 * Fica em app/Exceptions/ junto com as exceções de propósito: tudo que é
 * tratamento de erro do projeto está em uma pasta só.
 * ---------------------------------------------------------------------------
 */
class Problem implements Responsable
{
    /**
     * Títulos em português para os status que esta API pode devolver sem ter
     * uma exceção de domínio por trás (404, 405, 500...). São títulos do
     * TIPO, não da ocorrência — por isso constantes, e não interpolação.
     */
    private const TITLES = [
        400 => 'Requisição inválida',
        401 => 'Não autenticado',
        403 => 'Acesso negado',
        404 => 'Não encontrado',
        405 => 'Método não permitido',
        409 => 'Conflito',
        415 => 'Formato não suportado',
        422 => 'Dados inválidos',
        429 => 'Muitas requisições',
        500 => 'Erro interno',
        503 => 'Serviço indisponível',
        504 => 'Tempo esgotado',
    ];

    /** Membros de extensão da RFC (§3.2): request_id, order_id, etc. */
    private array $extensions = [];

    /** Headers extra do response. O caso real é o Retry-After do 503. */
    private array $headers = [];

    public function __construct(
        public readonly int $status,
        public readonly string $title,
        public readonly string $type = 'about:blank',
        public readonly ?string $detail = null,
        public readonly ?string $instance = null,
    ) {
    }

    /**
     * O problema genérico de um status HTTP.
     *
     * É o que o funil `respond()` de bootstrap/app.php usa para traduzir o
     * que NÃO é exceção de domínio — o 404 do route binding, o 405, o 500 de
     * um bug — para o mesmo formato. Sem isto, a API volta a ter três
     * formatos de erro e o cliente volta a ter três `if`.
     *
     * `type` fica em `about:blank` de propósito nos casos em que o status já
     * diz tudo o que há para dizer (RFC 9457 §4.2.1): inventar uma URI de
     * tipo para cada 404 é contrato a mais sem informação a mais.
     */
    public static function fromStatus(int $status, ?string $detail = null, string $type = 'about:blank'): self
    {
        return new self(
            status: $status,
            title: self::TITLES[$status] ?? 'Erro',
            type: $type,
            detail: $detail,
        );
    }

    /** Membro de extensão. Valor null é ignorado, para não poluir o corpo. */
    public function with(string $member, mixed $value): self
    {
        if ($value === null) {
            return $this;
        }

        $clone = clone $this;
        $clone->extensions[$member] = $value;

        return $clone;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function toArray(): array
    {
        $members = array_filter([
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
            'instance' => $this->instance,
        ], static fn (mixed $v): bool => $v !== null);

        // O request_id entra em TODO problema, sem ninguém pedir — é o mesmo
        // valor do header X-Request-Id, e está aqui no corpo porque corpo de
        // erro é o que um cliente guarda em log ou cola num chamado.
        return [
            ...$members,
            ...$this->extensions,
            'request_id' => Context::get('request_id'),
        ];
    }

    public function toResponse($request): JsonResponse
    {
        return response()->json(
            $this->toArray(),
            $this->status,
            [
                // O Content-Type é metade da RFC. Sem ele é só mais um JSON.
                // Passar o header aqui funciona porque o JsonResponse do
                // Symfony só força `application/json` quando o header ainda
                // não existe.
                'Content-Type' => 'application/problem+json',
                ...$this->headers,
            ],
            // Sem \/ escapado: o `type` é uma URI e precisa ser legível para
            // quem lê o erro no terminal.
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
