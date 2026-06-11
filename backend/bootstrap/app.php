<?php

use App\Http\Middleware\EnsureStoreAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        $middleware->alias([
            'store.access' => EnsureStoreAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Return JSON for all abort() calls on API routes
        $exceptions->render(function (HttpException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage() ?: match ($e->getStatusCode()) {
                        400 => 'Bad request.',
                        401 => 'Unauthenticated.',
                        403 => 'Forbidden.',
                        404 => 'Not found.',
                        422 => 'Unprocessable entity.',
                        500 => 'Server error.',
                        default => 'An error occurred.',
                    },
                ], $e->getStatusCode());
            }
        });
    })
    ->create();