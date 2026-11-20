<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\Localize;
use App\Http\Middleware\RequireInstallation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Web installer gate first: before installation everything leads to /install.
        $middleware->web(prepend: [RequireInstallation::class], append: [Localize::class]);
        $middleware->alias(['admin' => EnsureAdmin::class]);
        // Browser time zone cookie is set by JS and read as a plain value.
        $middleware->encryptCookies(except: ['tz']);
        // Gateways and billing.twsd.me post webhooks without a CSRF token (they are signature-verified).
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
