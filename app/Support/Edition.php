<?php

namespace App\Support;

/**
 * Which Eolas this installation is: "saas" (the hosted demo service: landing page, demo
 * requests, a workspace per demo account) or "client" (one company on its own server, under
 * a license: no landing page, no demo requests).
 */
class Edition
{
    public static function client(): bool
    {
        return config('eolas.edition') === 'client';
    }
}
