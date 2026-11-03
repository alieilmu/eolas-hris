<?php

namespace App\Http\Middleware;

use App\Support\Security\AppLock;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The installed app on a phone: when the long sign-in brings the account back (the short
 * session ran out), or the app asked to be locked on opening, nothing shows until the PIN
 * or the phone's fingerprint / face is given. Without either set, the long sign-in ends.
 */
class AppUnlock
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && Auth::guard('web')->viaRemember() && $request->cookies->has(AppLock::COOKIE)) {
            $request->session()->put(AppLock::SESSION_DEVICE, true);
            if (! AppLock::canUnlock($user)) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                Cookie::queue(Cookie::forget(AppLock::COOKIE));

                return redirect()->route('login')->withErrors(['email' => 'Sesi aplikasi berakhir. Masuk lagi, lalu buat PIN agar tetap masuk di HP ini.']);
            }
            AppLock::lock($request);
        }

        if ($user && $request->session()->get(AppLock::SESSION_LOCKED) && ! $request->routeIs('app-lock*', 'logout')) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => 'Aplikasi terkunci. Buka dengan PIN.'], 423);
            }
            if ($request->isMethod('GET') && ! $request->header('X-Inertia-Partial-Data')) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return redirect()->route('app-lock');
        }

        return $next($request);
    }
}
