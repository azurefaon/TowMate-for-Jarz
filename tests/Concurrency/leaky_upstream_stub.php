<?php

/*
 * A deliberately misbehaving "app" for the pilot-gateway test: it answers like a Laravel debug build would
 * (an HTML page full of internals, with server banners) so the test can prove the gateway never relays that
 * to the internet. Local only; the "secrets" below are obviously fake markers.
 */

// Chosen per process by the test (the gateway deliberately forwards no custom request headers).
$mode = getenv('STUB_MODE') ?: 'trace';

if ($mode === 'big-json') {
    header('Content-Type: application/json');
    echo json_encode(['padding' => str_repeat('x', 5000)]);

    return;
}

if ($mode === 'text') {
    header('Content-Type: text/plain');
    echo 'DB_PASSWORD=SECRET_MARKER_PASSWORD';

    return;
}

http_response_code(500);
header('Content-Type: text/html');
header('X-Powered-By: PHP/9.9.9-leaky');
header('Server: leaky-app/1.0');
echo '<html><body><h1>ErrorException</h1><pre>Stack trace: #0 /var/www/app/Services/Secret.php(12) '
    . 'APP_KEY=base64:SECRET_MARKER_APPKEY DB_PASSWORD=SECRET_MARKER_PASSWORD</pre></body></html>';
