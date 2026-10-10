<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(
            [
                'admin' => \App\Http\Middleware\CheckUserIsAdmin::class,
                'agency' => \App\Http\Middleware\EnsureCurrentAgency::class,
            ],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A member whose membership ended while the page was open: 403 like every other refusal, never a 500.
        $exceptions->map(\App\Gestionale\MissingAgencyContext::class, fn (\App\Gestionale\MissingAgencyContext $e) => new \Symfony\Component\HttpKernel\Exception\HttpException(403, $e->getMessage()));
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
