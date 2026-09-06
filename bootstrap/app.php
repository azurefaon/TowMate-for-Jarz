<?php

use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        channels: __DIR__.'/../routes/channels.php',
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role'               => RoleMiddleware::class,
            'force.password.change' => \App\Http\Middleware\ForcePasswordChange::class,
            'tl'                 => \App\Http\Middleware\EnsureTeamLeader::class,
            'password_changed'   => \App\Http\Middleware\EnsurePasswordChanged::class,
            'touch.dispatcher.presence' => \App\Http\Middleware\TouchDispatcherPresence::class,
        ]);

        $middleware->append(\App\Http\Middleware\TrustProxies::class);
        $middleware->web(append: [\App\Http\Middleware\SecurityHeaders::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (config('app.debug')) {
                return null;
            }

            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            if ($status !== 500) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong. Please try again later.',
            ], 500);
        });
    })->create();
