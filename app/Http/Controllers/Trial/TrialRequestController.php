<?php

namespace App\Http\Controllers\Trial;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionDemoWorkspace;
use App\Models\TrialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengajuan Akun Demo (public): the form, and the page that follows the workspace being
 * prepared. Each account gets its own workspace with the system's demo data or empty.
 */
class TrialRequestController extends Controller
{
    public const JUMLAH_KARYAWAN = ['1–50', '51–200', '201–500', '501–1.000', '> 1.000'];

    public function create(): Response
    {
        return Inertia::render('Trial/Request', [
            'source' => TrialAccount::SUMBER,
            'company_size' => self::JUMLAH_KARYAWAN,
            'auto_approve' => config('eolas.demo_auto_approve'),
            'demo_days' => config('eolas.demo_days'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:central.trial_accounts,email', 'unique:central.users,email', 'not_regex:/@eolas\.local$/i'],
            'company_name' => ['required', 'string', 'max:150'],
            'position' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{6,30}$/'],
            'company_size' => ['nullable', Rule::in(self::JUMLAH_KARYAWAN)],
            'requirements' => ['nullable', 'string', 'max:1000'],
            'data_source' => ['required', Rule::in(array_keys(TrialAccount::SUMBER))],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'agree' => ['accepted'],
        ], [
            'email.unique' => 'Email ini sudah terdaftar untuk akun demo. Silakan masuk.',
            'email.not_regex' => 'Gunakan email Anda sendiri.',
            'agree.accepted' => 'Centang persetujuan untuk melanjutkan.',
            'phone.regex' => 'Nomor HP tidak valid.',
        ]);

        $auto = (bool) config('eolas.demo_auto_approve');
        $account = TrialAccount::create([
            ...collect($data)->except(['agree', 'password_confirmation'])->all(),
            'email' => Str::lower($data['email']),
            'code' => TrialAccount::newCode(),
            'status' => $auto ? 'provisioning' : 'pending',
            'approved_at' => $auto ? now() : null,
        ]);
        if ($auto) {
            ProvisionDemoWorkspace::dispatch($account->id);
        }

        return redirect()->route('demo.status', $account->code);
    }

    /** Follows the request: waiting, being prepared, ready (with the sign-in), or failed. */
    public function status(string $code): Response
    {
        $account = TrialAccount::where('code', $code)->firstOrFail();

        return Inertia::render('Trial/Status', ['account' => [
            'code' => $account->code,
            'name' => $account->name,
            'company_name' => $account->company_name,
            // Masked: the page is reachable by its code alone.
            'email' => preg_replace('/(?<=^.{2})[^@]*(?=@)/', '•••', $account->email),
            ...collect($account->toShare())->only(['status', 'status_label', 'data_source', 'source_label', 'expires_at', 'days_left'])->all(),
        ]]);
    }
}
