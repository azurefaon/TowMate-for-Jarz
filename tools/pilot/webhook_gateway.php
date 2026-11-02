<?php

/*
 * Narrow front door for the PayMongo SANDBOX pilot. Point the HTTPS tunnel at THIS server, not at the
 * Laravel app, so the internet can reach exactly two things:
 *
 *   POST /api/webhooks/paymongo   forwarded untouched to the local Laravel app (body bytes and
 *                                 Paymongo-Signature header preserved, so the signature still verifies)
 *   GET  /pilot/return            a static "you can close this tab" page for the checkout's
 *                                 success/cancel redirects (it confirms nothing)
 *
 * Everything else, including the rest of the app, its API, login, and every other method or path
 * variant, gets a plain 404 here without ever reaching Laravel.
 *
 * Run (router script for PHP's built-in server):
 *   PILOT_TARGET=http://127.0.0.1:8001 php -S 127.0.0.1:8002 tools/pilot/webhook_gateway.php
 *
 * It holds no credentials and logs only "method path -> status": never a body, header or signature.
 */

const GATEWAY_MAX_BODY = 1_000_000;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$target = rtrim((string) getenv('PILOT_TARGET'), '/');

$reply = function (int $status, string $body, string $type = 'application/json') use ($method, $path): void {
    http_response_code($status);
    header_remove('X-Powered-By'); // no PHP version banner on the public door
    header('Content-Type: ' . $type);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo $body;
    error_log(sprintf('[pilot-gateway] %s %s -> %d', $method, $path, $status));
};

if ($method === 'GET' && $path === '/pilot/return') {
    $reply(200, "TowMate sandbox test: you can close this tab.\nThis page does not confirm any payment.\n", 'text/plain; charset=utf-8');

    return;
}

if ($method === 'POST' && $path === '/api/webhooks/paymongo' && $target !== '') {
    $body = (string) file_get_contents('php://input', false, null, 0, GATEWAY_MAX_BODY + 1);

    if (strlen($body) > GATEWAY_MAX_BODY) {
        $reply(413, '{"error":"too_large"}');

        return;
    }

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    $signature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? null;

    if (is_string($signature) && $signature !== '') {
        $headers[] = 'Paymongo-Signature: ' . $signature;
    }

    $curl = curl_init($target . '/api/webhooks/paymongo');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,          // the exact bytes received
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $contentType = strtolower((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE));
    curl_close($curl);

    // If the app is unreachable PayMongo gets a 5xx, which is the right signal for it to retry later.
    if ($response === false || $status === 0) {
        $reply(502, '{"error":"upstream_unavailable"}');

        return;
    }

    // The app's webhook answers are tiny JSON objects. Anything else (an HTML error page, a stack trace from a
    // debug build, a framework banner) is never relayed to the internet: the status survives, the body does not.
    $decoded = json_decode((string) $response, true);
    $relayable = str_starts_with($contentType, 'application/json') && is_array($decoded) && strlen((string) $response) <= 2048;

    $reply($status, $relayable ? (string) $response : '{"error":"upstream_error"}');

    return;
}

$reply(404, '{"error":"not_found"}');
