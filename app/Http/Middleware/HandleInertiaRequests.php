<?php

namespace App\Http\Middleware;

use App\Models\Branding;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            // White label: names, logos and colours (Settings → White Label).
            'branding' => fn () => Branding::current()->toShare(),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'role_label' => User::ROLE_LABELS[$user->role] ?? $user->role,
                    'karyawan_id' => $user->karyawan_id,
                ] : null,
                // UI hints only; every route and controller still authorizes on its own.
                'can' => [
                    'viewSensitiveData' => (bool) $user?->canAccessSensitiveData(),
                    'manageUsers' => (bool) $user?->isAdmin(),
                ],
                // Menu => "view" | "edit", for the sidebar and the edit buttons.
                'menus' => (object) ($user?->menus() ?? []),
            ],
        ];
    }
}
