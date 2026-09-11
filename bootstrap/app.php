<?php

use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\RecordUserActivity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    // Keep HTTP, console, and broadcast entry points in the framework's
    // standard bootstrap so every environment uses the same route registry.
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        // Broadcast authentication uses the same account and activity checks
        // as protected API requests before a private channel is authorized.
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum', EnsureActiveAccount::class, RecordUserActivity::class]],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Aliases keep route declarations readable while centralizing the
        // middleware classes that enforce account and admin boundaries.
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'active.account' => EnsureActiveAccount::class,
            'record.user.activity' => RecordUserActivity::class,
            'idempotent' => EnsureIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
