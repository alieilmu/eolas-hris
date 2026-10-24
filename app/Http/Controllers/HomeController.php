<?php

namespace App\Http\Controllers;

use App\Support\Access\Menu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where "/" leads: visitors see the Eolas landing page; employees go to the portal,
 * admins to the dashboard (or, failing that, the first menu the account may open).
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|Response
    {
        $user = $request->user();
        if (! $user) {
            return Inertia::render('Landing', ['demo_days' => config('eolas.demo_days')]);
        }
        if ($user->isKaryawan()) {
            return redirect()->route('portal');
        }
        if (! $user->must_change_password) {
            return redirect()->route('dashboard');
        }
        foreach (Menu::HOME as $menu => $url) {
            if ($user->canView($menu)) {
                return redirect($url);
            }
        }

        return $user->isAdmin() ? redirect()->route('settings.users') : Inertia::render('Error', ['status' => 403]);
    }
}
