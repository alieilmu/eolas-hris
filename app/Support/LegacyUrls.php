<?php

namespace App\Support;

/**
 * The page URLs from before the HRIS terminology refactor (docs/refactor/terminology-mapping.md)
 * and where they live now, so bookmarks and links in older notifications and e-mails keep working.
 */
class LegacyUrls
{
    /** Old path prefix => new one; longer prefixes first. */
    public const MAP = [
        'manpower/karyawan/data-lengkap' => 'core-hr/employees/full-data',
        'manpower/karyawan/keluar' => 'core-hr/employees/termination',
        'manpower/karyawan' => 'core-hr/employees',
        'manpower/master-data' => 'core-hr/settings',
        'manpower/mpp' => 'core-hr/workforce-planning',
        'manpower/org-structure' => 'core-hr/org-chart',
        'manpower/pengajuan-data' => 'core-hr/change-requests',
        'attendance/absensi' => 'time/attendance',
        'attendance/roster' => 'time/shift-schedule',
        'attendance/kehadiran' => 'time/clock',
        'attendance/trip-leave' => 'time/leave-travel',
        'attendance/pengajuan-dinas' => 'time/travel-requests',
        'attendance/master-data' => 'time/settings',
        'comben/cuti' => 'time/leave-balances',
        'comben/deklarasi' => 'compensation/claims',
        'comben/monitoring' => 'compensation/benefit-balances',
        'comben/data-gaji' => 'compensation/salaries',
        'comben/master-data' => 'compensation/settings',
        'portal/data' => 'self-service/profile',
        'portal' => 'self-service',
        'recruitment/tracking' => 'recruitment/pipeline',
        'recruitment/pelamar' => 'recruitment/applicants',
        'recruitment/laporan' => 'recruitment/reports',
        'recruitment/form' => 'recruitment/job-postings',
        'settings/akun-demo' => 'settings/trial-accounts',
        'settings/akun' => 'settings/users',
        'settings/white-label' => 'settings/branding',
        'akun/password' => 'account/password',
        'lowongan' => 'careers',
        'lampiran' => 'attachment',
    ];

    /** Path segments after the prefix that were renamed too ("…/karyawan/12/profil"). */
    private const SEGMENTS = ['profil' => 'profile', 'kandidat' => 'candidate'];

    /** The current URL of an old path (without query string). */
    public static function to(string $prefix, ?string $rest = null): string
    {
        $tail = $rest === null || $rest === '' ? '' : '/'.implode('/', array_map(fn (string $s) => self::SEGMENTS[$s] ?? $s, explode('/', $rest)));

        return '/'.self::MAP[$prefix].$tail;
    }
}
