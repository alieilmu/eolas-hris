<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\UseTenant;
use App\Models\DemoAccount;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(Request $request): Response
    {
        // A link from the demo request ("Masuk ke workspace") fills in its code.
        return Inertia::render('Auth/Login', ['workspace' => $request->string('ws')->limit(16, '')->toString() ?: null]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();
        // The demo workspace this account lives in (UseTenant opens it on every request).
        $request->session()->put(UseTenant::SESSION, $request->tenant);
        if ($request->tenant) {
            DemoAccount::where('database', $request->tenant)->update(['last_login_at' => now()]);
            if ($request->boolean('remember')) {
                Cookie::queue(Cookie::forever(UseTenant::COOKIE, $request->tenant));
            }
        }

        return redirect()->intended('/');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Cookie::queue(Cookie::forget(UseTenant::COOKIE));

        return redirect()->route('login');
    }
}
