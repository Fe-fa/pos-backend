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
        // Prepend CORS middleware to the API stack
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Register all your route middleware aliases here
        $middleware->alias([
            'store.access' => EnsureStoreAccess::class,
            'mpesa.callback' => \App\Http\Middleware\VerifyMpesaCallback::class,
        ]);

        // Exempt M-Pesa callbacks from CSRF (they're stateless POSTs from Safaricom)
        $middleware->validateCsrfTokens(except: [
            'api/mpesa/callbacks/*',
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