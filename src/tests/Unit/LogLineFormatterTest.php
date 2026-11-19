<?php

namespace Tests\Unit;

use App\Logging\LogLineFormatter;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * FASE 1 — a forma da linha de log é contrato.
 *
 * Quem consulta o log (e, a partir da Fase 5, o dashboard e o alerta) depende
 * de `request_id` estar na RAIZ e de `level` ser a palavra. Mudar isso sem
 * querer quebra consulta, painel e alerta de uma vez, e em silêncio — por isso
 * tem teste.
 */
class LogLineFormatterTest extends TestCase
{
    public function test_o_context_sobe_para_a_raiz_e_o_payload_da_chamada_fica_aninhado(): void
    {
        $linha = $this->format($this->record(
            context: ['amount_cents' => 1990],
            extra: ['request_id' => 'req-1', 'order_id' => 'ord-1', 'source' => 'worker', 'host' => 'c0ffee'],
        ));

        // assertSame compara a ORDEM também: a sequência dos campos faz parte
        // do que é agradável de ler no terminal.
        $this->assertSame([
            'ts' => '2026-11-19T03:13:10.506155+00:00',
            'source' => 'worker',
            'level' => 'info',
            'event' => 'order.created',
            'request_id' => 'req-1',
            'order_id' => 'ord-1',
            'host' => 'c0ffee',
            'context' => ['amount_cents' => 1990],
        ], $linha);
    }

    public function test_linha_sem_payload_nao_traz_a_chave_context(): void
    {
        $this->assertArrayNotHasKey('context', $this->format($this->record()));
    }

    public function test_o_level_e_a_palavra_que_o_grafana_entende(): void
    {
        $this->assertSame('error', $this->format($this->record(level: Level::Error))['level']);
        $this->assertSame('warning', $this->format($this->record(level: Level::Warning))['level']);
    }

    public function test_quem_loga_nao_consegue_reescrever_a_forma_da_linha(): void
    {
        // Um valor de Context chamado `event` ou `level` não pode trocar o
        // campo da linha: o formato não pode depender de quem loga.
        $linha = $this->format($this->record(
            extra: ['event' => 'mentira', 'level' => 'debug', 'ts' => 'ontem', 'request_id' => 'req-1'],
        ));

        $this->assertSame('order.created', $linha['event']);
        $this->assertSame('info', $linha['level']);
        $this->assertSame('2026-11-19T03:13:10.506155+00:00', $linha['ts']);
        $this->assertSame('req-1', $linha['request_id']);
    }

    public function test_sem_o_tap_a_linha_ainda_tem_source(): void
    {
        // O default existe para quem usar o formatter sem o processor que
        // injeta `source`. Linha sem `source` seria pior que uma errada: a
        // consulta `source="worker"` passaria a esconder linhas.
        $this->assertSame('app', $this->format($this->record())['source']);
    }

    public function test_excecao_no_payload_vira_dado_e_nao_quebra_a_linha(): void
    {
        // A Fase 2 vai mandar exceção para o log de propósito. Isto é o que o
        // normalizeRecord herdado do JsonFormatter resolve de graça.
        $linha = $this->format($this->record(context: ['exception' => new RuntimeException('bum', 42)]));

        $this->assertSame('RuntimeException', $linha['context']['exception']['class']);
        $this->assertSame('bum', $linha['context']['exception']['message']);
        $this->assertSame(42, $linha['context']['exception']['code']);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     */
    private function record(array $context = [], array $extra = [], Level $level = Level::Info): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable('2026-11-19T03:13:10.506155+00:00'),
            channel: 'local',
            level: $level,
            message: 'order.created',
            context: $context,
            extra: $extra,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function format(LogRecord $record): array
    {
        $formatada = (new LogLineFormatter())->format($record);

        $this->assertStringEndsWith("\n", $formatada);
        $this->assertSame(1, substr_count($formatada, "\n"), 'uma linha por evento, sempre');

        return json_decode(trim($formatada), true, 512, JSON_THROW_ON_ERROR);
    }
}
