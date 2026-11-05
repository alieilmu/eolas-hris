<?php

namespace App\Http\Middleware;

use App\Models\Branding;
use App\Models\User;
use App\Models\WorkspaceSetup;
use App\Support\Modules;
use App\Support\Push\WebPush;
use App\Support\Security\AppLock;
use App\Support\Tenancy\Tenant;
use App\Support\Workflow\Workflow;
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
            // Sesi awal of a workspace for the company's own data (Super Admin only).
            'setup' => fn () => $user?->isAdmin() ? WorkspaceSetup::current()?->status : null,
            // "client": a company's own installation (license page, no demo pages).
            'edition' => config('eolas.edition'),
            // The packages this installation may use (Modules): menus of the others stay hidden.
            'modules' => fn () => Modules::active(),
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
                // Unread notifications (the bell): pengajuan for HR; results, slips, certificates for employees.
                'notifications' => $user ? rescue(fn () => $user->unreadNotifications()->count(), 0) : 0,
                // Web Push: the server's key, for the phone to keep its subscription fresh (null: off).
                'push' => $user && WebPush::enabled() ? rescue(fn () => WebPush::publicKey(), null) : null,
                // Pengajuan waiting for this account's decision (Persetujuan Saya), admins and employees alike.
                'approvals' => $user ? rescue(fn () => Workflow::pendingFor($user)->count(), 0, report: false) : 0,
                // Menu => "view" | "edit", for the sidebar and the edit buttons.
                'menus' => (object) ($user?->menus() ?? []),
                // Signed in from the installed app on a phone: how it is opened again (null in a browser).
                'app' => rescue(fn () => AppLock::share($request, $user), null, report: false),
            ],
        ];
    }
}
