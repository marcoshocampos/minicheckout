<?php
/**
 * Entregador de webhook, rodado em processo separado pelo gateway.
 *
 * As copias extras repetem o MESMO event_id de proposito: e o cenario real
 * (o gateway nao sabe se voce recebeu, entao manda de novo) e e o que a
 * Fase 3 resolve com UNIQUE no banco, nao com um `if`.
 */

declare(strict_types=1);

$decoded = base64_decode($argv[1] ?? '', true);
$job = is_string($decoded) ? json_decode($decoded, true) : null;

if (! is_array($job)) {
    file_put_contents('php://stderr', "[webhook] payload invalido\n");
    exit(1);
}

$url = (string) $job['url'];
$payload = (string) json_encode($job['payload'], JSON_UNESCAPED_SLASHES);
$copies = max(1, (int) ($job['copies'] ?? 1));

usleep(max(0, (int) ($job['delay_ms'] ?? 0)) * 1000);

for ($i = 1; $i <= $copies; $i++) {
    $headers = [
        'Content-Type: application/json',
        'X-Gateway-Event-Id: ' . ($job['payload']['event_id'] ?? '-'),
        'X-Gateway-Delivery: ' . $i . '/' . $copies,
        'X-Request-Id: ' . ($job['request_id'] ?? '-'),
    ];

    if (! empty($job['traceparent'])) {
        $headers[] = 'traceparent: ' . $job['traceparent'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    file_put_contents('php://stderr', json_encode([
        'ts'         => gmdate('c'),
        'source'     => 'gateway',
        'event'      => 'webhook.sent',
        'request_id' => $job['request_id'] ?? '-',
        'event_id'   => $job['payload']['event_id'] ?? null,
        'order_id'   => $job['payload']['order_id'] ?? null,
        'delivery'   => $i . '/' . $copies,
        'url'        => $url,
        'status'     => $status,
        'error'      => $error !== '' ? $error : null,
        'response'   => is_string($body) ? mb_substr($body, 0, 200) : null,
    ], JSON_UNESCAPED_SLASHES) . "\n");

    if ($i < $copies) {
        usleep(150 * 1000);
    }
}
