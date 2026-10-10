<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionDemoWorkspace;
use App\Models\DemoAccount;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → Akun Demo (main application, Super Admin): the requests and their workspaces. */
class DemoAccountController extends Controller
{
    public function index(): Response
    {
        $accounts = DemoAccount::latest()->get();

        return Inertia::render('Settings/DemoAccounts', [
            'accounts' => $accounts->map->toShare(),
            'auto_approve' => config('eloas.demo_auto_approve'),
            'demo_days' => config('eloas.demo_days'),
            'stats' => [
                'total' => $accounts->count(),
                'aktif' => $accounts->filter->usable()->count(),
                'menunggu' => $accounts->where('status', 'menunggu')->count(),
                'demo' => $accounts->where('sumber_data', 'demo')->count(),
                'kosong' => $accounts->where('sumber_data', 'kosong')->count(),
            ],
        ]);
    }

    /** Approves a waiting request (or retries a failed one): its workspace is prepared. */
    public function approve(DemoAccount $account): RedirectResponse
    {
        abort_unless(in_array($account->status, ['menunggu', 'gagal'], true), 422);
        $account->update(['status' => 'menyiapkan', 'approved_at' => $account->approved_at ?? now()]);
        ProvisionDemoWorkspace::dispatch($account->id);

        Inertia::flash('success', "Workspace {$account->perusahaan} sedang disiapkan.");

        return back();
    }

    public function extend(Request $request, DemoAccount $account): RedirectResponse
    {
        $days = $request->validate(['hari' => ['required', 'integer', 'min:1', 'max:365']])['hari'];
        $from = $account->expires_at && $account->expires_at->isFuture() ? $account->expires_at : now();
        $account->update(['expires_at' => $from->copy()->addDays($days)->endOfDay()]);

        Inertia::flash('success', "Masa demo {$account->perusahaan} diperpanjang {$days} hari.");

        return back();
    }

    public function toggle(DemoAccount $account): RedirectResponse
    {
        abort_unless(in_array($account->status, ['aktif', 'nonaktif'], true), 422);
        $account->update(['status' => $account->status === 'aktif' ? 'nonaktif' : 'aktif']);

        Inertia::flash('success', $account->status === 'aktif' ? 'Akun demo diaktifkan.' : 'Akun demo dinonaktifkan.');

        return back();
    }

    /** Rebuilds the workspace with the chosen data (everything entered there is lost). */
    public function reset(Request $request, DemoAccount $account): RedirectResponse
    {
        $sumber = $request->validate(['sumber_data' => ['required', Rule::in(array_keys(DemoAccount::SUMBER))]])['sumber_data'];
        abort_if($account->status === 'menyiapkan' || $account->status === 'menunggu', 422);
        $account->update(['sumber_data' => $sumber, 'status' => 'menyiapkan']);
        ProvisionDemoWorkspace::dispatch($account->id);

        Inertia::flash('success', "Workspace {$account->perusahaan} disiapkan ulang dengan ".DemoAccount::SUMBER[$sumber].'.');

        return back();
    }

    public function destroy(DemoAccount $account): RedirectResponse
    {
        abort_if($account->status === 'menyiapkan', 422);
        Tenant::drop(Tenant::databaseFor($account));
        $account->delete();

        Inertia::flash('success', 'Akun demo dan workspace-nya dihapus.');

        return back();
    }
}
