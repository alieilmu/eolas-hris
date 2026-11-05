<?php

namespace App\Support\Push;

use App\Models\User;

/**
 * The kinds of notification an account may choose to receive on the phone. The bell inside
 * the app keeps every one; this only decides what is pushed.
 */
class PushCategories
{
    public const LABELS = [
        'requests' => ['label' => 'Pengajuan saya', 'hint' => 'Pengajuan Anda disetujui atau ditolak'],
        'approvals' => ['label' => 'Persetujuan', 'hint' => 'Pengajuan yang menunggu keputusan Anda, dan pengingatnya'],
        'payslip' => ['label' => 'Slip gaji terbit', 'hint' => 'Slip gaji baru bisa dibuka di portal'],
        'certification' => ['label' => 'Sertifikat akan habis', 'hint' => 'Sertifikat dan izin yang mendekati masa berakhir'],
    ];

    /** The kind of a notification's type (RequestReceived::$type). */
    public static function of(?string $type): string
    {
        return match (true) {
            $type === 'approval-result', str_ends_with((string) $type, '-result') => 'requests',
            $type === 'payslip' => 'payslip',
            str_starts_with((string) $type, 'certification') => 'certification',
            default => 'approvals',
        };
    }

    /** @return array<string, bool> every kind, on unless turned off */
    public static function preferences(User $user): array
    {
        $saved = (array) ($user->push_preferences ?? []);

        return collect(self::LABELS)->mapWithKeys(fn ($_, string $key) => [$key => ($saved[$key] ?? true) !== false])->all();
    }

    public static function wants(User $user, ?string $type): bool
    {
        return self::preferences($user)[self::of($type)];
    }
}
