<?php

namespace App\Support\TimeClock;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\MobileAttendanceSetting;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkLocationGeofence;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Absen HP: an employee clocks in or out from the phone, with the GPS position (and how
 * accurate it is) and a selfie from the camera. The punch goes into Kehadiran like a
 * machine's (source "mobile"), checked against the work location's geofences: outside
 * them it is refused, or taken and marked, as the location is set. The time is the
 * server's, never the phone's.
 */
class MobileClock
{
    /** The consent text the employee agreed to (UU PDP); a new version asks again. */
    public const CONSENT_VERSION = '2026-11';

    /** Two punches closer than this are one tap too many. */
    private const MIN_GAP_SECONDS = 60;

    public const STATUS_IN = 0;

    public const STATUS_OUT = 1;

    /** What is collected, why, and for how long: shown before the first punch. */
    public static function consent(): array
    {
        $days = MobileAttendanceSetting::current()->photo_retention_days;

        return [
            'version' => self::CONSENT_VERSION,
            'points' => [
                'Saat absen dari HP, perusahaan mencatat lokasi GPS Anda (beserta tingkat akurasinya) dan foto wajah dari kamera depan.',
                'Data ini hanya dipakai untuk mencatat kehadiran dan memastikan absen dilakukan di lokasi kerja. Lokasi tidak dilacak di luar saat Anda menekan tombol absen.',
                "Foto disimpan paling lama {$days} hari, lalu dihapus otomatis. Jam dan lokasi absen tetap tersimpan sebagai data kehadiran.",
                'Foto hanya bisa dibuka HR yang berwenang, dan setiap pembukaan foto tercatat di Audit Log.',
                'Anda bisa meminta salinan atau penjelasan data ini ke HR (UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi).',
            ],
        ];
    }

    public static function consented(Employee $employee): bool
    {
        return $employee->attendance_consent_at !== null && $employee->attendance_consent_version === self::CONSENT_VERSION;
    }

    public static function giveConsent(Employee $employee): void
    {
        $employee->forceFill(['attendance_consent_at' => now(), 'attendance_consent_version' => self::CONSENT_VERSION])->save();
    }

    /** For the portal: whether the button shows, the places, today's punches and the next one. */
    public static function state(Employee $employee): array
    {
        $location = $employee->workLocation;
        $mode = $location?->mobile_attendance ?? 'off';
        $today = AttendanceLog::where('employee_id', $employee->id)->whereBetween('punched_at', [today()->startOfDay(), today()->endOfDay()])
            ->orderBy('punched_at')->get(['punched_at', 'source', 'status_code', 'outside_geofence']);

        return [
            'enabled' => $mode !== 'off' && ($mode === 'flag' || $location?->geofences()->exists()),
            'mode' => $mode,
            'location' => $location?->name,
            'geofences' => $mode === 'off' ? [] : $location->geofences->map(fn (WorkLocationGeofence $g) => [
                'name' => $g->name, 'latitude' => $g->latitude, 'longitude' => $g->longitude, 'radius_m' => $g->radius_m,
            ])->values(),
            'consented' => self::consented($employee),
            'next' => $today->isEmpty() ? 'in' : 'out',
            'today' => $today->map(fn (AttendanceLog $l) => [
                'time' => $l->punched_at->format('H:i'), 'source' => $l->source,
                'type' => $l->status_code === self::STATUS_OUT ? 'out' : ($l->status_code === self::STATUS_IN ? 'in' : null),
                'outside' => (bool) $l->outside_geofence,
            ])->values(),
        ];
    }

    /** Metres between two points on the earth (haversine). */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /**
     * The nearest geofence of the location, how far away it is and whether the point is inside it.
     *
     * @return array{geofence: ?WorkLocationGeofence, distance: ?int, inside: bool}
     */
    public static function place(WorkLocation $location, float $lat, float $lng): array
    {
        $best = null;
        $bestOver = INF;
        $bestDistance = null;
        foreach ($location->geofences as $g) {
            $d = self::distance($lat, $lng, $g->latitude, $g->longitude);
            // Nearest by how far beyond the edge, so a wide pit beats a small office close by.
            if ($d - $g->radius_m < $bestOver) {
                [$best, $bestOver, $bestDistance] = [$g, $d - $g->radius_m, $d];
            }
        }

        return ['geofence' => $best, 'distance' => $bestDistance === null ? null : (int) round($bestDistance), 'inside' => $best !== null && $bestOver <= 0];
    }

    /**
     * Takes a punch from the phone.
     *
     * @param  array{type: string, latitude: float, longitude: float, accuracy: float}  $data
     *
     * @throws ValidationException
     */
    public static function punch(Employee $employee, User $user, array $data, UploadedFile $photo, ?string $client = null): AttendanceLog
    {
        $location = $employee->workLocation;
        $mode = $location?->mobile_attendance ?? 'off';
        if ($mode === 'off') {
            throw ValidationException::withMessages(['type' => 'Absen dari HP belum dipakai di lokasi kerja Anda.']);
        }
        if (! self::consented($employee)) {
            throw ValidationException::withMessages(['type' => 'Setujui dulu pemakaian lokasi dan foto untuk absen.']);
        }
        $now = CarbonImmutable::now();
        $last = AttendanceLog::where('employee_id', $employee->id)->latest('punched_at')->value('punched_at');
        if ($last && CarbonImmutable::parse($last)->diffInSeconds($now, true) < self::MIN_GAP_SECONDS) {
            throw ValidationException::withMessages(['type' => 'Absen baru saja tercatat. Tunggu sebentar sebelum absen lagi.']);
        }

        $place = self::place($location, $data['latitude'], $data['longitude']);
        if ($mode === 'reject' && ! $place['inside']) {
            throw ValidationException::withMessages(['latitude' => $place['geofence']
                ? "Anda di luar area absen ({$place['distance']} m dari {$place['geofence']->name}). Absen hanya bisa di dalam area kerja."
                : 'Area absen lokasi kerja Anda belum diatur. Hubungi HR.']);
        }

        $path = $photo->storeAs('attendance-photos/'.$now->format('Y/m'), $employee->id.'-'.$now->format('Ymd-His').'-'.Str::random(6).'.jpg', 'local');
        AttendanceIngest::store([[
            'pin' => $employee->pin(), 'punched_at' => $now->format('Y-m-d H:i:s'),
            'status' => $data['type'] === 'out' ? self::STATUS_OUT : self::STATUS_IN, 'verify' => 'mobile',
            'extra' => [
                'latitude' => $data['latitude'], 'longitude' => $data['longitude'], 'accuracy_m' => (int) round($data['accuracy']),
                'geofence_id' => $place['geofence']?->id, 'distance_m' => $place['distance'], 'outside_geofence' => ! $place['inside'],
                'photo_path' => $path, 'client' => $client ? Str::limit($client, 155, '') : null,
            ],
        ]], null, 'mobile', $user->id);

        return AttendanceLog::where('employee_id', $employee->id)->where('punched_at', $now->format('Y-m-d H:i:s'))->where('source', 'mobile')->firstOrFail();
    }

    /** Selfies older than the retention are deleted; the punch itself stays. Returns how many. */
    public static function purgePhotos(): int
    {
        $days = MobileAttendanceSetting::current()->photo_retention_days;
        $n = 0;
        AttendanceLog::whereNotNull('photo_path')->where('punched_at', '<', now()->subDays($days))->orderBy('id')
            ->chunkById(500, function ($logs) use (&$n) {
                foreach ($logs as $log) {
                    Storage::disk('local')->delete($log->photo_path);
                    $log->forceFill(['photo_path' => null, 'photo_deleted_at' => now()])->save();
                    $n++;
                }
            });

        return $n;
    }
}
