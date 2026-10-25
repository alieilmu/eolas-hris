<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A deactivated account is signed out; one with a first-time (or reset)
 * password must choose its own before anything else.
 */
class EnsureAccountReady
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }
        if (! $user->is_active || ($user->isEmployee() && (! $user->employee || $user->employee->status === Employee::STATUS_TERMINATED))) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Akun ini tidak aktif. Hubungi HR.']);
        }
        if ($user->must_change_password && ! $request->routeIs('account.password*', 'logout')) {
            return redirect()->route('account.password');
        }

        return $next($request);
    }
}
