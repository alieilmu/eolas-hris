<?php

namespace App\Http\Controllers\Trial;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionDemoWorkspace;
use App\Models\TrialAccount;
use App\Rules\Turnstile;
use App\Support\Demo\Industry;
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
            'industries' => collect(Industry::LABELS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label, 'description' => Industry::DESCRIPTIONS[$key]])->values(),
            'company_size' => self::JUMLAH_KARYAWAN,
            'auto_approve' => config('eolas.demo_auto_approve'),
            'demo_days' => config('eolas.demo_days'),
            'turnstile_site_key' => Turnstile::enabled() ? config('services.turnstile.site_key') : null,
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
            'industry' => ['nullable', 'required_if:data_source,demo', Rule::in(array_keys(Industry::LABELS))],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'agree' => ['accepted'],
            // A field people never see: bots filling every input give themselves away.
            'website' => ['prohibited'],
            'captcha' => [Rule::requiredIf(Turnstile::enabled()), new Turnstile($request->ip())],
        ], [
            'website.prohibited' => 'Pengajuan tidak bisa diproses.',
            'captcha.required' => 'Selesaikan verifikasi "Saya bukan robot" terlebih dahulu.',
            'email.unique' => 'Email ini sudah terdaftar untuk akun demo. Silakan masuk.',
            'email.not_regex' => 'Gunakan email Anda sendiri.',
            'agree.accepted' => 'Centang persetujuan untuk melanjutkan.',
            'phone.regex' => 'Nomor HP tidak valid.',
        ]);

        $data['industry'] = $data['data_source'] === 'demo' ? ($data['industry'] ?? Industry::DEFAULT) : null;
        $auto = (bool) config('eolas.demo_auto_approve');
        // The server holds so many workspaces at once; past that the request waits in line
        // (eolas:prune-workspaces prepares it once a place frees up).
        $start = $auto && ! TrialAccount::full();
        $account = TrialAccount::create([
            ...collect($data)->except(['agree', 'password_confirmation', 'website', 'captcha'])->all(),
            'email' => Str::lower($data['email']),
            'code' => TrialAccount::newCode(),
            'status' => $start ? 'provisioning' : ($auto ? 'waiting' : 'pending'),
            'approved_at' => $start ? now() : null,
        ]);
        if ($start) {
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
            ...collect($account->toShare())->only(['status', 'status_label', 'data_source', 'source_label', 'industry_label', 'expires_at', 'days_left'])->all(),
        ]]);
    }
}
