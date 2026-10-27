<?php

namespace App\Http\Middleware;

use App\Support\Security\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Payroll pages need two-factor sign-in: an account without it is sent to set it up first.
 * In a demo workspace it may be skipped for the session.
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $user->hasTwoFactor() && TwoFactor::required($user)
            && ! (TwoFactor::skippable() && $request->session()->get(TwoFactor::SESSION_SKIPPED) === true)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Aktifkan 2FA untuk membuka data payroll.'], 403);
            }
            $request->session()->put('url.intended', $request->fullUrl());
            Inertia::flash('error', 'Data payroll hanya bisa dibuka dengan verifikasi 2 langkah (2FA). Aktifkan dulu di sini.');

            return redirect()->route('account.two-factor');
        }

        return $next($request);
    }
}
