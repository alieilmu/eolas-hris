<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\UseTenant;
use App\Models\TrialAccount;
use App\Http\Requests\Auth\LoginRequest;
use App\Rules\Turnstile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(Request $request): Response
    {
        // A link from the demo request ("Masuk ke workspace") fills in its code.
        return Inertia::render('Auth/Login', [
            'workspace' => $request->string('ws')->limit(16, '')->toString() ?: null,
            'turnstile_site_key' => Turnstile::enabled() ? config('services.turnstile.site_key') : null,
        ]);
    }

    public function store(LoginRequest $request): HttpResponse
    {
        $request->authenticate();

        $request->session()->regenerate();
        // The demo workspace this account lives in (UseTenant opens it on every request).
        $request->session()->put(UseTenant::SESSION, $request->tenant);
        if ($request->tenant) {
            TrialAccount::where('database', $request->tenant)->update(['last_login_at' => now()]);
            if ($request->boolean('remember')) {
                Cookie::queue(Cookie::forever(UseTenant::COOKIE, $request->tenant));
            }
        }

        // A full page load: the theme, logo and favicon in the page head are the account's (main app or workspace).
        return Inertia::location(redirect()->intended('/')->getTargetUrl());
    }

    public function destroy(Request $request): HttpResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Cookie::queue(Cookie::forget(UseTenant::COOKIE));

        return Inertia::location(route('login'));
    }
}
