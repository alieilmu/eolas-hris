<?php

namespace App\Http\Controllers\Demo;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionDemoWorkspace;
use App\Models\DemoAccount;
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
class DemoRequestController extends Controller
{
    public const JUMLAH_KARYAWAN = ['1–50', '51–200', '201–500', '501–1.000', '> 1.000'];

    public function create(): Response
    {
        return Inertia::render('Demo/Request', [
            'sumber' => DemoAccount::SUMBER,
            'jumlah_karyawan' => self::JUMLAH_KARYAWAN,
            'auto_approve' => config('eolas.demo_auto_approve'),
            'demo_days' => config('eolas.demo_days'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:central.demo_accounts,email', 'unique:central.users,email', 'not_regex:/@eolas\.local$/i'],
            'perusahaan' => ['required', 'string', 'max:150'],
            'jabatan' => ['nullable', 'string', 'max:100'],
            'no_hp' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{6,30}$/'],
            'jumlah_karyawan' => ['nullable', Rule::in(self::JUMLAH_KARYAWAN)],
            'kebutuhan' => ['nullable', 'string', 'max:1000'],
            'sumber_data' => ['required', Rule::in(array_keys(DemoAccount::SUMBER))],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'setuju' => ['accepted'],
        ], [
            'email.unique' => 'Email ini sudah terdaftar untuk akun demo. Silakan masuk.',
            'email.not_regex' => 'Gunakan email Anda sendiri.',
            'setuju.accepted' => 'Centang persetujuan untuk melanjutkan.',
            'no_hp.regex' => 'Nomor HP tidak valid.',
        ]);

        $auto = (bool) config('eolas.demo_auto_approve');
        $account = DemoAccount::create([
            ...collect($data)->except(['setuju', 'password_confirmation'])->all(),
            'email' => Str::lower($data['email']),
            'kode' => DemoAccount::newKode(),
            'status' => $auto ? 'menyiapkan' : 'menunggu',
            'approved_at' => $auto ? now() : null,
        ]);
        if ($auto) {
            ProvisionDemoWorkspace::dispatch($account->id);
        }

        return redirect()->route('demo.status', $account->kode);
    }

    /** Follows the request: waiting, being prepared, ready (with the sign-in), or failed. */
    public function status(string $kode): Response
    {
        $account = DemoAccount::where('kode', $kode)->firstOrFail();

        return Inertia::render('Demo/Status', ['account' => [
            'kode' => $account->kode,
            'nama' => $account->nama,
            'perusahaan' => $account->perusahaan,
            // Masked: the page is reachable by its code alone.
            'email' => preg_replace('/(?<=^.{2})[^@]*(?=@)/', '•••', $account->email),
            ...collect($account->toShare())->only(['status', 'status_label', 'sumber_data', 'sumber_label', 'expires_at', 'days_left'])->all(),
        ]]);
    }
}
