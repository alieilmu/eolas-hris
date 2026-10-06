<?php

namespace App\Http\Middleware;

use App\Support\Access\AccessScope;
use App\Support\Access\Menu;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "menu:roster" — reading (GET) needs the menu at "view", changing anything
 * needs "edit". Several menus: any of them will do. "@type" takes the menu from
 * the route parameter "type". Pages of a scoped menu only see the account's
 * locations (AccessScope).
 */
class MenuAccess
{
    public function handle(Request $request, Closure $next, string ...$menus): Response
    {
        $menus = array_map(fn (string $m) => str_starts_with($m, '@') ? (string) $request->route(substr($m, 1)) : $m, $menus);
        $user = $request->user();
        $allowed = $user && ($request->isMethodSafe() ? $user->canView(...$menus) : $user->canEdit(...$menus));
        abort_unless($allowed, 403);

        if (array_intersect($menus, Menu::SCOPED)) {
            AccessScope::enable();
        }

        return $next($request);
    }
}
