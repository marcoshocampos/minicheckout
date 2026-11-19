<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Exceptions\GatewayUnavailable;
use App\Exceptions\PaymentDeclined;
use App\Exceptions\PaymentUnconfirmed;
use App\Jobs\SendOrderNotification;
use App\Models\Order;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Promise\Create;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * FASE 2 — o contrato do tratamento de erro.
 *
 * O que estes testes travam não é "o código funciona": é a POLÍTICA. Cada
 * caso aqui é uma decisão que dá para tomar errado sem nenhum teste reclamar
 * — e que só aparece em produção, no dia ruim.
 *
 * Os três grupos:
 *   1. o que o cliente vê     (Problem Details, status, headers)
 *   2. o que nós fazemos      (retentar ou não, e quantas vezes)
 *   3. o que isso significa   (estado do pedido: failed vs unknown)
 *
 * `Sleep::fake()` em todos: sem ele, os testes de retry esperariam o backoff
 * de verdade. O `Sleep` é fake e o backoff continua sendo exercido — o que se
 * perde é só a espera.
 */
class TratamentoDeErrosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        // Requisição HTTP que escape do fake é bug de teste, não "ah, foi na
        // rede". Sem isto, um teste mal escrito bate no gateway de verdade.
        Http::preventStrayRequests();
    }

    private function criaPedido(array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(
            '/orders',
            [
                'amount_cents' => 1990,
                'currency' => 'BRL',
                'customer_email' => 'eu@example.test',
            ],
            ['Idempotency-Key' => 'teste-' . uniqid()] + $headers,
        );
    }

    /** Simula "a requisição NÃO chegou": o Guzzle nem conectou. */
    private function fakeConexaoRecusada(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
    }

    /**
     * Simula "a requisição chegou e não voltou resposta": timeout de leitura.
     *
     * É o `NetworkTimeoutException` do Guzzle (cURL 28 depois de conectar) —
     * a classe que separa este caso do de cima. O Laravel embala os dois numa
     * `ConnectionException` só, e é no `getPrevious()` que a diferença fica.
     */
    private function fakeTimeoutDeLeitura(): void
    {
        Http::fake(['*' => fn (ClientRequest $request) => Create::rejectionFor(
            new NetworkTimeoutException(
                'cURL error 28: Operation timed out after 3000 milliseconds',
                $request->toPsrRequest(),
            ),
        )]);
    }

    // =====================================================================
    // 1. O QUE O CLIENTE VÊ
    // =====================================================================

    public function test_recusa_responde_402_em_problem_details(): void
    {
        Http::fake(['*' => Http::response([
            'error' => 'card_declined',
            'decline_code' => 'insufficient_funds',
            'payment_id' => 'pay_123',
        ], 402)]);

        $response = $this->criaPedido()
            ->assertStatus(402)
            ->assertHeader('Content-Type', 'application/problem+json');

        $order = Order::sole();

        $response->assertJsonPath('type', '/problems/payment-declined')
            ->assertJsonPath('title', 'Pagamento recusado')
            ->assertJsonPath('status', 402)
            ->assertJsonPath('instance', "/orders/{$order->id}")
            ->assertJsonPath('order_id', $order->id)
            ->assertJsonPath('decline_code', 'insufficient_funds')
            ->assertJsonPath('payment_id', 'pay_123');

        // O request_id em TODO erro: é o que liga a resposta ao log.
        $this->assertNotEmpty($response->json('request_id'));
        $this->assertSame(
            $response->headers->get('X-Request-Id'),
            $response->json('request_id'),
            'o id do corpo tem que ser o mesmo do header',
        );

        // O pedido EXISTE e está consultável: é isso que o 402 não diz e o
        // `instance` resolve.
        $this->assertSame(OrderStatus::Declined, $order->status);
        $this->assertSame('insufficient_funds', $order->decline_reason);
    }

    public function test_validacao_responde_422_em_problem_details_sem_perder_os_campos(): void
    {
        $response = $this->postJson('/orders', [
            'amount_cents' => 0,
            'currency' => 'XXX',
        ], ['Idempotency-Key' => 'teste-validacao'])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json');

        $response->assertJsonPath('type', '/problems/validation')
            ->assertJsonPath('status', 422)
            // `errors` sobrevive como membro de extensão da RFC: seguir o
            // formato não pode custar a informação que o cliente usa.
            ->assertJsonStructure(['errors' => ['amount_cents', 'currency', 'customer_email']]);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_sem_idempotency_key_responde_400_em_problem_details(): void
    {
        $this->postJson('/orders', [
            'amount_cents' => 1990,
            'currency' => 'BRL',
            'customer_email' => 'eu@example.test',
        ])
            ->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', '/problems/missing-idempotency-key')
            ->assertJsonPath('status', 400);
    }

    public function test_pedido_inexistente_responde_404_em_problem_details(): void
    {
        $this->getJson('/orders/01a1ef36-69f0-7128-819b-2f9d7a625b18')
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 404)
            // `about:blank` é o `type` correto quando o status já diz tudo.
            ->assertJsonPath('type', 'about:blank')
            // E o detalhe fica de fora de propósito: a mensagem padrão do
            // Eloquent vaza o nome do model.
            ->assertJsonMissingPath('detail');
    }

    public function test_erro_inesperado_responde_500_sem_stack_trace(): void
    {
        // 400 do gateway = payload errado = BUG NOSSO. Não é recusa e não é
        // indisponibilidade: é a terceira categoria, a que não tem nada a
        // dizer ao cliente além do request_id.
        Http::fake(['*' => Http::response(['error' => 'invalid_request'], 400)]);

        $response = $this->criaPedido()
            ->assertStatus(500)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', '/problems/internal');

        $body = $response->json();

        // O que esta fase conserta: o trace NUNCA vai para o cliente.
        $this->assertArrayNotHasKey('trace', $body);
        $this->assertArrayNotHasKey('file', $body);
        $this->assertArrayNotHasKey('line', $body);
        $this->assertNotEmpty($body['request_id']);

        // E o pedido não fica `pending` para sempre.
        $this->assertSame(OrderStatus::Failed, Order::sole()->status);
    }

    // =====================================================================
    // 2. O QUE NÓS FAZEMOS: retentar é uma decisão, não um default
    // =====================================================================

    public function test_conexao_recusada_retenta_e_responde_503(): void
    {
        $this->fakeConexaoRecusada();

        $this->criaPedido()
            ->assertStatus(503)
            ->assertHeader('Content-Type', 'application/problem+json')
            // Só este caso pode convidar o cliente a repetir.
            ->assertHeader('Retry-After', '5')
            ->assertJsonPath('type', '/problems/gateway-unavailable');

        // 3 tentativas: há PROVA de que a cobrança não saiu.
        Http::assertSentCount(3);

        $this->assertSame(OrderStatus::Failed, Order::sole()->status);
    }

    public function test_503_do_gateway_retenta_e_responde_503(): void
    {
        // "estou fora, não processei nada" — o gateway afirmando que não
        // cobrou. É o único status 5xx que é seguro retentar.
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);

        Log::spy();

        $this->criaPedido()
            ->assertStatus(503)
            ->assertJsonPath('type', '/problems/gateway-unavailable');

        Http::assertSentCount(3);

        $this->assertSame(OrderStatus::Failed, Order::sole()->status);

        // DUAS linhas de retry para TRÊS tentativas — e é aqui que o log
        // mentiria: quando a falha é uma resposta (e não um erro de conexão),
        // o `when` do cliente HTTP é consultado também na última tentativa.
        // Quem loga "vou retentar" sem olhar o número da tentativa escreve
        // uma retentativa que nunca aconteceu.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $event) => $event === 'gateway.retrying')
            ->twice();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $event, array $context) => $event === 'gateway.not_retrying'
                && $context['reason'] === 'tentativas esgotadas')
            ->once();
    }

    public function test_timeout_de_leitura_nao_retenta_e_responde_504(): void
    {
        $this->fakeTimeoutDeLeitura();

        $this->criaPedido()
            ->assertStatus(504)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', '/problems/payment-unconfirmed')
            // O oposto do 503: NADA de Retry-After. Repetir é o que pode
            // cobrar duas vezes.
            ->assertHeaderMissing('Retry-After');

        // UMA tentativa. Esta asserção é o coração da fase: a requisição saiu
        // e pode ter cobrado, então insistir é arriscar cobrança dupla.
        Http::assertSentCount(1);

        // E o estado indeterminado existe nos DADOS, não só na resposta.
        $this->assertSame(OrderStatus::Unknown, Order::sole()->status);
    }

    public function test_500_do_gateway_nao_retenta_e_responde_504(): void
    {
        // Ele respondeu — e o que fez antes de responder é desconhecido.
        Http::fake(['*' => Http::response(['error' => 'internal_error'], 500)]);

        $this->criaPedido()
            ->assertStatus(504)
            ->assertJsonPath('type', '/problems/payment-unconfirmed');

        Http::assertSentCount(1);

        $this->assertSame(OrderStatus::Unknown, Order::sole()->status);
    }

    public function test_recusa_nunca_retenta(): void
    {
        Http::fake(['*' => Http::response(['decline_code' => 'expired_card'], 402)]);

        $this->criaPedido()->assertStatus(402);

        // Retentar uma recusa é três vezes o mesmo "não", três vezes mais
        // lento, e três linhas de ruído no log.
        Http::assertSentCount(1);
    }

    public function test_aprovado_despacha_a_notificacao_uma_vez(): void
    {
        Queue::fake();

        Http::fake(['*' => Http::response([
            'payment_id' => 'pay_ok',
            'status' => 'approved',
        ], 200)]);

        $this->criaPedido()
            ->assertStatus(201)
            ->assertJsonPath('status', 'paid');

        Http::assertSentCount(1);
        Queue::assertPushed(SendOrderNotification::class, 1);
    }

    // =====================================================================
    // 3. A POLÍTICA DE bootstrap/app.php
    // =====================================================================

    public function test_recusa_nao_e_incidente_e_o_resto_e(): void
    {
        $handler = app(ExceptionHandler::class);

        // Erro ESPERADO: responde 402 e NÃO vira linha no log de erro, nem
        // conta na taxa de erro, nem acorda ninguém.
        $this->assertFalse(
            $handler->shouldReport(new PaymentDeclined('order-1', 'insufficient_funds')),
            'recusa de cartao nao pode ser incidente: 5% num dia de promocao dispara o alerta',
        );

        // Erros INESPERADOS: respondem ao cliente E são incidente.
        $this->assertTrue($handler->shouldReport(new GatewayUnavailable('order-1')));
        $this->assertTrue($handler->shouldReport(new PaymentUnconfirmed('order-1')));
        $this->assertTrue($handler->shouldReport(new RuntimeException('bug')));
    }

    public function test_a_gravidade_do_log_acompanha_o_dano(): void
    {
        Log::spy();

        $this->fakeTimeoutDeLeitura();
        $this->criaPedido()->assertStatus(504);

        // Pode haver dinheiro cobrado sem pedido pago: é o nível mais alto do
        // projeto, e é o que a Fase 5 vai alertar.
        Log::shouldHaveReceived('critical')
            ->withArgs(fn (string $event) => $event === 'order.payment_unconfirmed')
            ->once();
    }

    public function test_falha_sem_cobranca_e_warning_e_nao_error(): void
    {
        Log::spy();

        $this->fakeConexaoRecusada();
        $this->criaPedido()->assertStatus(503);

        // warning: o cliente comeu um 503, mas nada foi cobrado e repetir
        // resolve. Dói, não sangra.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $event) => $event === 'order.payment_failed')
            ->once();

        // E o retry foi VISÍVEL: sem esta linha, o cliente espera segundos e
        // o log não explica por quê.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $event) => $event === 'gateway.retrying')
            ->twice();
    }

    // =====================================================================
    // 4. A FILA
    // =====================================================================

    public function test_o_job_declara_tentativas_e_backoff(): void
    {
        $job = new SendOrderNotification(new Order());

        // Este valor é o que faz o worker ignorar o `--tries=1` do compose.
        $this->assertSame(4, $job->tries);

        // Dois valores para três intervalos: o último SE REPETE (2, 8, 8).
        $this->assertSame([2, 8], $job->backoff());
    }

    public function test_o_job_morto_diz_no_log_qual_efeito_se_perdeu(): void
    {
        Log::spy();

        (new SendOrderNotification(new Order()))
            ->failed(new RuntimeException('smtp fora'));

        // A linha que um humano procura não é "Job failed": é o efeito que
        // não aconteceu, em linguagem de negócio.
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $event, array $context) => $event === 'notification.dead_letter'
                && $context['exception'] === RuntimeException::class
                && $context['effect_lost'] === 'o cliente nao foi notificado do pedido pago')
            ->once();
    }
}
