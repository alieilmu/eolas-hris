<?php

namespace App\Http\Controllers\Trial;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionDemoWorkspace;
use App\Models\TrialAccount;
use App\Support\Tenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → Akun Demo (main application, Super Admin): the requests and their workspaces. */
class TrialAccountController extends Controller
{
    public function index(): Response
    {
        $accounts = TrialAccount::latest()->get();

        return Inertia::render('Settings/TrialAccounts', [
            'accounts' => $accounts->map->toShare(),
            'auto_approve' => config('eolas.demo_auto_approve'),
            'demo_days' => config('eolas.demo_days'),
            'stats' => [
                'total' => $accounts->count(),
                'active' => $accounts->filter->usable()->count(),
                'pending' => $accounts->where('status', 'pending')->count(),
                'demo' => $accounts->where('data_source', 'demo')->count(),
                'blank' => $accounts->where('data_source', 'blank')->count(),
            ],
        ]);
    }

    /** Approves a waiting request (or retries a failed one): its workspace is prepared. */
    public function approve(TrialAccount $account): RedirectResponse
    {
        abort_unless(in_array($account->status, ['pending', 'failed'], true), 422);
        $account->update(['status' => 'provisioning', 'approved_at' => $account->approved_at ?? now()]);
        ProvisionDemoWorkspace::dispatch($account->id);

        Inertia::flash('success', "Workspace {$account->company_name} sedang disiapkan.");

        return back();
    }

    public function extend(Request $request, TrialAccount $account): RedirectResponse
    {
        $days = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:365']])['days'];
        $from = $account->expires_at && $account->expires_at->isFuture() ? $account->expires_at : now();
        $account->update(['expires_at' => $from->copy()->addDays($days)->endOfDay()]);

        Inertia::flash('success', "Masa demo {$account->company_name} diperpanjang {$days} hari.");

        return back();
    }

    /** Sets the end of the demo to a date chosen by hand (the end of that day). */
    public function setExpiry(Request $request, TrialAccount $account): RedirectResponse
    {
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01']])['date'];
        $account->update(['expires_at' => Carbon::parse($date)->endOfDay()]);

        Inertia::flash('success', "Masa demo {$account->company_name} berakhir ".$account->expires_at->translatedFormat('j F Y').'.');

        return back();
    }

    public function toggle(TrialAccount $account): RedirectResponse
    {
        abort_unless(in_array($account->status, ['active', 'inactive'], true), 422);
        $account->update(['status' => $account->status === 'active' ? 'inactive' : 'active']);

        Inertia::flash('success', $account->status === 'active' ? 'Akun demo diaktifkan.' : 'Akun demo dinonaktifkan.');

        return back();
    }

    /** Rebuilds the workspace with the chosen data (everything entered there is lost). */
    public function reset(Request $request, TrialAccount $account): RedirectResponse
    {
        $source = $request->validate(['data_source' => ['required', Rule::in(array_keys(TrialAccount::SUMBER))]])['data_source'];
        abort_if($account->status === 'provisioning' || $account->status === 'pending', 422);
        $account->update(['data_source' => $source, 'status' => 'provisioning']);
        ProvisionDemoWorkspace::dispatch($account->id);

        Inertia::flash('success', "Workspace {$account->company_name} disiapkan ulang dengan ".TrialAccount::SUMBER[$source].'.');

        return back();
    }

    public function destroy(TrialAccount $account): RedirectResponse
    {
        abort_if($account->status === 'provisioning', 422);
        Tenant::drop(Tenant::databaseFor($account));
        $account->delete();

        Inertia::flash('success', 'Akun demo dan workspace-nya dihapus.');

        return back();
    }
}
