<?php

namespace App\Http\Middleware;

use App\Support\Edition;
use App\Support\License\License;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A client installation without a valid license opens only the license page (and the account's own pages). */
class EnsureLicensed
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Edition::client() && $request->user() && ! License::valid()
            && ! $request->routeIs('settings.license*', 'logout', 'account.*', 'two-factor.*')) {
            return $request->expectsJson() ? response()->json(['message' => License::check()['reason']], 403) : redirect()->route('settings.license');
        }

        return $next($request);
    }
}
