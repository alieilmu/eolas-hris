<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Http\Middleware\UseTenant;
use App\Jobs\ProvisionDemoWorkspace;
use App\Models\DemoAccount;
use App\Support\Tenancy\Tenant;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

/**
 * Inside a demo workspace, its Super Admin switches the data: the system's demo data
 * again, or empty for their own. The workspace is rebuilt, so they sign in again after.
 */
class WorkspaceController extends Controller
{
    public function data(Request $request): Response
    {
        $account = Tenant::account();
        abort_unless($account, 404);
        $sumber = $request->validate([
            'sumber_data' => ['required', Rule::in(array_keys(DemoAccount::SUMBER))],
            'konfirmasi' => ['required', 'in:SETUJU'],
        ], ['konfirmasi.in' => 'Ketik SETUJU untuk melanjutkan.'])['sumber_data'];

        $account->update(['sumber_data' => $sumber, 'status' => 'menyiapkan']);
        ProvisionDemoWorkspace::dispatch($account->id);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->forget(UseTenant::SESSION);
        Cookie::queue(Cookie::forget(UseTenant::COOKIE));

        return Inertia::location(route('demo.status', $account->kode));
    }
}
