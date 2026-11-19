<?php
/**
 * -------------------------------------------------------------------------
 * GATEWAY DE PAGAMENTO FAKE
 *
 * Existe para falhar. Cada falha abaixo corresponde a uma licao do roteiro:
 *
 *   500   -> erro INESPERADO: o app deve reportar e retentar           (Fase 2)
 *   402   -> erro ESPERADO (recusa): responder, NUNCA retentar         (Fase 2)
 *   sleep -> timeout: o app precisa de Http::timeout()                 (Fase 2)
 *   webhook duplicado (MESMO event_id) -> idempotencia de webhook      (Fase 3)
 *   webhook atrasado -> chega fora de ordem                            (Fase 3)
 *
 * Importante: este gateway NAO e idempotente, de proposito. Ele cobra toda vez
 * que for chamado. E assim que o teste "20 requisicoes = 1 cobranca" tem valor:
 * quem precisa garantir isso e o seu app, nao ele.
 *
 * Endpoints:
 *   GET  /health   -> ok + config de caos atual
 *   POST /charge   -> cobranca
 *   GET  /_stats   -> quantas cobrancas ele recebeu (total e por pedido)
 *   POST /_reset   -> zera os contadores
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

const STATE_FILE = '/tmp/gateway-state.json';

// --- helpers ---------------------------------------------------------------

function envInt(string $key, int $default): int
{
    $v = getenv($key);

    return ($v === false || $v === '') ? $default : (int) $v;
}

function envFloat(string $key, float $default): float
{
    $v = getenv($key);

    return ($v === false || $v === '') ? $default : (float) $v;
}

/**
 * Config do caos: a variavel de ambiente e o default, e o header X-Chaos-*
 * sobrescreve so naquela chamada. Isso evita restart do container para
 * testar um caso pontual ("esta chamada aqui tem que dar timeout").
 */
function chaos(string $name, float $default): float
{
    $header = 'HTTP_X_CHAOS_' . strtoupper(str_replace('-', '_', $name));

    if (isset($_SERVER[$header]) && $_SERVER[$header] !== '') {
        return (float) $_SERVER[$header];
    }

    return $default;
}

function chance(float $rate): bool
{
    return $rate > 0 && (mt_rand() / mt_getrandmax()) < $rate;
}

function requestId(): string
{
    return $_SERVER['HTTP_X_REQUEST_ID'] ?? '-';
}

function logLine(string $event, array $ctx = []): void
{
    // JSON no stderr: aparece em `docker compose logs gateway` e ja da para
    // cruzar com o log do app pelo request_id.
    $base = [
        'ts'         => gmdate('c'),
        'source'     => 'gateway',
        'event'      => $event,
        'request_id' => requestId(),
        // Fase 4: aqui e onde voce confere se o traceparent chegou do app.
        'traceparent' => $_SERVER['HTTP_TRACEPARENT'] ?? null,
    ];

    // php://stderr e nao STDERR: sob `php -S` o SAPI e cli-server, que nao
    // define as constantes STDIN/STDOUT/STDERR.
    file_put_contents('php://stderr', json_encode($base + $ctx, JSON_UNESCAPED_SLASHES) . "\n");
}

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('X-Gateway-Fake: 1');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit;
}

function readBody(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode((string) $raw, true);

    return is_array($json) ? $json : [];
}

/** Contadores com flock: o servidor roda varios processos (PHP_CLI_SERVER_WORKERS). */
function withState(callable $fn): array
{
    $fh = fopen(STATE_FILE, 'c+');

    if ($fh === false) {
        return [];
    }

    flock($fh, LOCK_EX);
    $raw = (string) stream_get_contents($fh);
    $state = json_decode($raw, true) ?: [
        'attempts_total' => 0,
        'approved_total' => 0,
        'declined_total' => 0,
        'failed_total'   => 0,
        'timeout_total'  => 0,
        'by_order'       => [],
    ];

    $state = $fn($state);

    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($state));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    return $state;
}

function countAttempt(string $orderId, string $outcome): void
{
    withState(static function (array $s) use ($orderId, $outcome): array {
        $s['attempts_total'] = ($s['attempts_total'] ?? 0) + 1;
        $s[$outcome . '_total'] = ($s[$outcome . '_total'] ?? 0) + 1;
        $s['by_order'][$orderId]['attempts'] = ($s['by_order'][$orderId]['attempts'] ?? 0) + 1;
        $s['by_order'][$orderId][$outcome] = ($s['by_order'][$orderId][$outcome] ?? 0) + 1;

        return $s;
    });
}

/**
 * Dispara o webhook em processo separado para nao bloquear a resposta.
 * O delay e o que faz o webhook chegar fora de ordem; as copias extras usam
 * o MESMO event_id, que e exatamente o caso que a Fase 3 tem que tratar.
 */
function scheduleWebhook(array $payload): void
{
    $job = [
        'url'      => getenv('GATEWAY_WEBHOOK_URL') ?: 'http://nginx/webhooks/payments',
        'delay_ms' => (int) chaos('webhook-delay-ms', (float) envInt('GATEWAY_WEBHOOK_DELAY_MS', 400)),
        'copies'   => 1 + (int) chaos('duplicate-webhook', (float) envInt('GATEWAY_DUPLICATE_WEBHOOK', 0)),
        'payload'  => $payload,
        // Fase 4: propagar o traceparent recebido fecha o trace no webhook.
        'traceparent' => $_SERVER['HTTP_TRACEPARENT'] ?? null,
        'request_id'  => requestId(),
    ];

    $arg = escapeshellarg(base64_encode((string) json_encode($job)));

    // stdout em /dev/null para o exec() nao esperar o processo; o stderr fica
    // herdado, entao o log do webhook aparece em `docker compose logs gateway`.
    exec('php /app/bin/send_webhook.php ' . $arg . ' >/dev/null &');
}

// --- roteamento ------------------------------------------------------------

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$config = [
    'latency_ms'        => (int) chaos('latency-ms', (float) envInt('GATEWAY_LATENCY_MS', 0)),
    'fail_rate'         => chaos('fail-rate', envFloat('GATEWAY_FAIL_RATE', 0)),
    'fail_status'       => (int) chaos('fail-status', (float) envInt('GATEWAY_FAIL_STATUS', 500)),
    'decline_rate'      => chaos('decline-rate', envFloat('GATEWAY_DECLINE_RATE', 0)),
    'timeout_rate'      => chaos('timeout-rate', envFloat('GATEWAY_TIMEOUT_RATE', 0)),
    'timeout_sleep_ms'  => (int) chaos('timeout-sleep-ms', (float) envInt('GATEWAY_TIMEOUT_SLEEP_MS', 30000)),
    'duplicate_webhook' => (int) chaos('duplicate-webhook', (float) envInt('GATEWAY_DUPLICATE_WEBHOOK', 0)),
    'webhook_delay_ms'  => (int) chaos('webhook-delay-ms', (float) envInt('GATEWAY_WEBHOOK_DELAY_MS', 400)),
];

if ($path === '/' && $method === 'GET') {
    respond(200, [
        'service'   => 'fake-payment-gateway',
        'endpoints' => ['GET /health', 'POST /charge', 'GET /_stats', 'POST /_reset'],
        'chaos'     => $config,
        'hint'      => 'Os headers X-Chaos-Fail-Rate, X-Chaos-Fail-Status, X-Chaos-Latency-Ms, '
            . 'X-Chaos-Timeout-Rate, X-Chaos-Decline-Rate e X-Chaos-Duplicate-Webhook '
            . 'sobrescrevem por chamada.',
    ]);
}

if ($path === '/health') {
    respond(200, ['status' => 'ok', 'chaos' => $config]);
}

if ($path === '/_stats' && $method === 'GET') {
    respond(200, withState(static fn (array $s): array => $s));
}

if ($path === '/_reset' && $method === 'POST') {
    @unlink(STATE_FILE);
    logLine('stats.reset');
    respond(200, ['status' => 'reset']);
}

if ($path === '/charge') {
    if ($method !== 'POST') {
        respond(405, ['error' => 'method_not_allowed']);
    }

    $in = readBody();
    $orderId = (string) ($in['order_id'] ?? '');
    $amount = (int) ($in['amount_cents'] ?? 0);

    // 400: erro do CLIENTE. Retentar isto e desperdicio, nunca vai mudar. (Fase 2)
    if ($orderId === '' || $amount <= 0) {
        logLine('charge.invalid', ['order_id' => $orderId, 'amount_cents' => $amount]);
        respond(400, [
            'error'   => 'invalid_request',
            'message' => 'order_id e amount_cents (> 0) sao obrigatorios',
        ]);
    }

    logLine('charge.received', [
        'order_id'        => $orderId,
        'amount_cents'    => $amount,
        'idempotency_key' => $in['idempotency_key'] ?? null,
        'chaos'           => $config,
    ]);

    if ($config['latency_ms'] > 0) {
        usleep($config['latency_ms'] * 1000);
    }

    // 1) Timeout: ele nao responde a tempo. Quem tem que se proteger e o cliente.
    if (chance($config['timeout_rate'])) {
        countAttempt($orderId, 'timeout');
        logLine('charge.hanging', ['order_id' => $orderId, 'sleep_ms' => $config['timeout_sleep_ms']]);
        usleep($config['timeout_sleep_ms'] * 1000);
        respond(504, ['error' => 'gateway_timeout', 'message' => 'demorei de proposito']);
    }

    // 2) Falha do lado do gateway. O STATUS e configuravel de proposito,
    //    porque os dois casos exigem decisoes OPOSTAS do cliente (Fase 2):
    //
    //      500  "explodi". Pode ter cobrado antes de explodir -> o cliente
    //           NAO pode retentar: e o caso INDETERMINADO.
    //      503  "estou fora, nao processei nada" -> retentar e seguro, e e
    //           o unico jeito de VER o retry acontecendo no /_stats.
    //
    //    GATEWAY_FAIL_STATUS=503 + GATEWAY_FAIL_RATE=1 e o cenario que mostra
    //    1 requisicao do cliente = N cobrancas tentadas.
    if (chance($config['fail_rate'])) {
        $status = $config['fail_status'];
        countAttempt($orderId, 'failed');
        logLine('charge.exploded', ['order_id' => $orderId, 'status' => $status]);
        respond($status, $status === 503
            ? ['error' => 'unavailable', 'message' => 'estou fora de proposito, nao processei nada']
            : ['error' => 'internal_error', 'message' => 'explodi de proposito']);
    }

    $paymentId = 'pay_' . bin2hex(random_bytes(8));
    $eventId = 'evt_' . bin2hex(random_bytes(8));

    // 3) Recusa: resposta LEGITIMA do gateway. Retentar nao muda nada.
    if (chance($config['decline_rate'])) {
        countAttempt($orderId, 'declined');
        logLine('charge.declined', ['order_id' => $orderId, 'payment_id' => $paymentId]);

        scheduleWebhook([
            'event_id'     => $eventId,
            'type'         => 'payment.failed',
            'payment_id'   => $paymentId,
            'order_id'     => $orderId,
            'amount_cents' => $amount,
            'status'       => 'declined',
            'occurred_at'  => gmdate('c'),
        ]);

        respond(402, [
            'error'        => 'card_declined',
            'status'       => 'declined',
            'decline_code' => 'insufficient_funds',
            'payment_id'   => $paymentId,
            'order_id'     => $orderId,
        ]);
    }

    // 4) Aprovado.
    countAttempt($orderId, 'approved');

    scheduleWebhook([
        'event_id'     => $eventId,
        'type'         => 'payment.succeeded',
        'payment_id'   => $paymentId,
        'order_id'     => $orderId,
        'amount_cents' => $amount,
        'status'       => 'approved',
        'occurred_at'  => gmdate('c'),
    ]);

    logLine('charge.approved', [
        'order_id'   => $orderId,
        'payment_id' => $paymentId,
        'event_id'   => $eventId,
        'webhooks'   => 1 + $config['duplicate_webhook'],
    ]);

    respond(200, [
        'payment_id'   => $paymentId,
        'status'       => 'approved',
        'order_id'     => $orderId,
        'amount_cents' => $amount,
        'charged_at'   => gmdate('c'),
    ]);
}

respond(404, ['error' => 'not_found', 'path' => $path]);
