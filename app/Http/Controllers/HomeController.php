<?php

namespace App\Http\Controllers;

use App\Support\Access\Menu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Where "/" leads: the employee portal, or the first menu the account may open. */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|Response
    {
        $user = $request->user();
        if ($user->isKaryawan()) {
            return redirect()->route('portal');
        }
        foreach (Menu::HOME as $menu => $url) {
            if ($user->canView($menu)) {
                return redirect($url);
            }
        }

        return $user->isAdmin() ? redirect()->route('settings.users') : Inertia::render('Error', ['status' => 403]);
    }
}
