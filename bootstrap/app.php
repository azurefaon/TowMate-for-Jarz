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
            'account.active'     => \App\Http\Middleware\EnsureAccountActive::class,
            'idle.timeout'       => \App\Http\Middleware\EnforceIdleTimeout::class,
            'touch.dispatcher.presence' => \App\Http\Middleware\TouchDispatcherPresence::class,
        ]);

        $middleware->append(\App\Http\Middleware\TrustProxies::class);
        $middleware->web(append: [\App\Http\Middleware\SecurityHeaders::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                if ($e instanceof \Illuminate\Routing\Exceptions\InvalidSignatureException) {
                    return response()->view('errors.invalid-link', [], 403);
                }

                $isMissingRecord = $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                    || $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

                if (
                    $isMissingRecord
                    && str_starts_with((string) $request->route()?->getName(), 'quotation.')
                    && ! $request->hasValidSignature()
                ) {
                    return response()->view('errors.invalid-link', [], 403);
                }

                return null;
            }

            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], $e->status);
            }

            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return response()->json(['message' => $e->getMessage()], 401);
            }

            $safeMessage = function (int $status, ?string $message): string {
                $frameworkDefaults = [
                    '', 'forbidden', 'not found', 'server error', 'too many requests', 'too many attempts.',
                    'this action is unauthorized.', 'invalid signature.', 'unauthenticated.',
                    'page expired', 'method not allowed', 'bad request', 'service unavailable',
                ];

                $normalized = strtolower(trim((string) $message));

                if (! in_array($normalized, $frameworkDefaults, true) && ! str_starts_with($normalized, 'no query results')) {
                    return (string) $message;
                }

                return match (true) {
                    $status === 403 => "You don't have permission to do this.",
                    $status === 404 => "We couldn't find what you were looking for.",
                    $status === 419 => 'Your session has expired. Please refresh the page and try again.',
                    $status === 429 => 'Too many attempts. Please wait a moment and try again.',
                    $status >= 500 => "We couldn't complete your request right now. Please try again.",
                    default => 'Request failed.',
                };
            };

            if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                $status = $e->status() ?? 403;

                return response()->json(['message' => $safeMessage($status, $e->getMessage())], $status);
            }

            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return response()->json(['message' => $safeMessage(404, null)], 404);
            }

            if ($e instanceof HttpExceptionInterface) {
                return response()->json([
                    'message' => $safeMessage($e->getStatusCode(), $e->getMessage()),
                ], $e->getStatusCode(), $e->getHeaders());
            }

            if (! config('app.debug')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Something went wrong. Please try again later.',
                ], 500);
            }

            return null;
        });
    })->create();
