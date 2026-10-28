<?php

namespace App\Http\Middleware;

use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** "module:talent" — the page belongs to an add-on the license must include. */
class EnsureModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(Modules::enabled($module), 403, 'Modul ini belum termasuk dalam lisensi Anda.');

        return $next($request);
    }
}
