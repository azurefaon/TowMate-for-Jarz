<?php

/**
 * Team Leader public folder bridge.
 *
 * When `/teamleader` is requested directly, redirect once to the real
 * dashboard route. For deeper paths like `/teamleader/dashboard`, hand
 * the request back to Laravel so it does not loop inside this folder.
 */

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/teamleader', PHP_URL_PATH) ?: '/teamleader';
$normalizedPath = rtrim($requestPath, '/');

if ($normalizedPath === '/teamleader') {
    header('Location: /teamleader/dashboard', true, 302);
    exit;
}

require dirname(__DIR__) . '/index.php';
