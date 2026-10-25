<?php

namespace App\Http\Controllers;

use App\Support\LegacyUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A page URL from before the terminology refactor: 301 to where it lives now, query string kept. */
class LegacyRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        // By name: the "old" default would otherwise take the place of the {rest} segment.
        $query = $request->getQueryString();

        return redirect(LegacyUrls::to($request->route('old'), $request->route('rest')).($query ? "?{$query}" : ''), 301);
    }
}
