<?php

namespace App\Http\Controllers\SelfService;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\Audit\Audit;
use App\Support\TimeClock\MobileClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;
use Inertia\Inertia;

/**
 * Portal Karyawan → Absen: clock in / out from the phone, after agreeing to the location and
 * selfie being kept. A punch taken without a signal waits on the phone and is sent here later
 * by the app itself (as JSON), with the phone's time of the tap.
 */
class ClockController extends Controller
{
    private function me(Request $request): Employee
    {
        return Employee::with('workLocation.geofences')->findOrFail($request->user()->employee_id);
    }

    public function consent(Request $request): RedirectResponse
    {
        $employee = $this->me($request);
        MobileClock::giveConsent($employee);
        Audit::log('attendance.consent', $employee, null, ['version' => MobileClock::CONSENT_VERSION]);

        return back();
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:in,out'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'min:0', 'max:100000'],
            'photo' => ['required', File::types(['jpg', 'jpeg', 'png', 'webp'])->max(4096)],
            'id' => ['nullable', 'uuid'],
            'taken_at' => ['nullable', 'date'],
            'sent_at' => ['nullable', 'date'],
            'offline' => ['nullable', 'boolean'],
            // The account the phone queued it for: another one signed in since does not get it.
            'user' => ['nullable', 'integer'],
        ], [
            'latitude.required' => 'Lokasi GPS belum didapat. Nyalakan GPS / izinkan lokasi, lalu coba lagi.',
            'photo.required' => 'Ambil foto wajah dulu.',
        ]);
        if (isset($data['user']) && (int) $data['user'] !== (int) $request->user()->id) {
            abort(409, 'Absen ini disimpan untuk akun lain di HP ini.');
        }
        $time = fn (?string $at) => $at ? CarbonImmutable::parse($at) : null;
        $log = MobileClock::punch($this->me($request), $request->user(), [
            'type' => $data['type'], 'latitude' => (float) $data['latitude'], 'longitude' => (float) $data['longitude'], 'accuracy' => (float) $data['accuracy'],
        ], $request->file('photo'), $request->userAgent(), [
            'id' => $data['id'] ?? null, 'taken_at' => $time($data['taken_at'] ?? null), 'sent_at' => $time($data['sent_at'] ?? null), 'offline' => (bool) ($data['offline'] ?? false),
        ]);

        $what = $data['type'] === 'out' ? 'Absen pulang' : 'Absen masuk';
        $message = "{$what} tercatat pukul {$log->punched_at->format('H:i')}".($log->offline ? ' (dikirim setelah sinyal kembali)' : '')
            .($log->outside_geofence ? ' — ditandai di luar area kerja.' : '.')
            .($log->time_suspect ? ' Jam HP Anda tidak sesuai jam server; periksa pengaturan jam HP.' : '');
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'time' => $log->punched_at->format('H:i')]);
        }
        Inertia::flash('success', $message);

        return redirect('/self-service');
    }
}
