<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Pages of the main application only (e.g. the demo accounts), never inside a demo workspace. */
class CentralOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(Tenant::current() !== null, 404);

        return $next($request);
    }
}
