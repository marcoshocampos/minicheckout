<?php

namespace Tests\Feature;

use App\Http\Middleware\RequestId;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * FASE 1 — o contrato do middleware de correlação.
 *
 * As rotas aqui são do teste, não da aplicação: o middleware é GLOBAL
 * (`prepend` em bootstrap/app.php), então ele roda em qualquer rota, e uma
 * rota própria deixa testar o 500 sem depender do gateway fake.
 */
class RequestIdTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Devolve o que o resto da aplicação enxerga: o id no Context (que é o
        // que vai para o log e para a fila) e o id no próprio request (que é o
        // que o PaymentGateway propagaria).
        Route::get('/_teste/eco', fn () => response()->json([
            'no_context' => Context::get('request_id'),
            'no_request' => request()->header(RequestId::HEADER),
        ]));

        Route::get('/_teste/explode', fn () => throw new RuntimeException('bum'));
    }

    public function test_respeita_o_id_que_o_cliente_mandou(): void
    {
        $this->getJson('/_teste/eco', [RequestId::HEADER => 'req-do-cliente-42'])
            ->assertOk()
            ->assertHeader(RequestId::HEADER, 'req-do-cliente-42')
            ->assertExactJson([
                'no_context' => 'req-do-cliente-42',
                'no_request' => 'req-do-cliente-42',
            ]);
    }

    public function test_gera_um_ulid_quando_o_cliente_nao_manda(): void
    {
        $response = $this->getJson('/_teste/eco')->assertOk();

        $id = $response->headers->get(RequestId::HEADER);

        $this->assertNotNull($id, 'o response tem que trazer o header sempre');
        $this->assertSame(26, strlen($id), 'um ULID tem 26 caracteres');

        // O id do response é o MESMO que a aplicação viu: um id que o cliente
        // recebe mas que não está no log não serve para nada.
        $response->assertExactJson(['no_context' => $id, 'no_request' => $id]);
    }

    /**
     * Valores que não podem ser aceitos como id. O primeiro é o que importa:
     * um `\n` dentro do id injetaria no log uma linha falsa, escrita pelo
     * cliente.
     *
     * @return array<string, array{string}>
     */
    public static function idsInaceitaveis(): array
    {
        return [
            'quebra de linha' => ["req-a\nfake-log-line"],
            'retorno de carro' => ["req-a\rfake-log-line"],
            'espaco' => ['req com espaco'],
            'aspas' => ['req-"a"'],
            'barra' => ['req/a/b'],
            'acentuado' => ['requisição'],
            'vazio' => [''],
            'um byte acima do limite' => [str_repeat('a', RequestId::MAX_BYTES + 1)],
        ];
    }

    #[DataProvider('idsInaceitaveis')]
    public function test_descarta_id_inaceitavel_e_gera_outro(string $enviado): void
    {
        $response = $this->getJson('/_teste/eco', [RequestId::HEADER => $enviado])->assertOk();

        $id = $response->headers->get(RequestId::HEADER);

        $this->assertNotSame($enviado, $id, 'o valor recusado não pode voltar');
        $this->assertSame(26, strlen((string) $id), 'tem que vir um ULID novo');
        $response->assertExactJson(['no_context' => $id, 'no_request' => $id]);
    }

    public function test_aceita_exatamente_o_limite_de_tamanho(): void
    {
        $noLimite = str_repeat('a', RequestId::MAX_BYTES);

        $this->getJson('/_teste/eco', [RequestId::HEADER => $noLimite])
            ->assertOk()
            ->assertHeader(RequestId::HEADER, $noLimite);
    }

    public function test_devolve_o_header_quando_a_rota_explode(): void
    {
        // O handler vai REPORTAR a exceção, e a linha de log cairia no meio da
        // saída do PHPUnit. (Não dá para resolver com LOG_CHANNEL no
        // phpunit.xml: dentro do container o compose define a env no ambiente
        // de verdade, e o Env do Laravel lê $_SERVER antes do $_ENV que o
        // PHPUnit escreve.)
        Log::spy();

        // É o caso que mais importa: o 500 é a resposta em que o cliente
        // precisa do id, porque é o que ele tem para abrir um chamado.
        $this->getJson('/_teste/explode', [RequestId::HEADER => 'req-no-500'])
            ->assertStatus(500)
            ->assertHeader(RequestId::HEADER, 'req-no-500');
    }

    public function test_devolve_o_header_no_404(): void
    {
        $this->getJson('/_teste/rota-que-nao-existe', [RequestId::HEADER => 'req-no-404'])
            ->assertNotFound()
            ->assertHeader(RequestId::HEADER, 'req-no-404');
    }
}
