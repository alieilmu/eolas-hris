<?php

use App\Http\Middleware\CentralOnly;
use App\Http\Middleware\EnableAccessScope;
use App\Http\Middleware\EnsureAccountReady;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\MenuAccess;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\EnsureLicensed;
use App\Http\Middleware\SaasOnly;
use App\Http\Middleware\TwoFactorChallenge;
use App\Http\Middleware\UseTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Attendance machines (ADMS): outside the web group, without session and CSRF.
        then: fn () => Illuminate\Support\Facades\Route::group([], base_path('routes/iclock.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only Traefik (Docker network) and the host nginx reach the app (port bound to 127.0.0.1):
        // trust their X-Forwarded-* so the visitor's real address is seen (rate limits, machines).
        // A client installation sits behind its own Caddy on a Docker network (any private range).
        $middleware->trustProxies(at: env('TRUSTED_PROXIES') ? explode(',', env('TRUSTED_PROXIES')) : ['127.0.0.1', '172.16.0.0/12']);

        $middleware->web(append: [
            UseTenant::class,
            HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            // menu:roster — view to read, edit to change (Settings → Akun & Akses).
            'menu' => MenuAccess::class,
            'account.ready' => EnsureAccountReady::class,
            'scoped' => EnableAccessScope::class,
            'central' => CentralOnly::class,
            // An account with 2FA gives its code after the password; payroll pages need 2FA.
            'two-factor' => TwoFactorChallenge::class,
            'two-factor.required' => RequireTwoFactor::class,
            // Client edition: a license is needed; the demo service's pages are not there.
            'licensed' => EnsureLicensed::class,
            'saas' => SaasOnly::class,
        ]);
        // The demo workspace is opened right after the session starts, before anyone is authenticated.
        $middleware->prependToPriorityList(before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, prepend: UseTenant::class);
        // Access is settled before route-model binding, so a {karyawan} outside the account's locations is not found.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: MenuAccess::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnableAccessScope::class);

        // Guests hitting a protected page go to route('login'); signed-in users skip the login page.
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
