<?php

use App\Http\Middleware\EnableAccessScope;
use App\Http\Middleware\EnsureAccountReady;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\MenuAccess;
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
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            // menu:roster — view to read, edit to change (Settings → Akun & Akses).
            'menu' => MenuAccess::class,
            'account.ready' => EnsureAccountReady::class,
            'scoped' => EnableAccessScope::class,
        ]);
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
