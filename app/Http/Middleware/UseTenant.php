<?php

namespace App\Http\Middleware;

use App\Models\DemoAccount;
use App\Support\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens the demo workspace of the signed-in account (session "tenant", or the
 * remember-me cookie of the workspace) before anything reads the database. A workspace
 * that was switched off, is being rebuilt or has run out signs the visitor out.
 */
class UseTenant
{
    public const SESSION = 'tenant';

    public const COOKIE = 'eloas_ws';

    public function handle(Request $request, Closure $next): Response
    {
        // The cookie only stands in for an expired session of a "remember me" sign-in.
        $remembered = $request->cookies->has(Auth::guard('web')->getRecallerName());
        $database = $request->session()->get(self::SESSION) ?? ($remembered ? $request->cookie(self::COOKIE) : null);
        if (! $database) {
            return $next($request);
        }

        $account = DemoAccount::where('database', $database)->first();
        if (! $account || ! $account->usable()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Cookie::queue(Cookie::forget(self::COOKIE));

            return redirect()->route('login')->withErrors(['email' => $account?->blockedReason() ?? 'Workspace demo tidak ditemukan.']);
        }

        $request->session()->put(self::SESSION, $database);
        Tenant::use($database);
        app()->instance(DemoAccount::class, $account);

        return $next($request);
    }
}
