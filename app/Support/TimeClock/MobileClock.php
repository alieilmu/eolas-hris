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
            // A punch queued on the phone older than this is refused when it arrives.
            'offline_max_hours' => MobileAttendanceSetting::current()->offline_max_hours,
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
     * Takes a punch from the phone. Sent right away, its time is the server's. Taken without a
     * signal and sent later (offline), its time is the phone's at the tap, corrected by how far
     * the phone's clock is off from the server's when sending; one older than the limit is
     * refused. A phone clock off by more than the tolerance marks the punch for HR. The phone's
     * id of the punch makes a second send of the same one harmless.
     *
     * @param  array{type: string, latitude: float, longitude: float, accuracy: float}  $data
     * @param  array{id?: ?string, taken_at?: ?CarbonImmutable, sent_at?: ?CarbonImmutable, offline?: bool}  $timing
     *
     * @throws ValidationException
     */
    public static function punch(Employee $employee, User $user, array $data, UploadedFile $photo, ?string $client = null, array $timing = []): AttendanceLog
    {
        $id = $timing['id'] ?? null;
        if ($id && ($done = AttendanceLog::where('client_uuid', $id)->first())) {
            if ((int) $done->employee_id !== (int) $employee->id) {
                throw ValidationException::withMessages(['type' => 'Absen ini tercatat untuk akun lain.']);
            }

            return $done;
        }
        $location = $employee->workLocation;
        $mode = $location?->mobile_attendance ?? 'off';
        if ($mode === 'off') {
            throw ValidationException::withMessages(['type' => 'Absen dari HP belum dipakai di lokasi kerja Anda.']);
        }
        if (! self::consented($employee)) {
            throw ValidationException::withMessages(['type' => 'Setujui dulu pemakaian lokasi dan foto untuk absen.']);
        }

        $settings = MobileAttendanceSetting::current();
        $now = CarbonImmutable::now();
        $offline = (bool) ($timing['offline'] ?? false);
        $takenAt = $timing['taken_at'] ?? null;
        $sentAt = $timing['sent_at'] ?? $takenAt;
        // Server minus phone: what the phone's clock is off by.
        $offset = $sentAt ? (int) round($sentAt->diffInSeconds($now, false)) : null;
        if ($offline) {
            if (! $takenAt) {
                throw ValidationException::withMessages(['type' => 'Jam absen dari HP tidak terbaca. Absen ulang.']);
            }
            $at = $takenAt->addSeconds($offset ?? 0)->setTimezone($now->getTimezone())->startOfSecond();
            $future = $at->gt($now);
            $at = $at->min($now);
            if ($at->diffInSeconds($now, true) > $settings->offline_max_hours * 3600) {
                throw ValidationException::withMessages(['type' => "Absen offline {$at->format('d.m.Y H:i')} sudah lebih dari {$settings->offline_max_hours} jam, tidak bisa diterima. Hubungi HR untuk koreksi."]);
            }
        } else {
            $at = $now;
            $future = false;
        }
        $suspect = $future || ($offset !== null && abs($offset) > $settings->clock_tolerance_minutes * 60);

        $near = AttendanceLog::where('employee_id', $employee->id)
            ->whereBetween('punched_at', [$at->subSeconds(self::MIN_GAP_SECONDS)->format('Y-m-d H:i:s'), $at->addSeconds(self::MIN_GAP_SECONDS)->format('Y-m-d H:i:s')])->exists();
        if ($near) {
            throw ValidationException::withMessages(['type' => $offline
                ? "Sudah ada absen pukul {$at->format('H:i')}. Absen offline ini tidak dicatat dua kali."
                : 'Absen baru saja tercatat. Tunggu sebentar sebelum absen lagi.']);
        }

        $place = self::place($location, $data['latitude'], $data['longitude']);
        if ($mode === 'reject' && ! $place['inside']) {
            throw ValidationException::withMessages(['latitude' => $place['geofence']
                ? "Anda di luar area absen ({$place['distance']} m dari {$place['geofence']->name}). Absen hanya bisa di dalam area kerja."
                : 'Area absen lokasi kerja Anda belum diatur. Hubungi HR.']);
        }

        $path = $photo->storeAs('attendance-photos/'.$at->format('Y/m'), $employee->id.'-'.$at->format('Ymd-His').'-'.Str::random(6).'.jpg', 'local');
        AttendanceIngest::store([[
            'pin' => $employee->pin(), 'punched_at' => $at->format('Y-m-d H:i:s'),
            'status' => $data['type'] === 'out' ? self::STATUS_OUT : self::STATUS_IN, 'verify' => 'mobile',
            'extra' => [
                'latitude' => $data['latitude'], 'longitude' => $data['longitude'], 'accuracy_m' => (int) round($data['accuracy']),
                'geofence_id' => $place['geofence']?->id, 'distance_m' => $place['distance'], 'outside_geofence' => ! $place['inside'],
                'photo_path' => $path, 'client' => $client ? Str::limit($client, 155, '') : null,
                'client_uuid' => $id, 'device_time' => $takenAt?->setTimezone($now->getTimezone())->format('Y-m-d H:i:s'),
                'received_at' => $now->format('Y-m-d H:i:s'), 'clock_offset_s' => $offset, 'offline' => $offline, 'time_suspect' => $suspect,
            ],
        ]], null, 'mobile', $user->id);

        return AttendanceLog::where('employee_id', $employee->id)->where('punched_at', $at->format('Y-m-d H:i:s'))->where('source', 'mobile')->firstOrFail();
    }

    /** "Jam HP 2 jam 5 menit lebih lambat" — how far the phone's clock was off, for HR. */
    public static function offsetLabel(?int $seconds): ?string
    {
        if ($seconds === null || abs($seconds) < 60) {
            return null;
        }
        $m = intdiv(abs($seconds), 60);
        $text = ($m >= 60 ? intdiv($m, 60).' jam'.($m % 60 ? ' '.($m % 60).' menit' : '') : "{$m} menit");

        return 'Jam HP '.$text.($seconds > 0 ? ' lebih lambat' : ' lebih cepat');
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
