<?php

/*
 * A minimal stand-in for PayMongo's create-checkout-session endpoint, for the concurrency tests only
 * (run with: php -S 127.0.0.1:<port> paymongo_stub.php). It never leaves the machine, holds no
 * credentials, and counts how many sessions were created so a test can prove a race opened only one.
 */

header('Content-Type: application/json');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$counterFile = getenv('PMS_STUB_COUNTER');

if ($path === '/v2/checkout_sessions' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $counterFile) {
    $handle = fopen($counterFile, 'c+');
    flock($handle, LOCK_EX);
    $count = (int) stream_get_contents($handle) + 1;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) $count);
    flock($handle, LOCK_UN);
    fclose($handle);

    usleep(300_000); // keep the request in flight long enough for rivals to pile up behind it

    echo json_encode(['data' => [
        'id' => "cs_test_stub_{$count}",
        'type' => 'checkout_session',
        'attributes' => ['checkout_url' => "https://checkout.stub.invalid/cs_test_stub_{$count}", 'livemode' => false],
    ]]);

    return;
}

http_response_code(404);
echo '{}';
