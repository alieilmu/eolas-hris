<?php

namespace App\Http\Middleware;

use App\Support\Security\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** An account with two-factor sign-in gives its code after the password, before anything else. */
class TwoFactorChallenge
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->hasTwoFactor() && $request->session()->get(TwoFactor::SESSION_PASSED) !== true
            && ! $request->routeIs('two-factor.challenge*', 'logout')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Masukkan kode 2FA.'], 403);
            }
            if ($request->isMethod('GET') && ! $request->header('X-Inertia-Partial-Data')) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return redirect()->route('two-factor.challenge');
        }

        return $next($request);
    }
}
