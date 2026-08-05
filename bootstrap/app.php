<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // EnsureFrontendRequestsAreStateful removed on purpose — that's
        // what made auth:sanctum accept cookie/session auth for "stateful"
        // domains. We're on token auth now (Bearer tokens via
        // createToken()), which doesn't need it and doesn't depend on
        // cross-site cookies working at all.
        $middleware->api(prepend: [
            \App\Http\Middleware\UpdateLastSeen::class,
        ]);

        // ✅ Combine all aliases in one array
        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();