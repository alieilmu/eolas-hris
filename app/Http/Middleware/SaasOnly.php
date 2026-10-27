<?php

namespace App\Http\Middleware;

use App\Support\Edition;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Pages of the hosted demo service only (demo requests, demo accounts): not in a client installation. */
class SaasOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(Edition::client(), 404);

        return $next($request);
    }
}
