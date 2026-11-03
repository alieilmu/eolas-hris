<?php

namespace App\Http\Controllers\SelfService;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\Audit\Audit;
use App\Support\TimeClock\MobileClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\File;
use Inertia\Inertia;

/** Portal Karyawan → Absen: clock in / out from the phone, after agreeing to the location and selfie being kept. */
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

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:in,out'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'min:0', 'max:100000'],
            'photo' => ['required', File::types(['jpg', 'jpeg', 'png', 'webp'])->max(4096)],
        ], [
            'latitude.required' => 'Lokasi GPS belum didapat. Nyalakan GPS / izinkan lokasi, lalu coba lagi.',
            'photo.required' => 'Ambil foto wajah dulu.',
        ]);
        $log = MobileClock::punch($this->me($request), $request->user(), [
            'type' => $data['type'], 'latitude' => (float) $data['latitude'], 'longitude' => (float) $data['longitude'], 'accuracy' => (float) $data['accuracy'],
        ], $request->file('photo'), $request->userAgent());

        $what = $data['type'] === 'out' ? 'Absen pulang' : 'Absen masuk';
        Inertia::flash('success', "{$what} tercatat pukul {$log->punched_at->format('H:i')}".($log->outside_geofence ? ' — ditandai di luar area kerja.' : '.'));

        return redirect('/self-service');
    }
}
