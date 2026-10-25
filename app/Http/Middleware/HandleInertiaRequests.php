<?php

namespace App\Http\Middleware;

use App\Models\Branding;
use App\Models\User;
use App\Support\Tenancy\Tenant;
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
            // Eolas: the demo workspace this account works in (null in the main application).
            'workspace' => fn () => ($a = Tenant::account()) ? collect($a->toShare())->only(['code', 'company_name', 'data_source', 'source_label', 'expires_at', 'days_left'])->all() : null,
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'role_label' => User::ROLE_LABELS[$user->role] ?? $user->role,
                    'employee_id' => $user->employee_id,
                ] : null,
                // UI hints only; every route and controller still authorizes on its own.
                'can' => [
                    'viewSensitiveData' => (bool) $user?->canAccessSensitiveData(),
                    'manageUsers' => (bool) $user?->isAdmin(),
                ],
                // Unread pengajuan notifications (the bell); employees have none.
                'notifications' => $user && ! $user->isEmployee() ? rescue(fn () => $user->unreadNotifications()->count(), 0) : 0,
                // Menu => "view" | "edit", for the sidebar and the edit buttons.
                'menus' => (object) ($user?->menus() ?? []),
            ],
        ];
    }
}
