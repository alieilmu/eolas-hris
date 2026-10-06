<?php

namespace App\Http\Middleware;

use App\Support\Access\AccessScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Narrows employee queries to what the account may see (the employee portal: only themselves). */
class EnableAccessScope
{
    public function handle(Request $request, Closure $next): Response
    {
        AccessScope::enable();

        return $next($request);
    }
}
