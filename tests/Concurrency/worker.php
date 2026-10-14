<?php

// One concurrent session for tests/Concurrency: boots the app in its own PHP
// process (so it has its own Postgres connection), sends one real HTTP
// request through the kernel as the given user, lets the app terminate (which
// flushes queued audit entries), and prints the outcome as JSON.
//
// Usage: php worker.php <base64 json job>
// job: {actor_id, method, uri, data, json, app_name}

$job = json_decode(base64_decode($argv[1] ?? ''), true);

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Names this session so the test's pause trigger can recognise it.
config(['database.connections.pgsql.application_name' => $job['app_name'] ?? 'cc_worker']);
Illuminate\Support\Facades\DB::purge('pgsql');

// An artisan command instead of an HTTP request: job.artisan = "name" (job.data = options).
if (! empty($job['artisan'])) {
    $console = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $output = new Symfony\Component\Console\Output\BufferedOutput();
    $exit = $console->call($job['artisan'], $job['data'] ?? [], $output);

    echo "\n@@RESULT@@" . json_encode(['status' => $exit === 0 ? 200 : 500, 'exit' => $exit, 'output' => trim($output->fetch()), 'body' => null, 'success' => null, 'errors' => [], 'exception' => null]) . "\n";
    exit(0);
}

$app['auth']->guard('web')->setUser(App\Models\User::findOrFail($job['actor_id']));

$server = ['HTTP_ACCEPT' => $job['json'] ? 'application/json' : 'text/html'];
$content = null;
if ($job['json'] && $job['method'] !== 'GET') {
    $server['CONTENT_TYPE'] = 'application/json';
    $content = json_encode($job['data']);
}

$request = Illuminate\Http\Request::create($job['uri'], $job['method'], $job['json'] ? [] : $job['data'], [], [], $server, $content);
$response = $kernel->handle($request);

$session = $app['session.store'];
$errors = $session->get('errors');

$result = [
    'status' => $response->getStatusCode(),
    'body' => json_decode((string) $response->getContent(), true),
    'success' => $session->get('success'),
    'errors' => $errors ? $errors->getBag('default')->all() : [],
    'exception' => $response->exception ? get_class($response->exception) . ': ' . $response->exception->getMessage() : null,
];

$kernel->terminate($request, $response);

echo "\n@@RESULT@@" . json_encode($result) . "\n";
