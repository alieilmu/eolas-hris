<?php

namespace App\Support\Access;

/**
 * The menus an Admin account can be given, per module, each as "view" (lihat)
 * or "edit" (lihat & ubah). Settings is for the Super Admin only.
 */
class Menu
{
    public const VIEW = 'view';

    public const EDIT = 'edit';

    /** module => menu key => label */
    public const MODULES = [
        'Recruitment' => [
            'recruitment' => 'Recruitment (Tracking, Pelamar, Form)',
        ],
        'Manpower' => [
            'org-structure' => 'Struktur Organisasi',
            'mpp' => 'Man Power Planning',
            // "Ubah" = may approve the plan revisions of its locations (and change the plan straight away).
            'mpp-approve' => 'Persetujuan Revisi MPP',
            'karyawan' => 'Man Power (data karyawan)',
            'update-data' => 'Pengajuan Update Data',
            'master-personalia' => 'Master Data Personalia',
        ],
        'Attendance' => [
            'roster' => 'Roster',
            'absensi' => 'Absensi',
            'trip-leave' => 'Trip & Leave',
            'pengajuan-dinas' => 'Pengajuan Dinas',
            'master-attendance' => 'Master Data Attendance',
        ],
        'Comben' => [
            'deklarasi' => 'Deklarasi',
            'monitoring-plafon' => 'Monitoring Plafon',
            'cuti' => 'Cuti Tahunan & Besar',
            'master-comben' => 'Master Data Comben',
        ],
    ];

    /** The page each menu opens on, in the order "/" tries them. */
    public const HOME = [
        'recruitment' => '/recruitment/tracking',
        'org-structure' => '/manpower/org-structure',
        'mpp' => '/manpower/mpp',
        'mpp-approve' => '/manpower/mpp',
        'karyawan' => '/manpower/karyawan',
        'update-data' => '/manpower/pengajuan-data',
        'master-personalia' => '/manpower/master-data',
        'roster' => '/attendance/roster',
        'absensi' => '/attendance/absensi',
        'trip-leave' => '/attendance/trip-leave',
        'pengajuan-dinas' => '/attendance/pengajuan-dinas',
        'master-attendance' => '/attendance/master-data',
        'deklarasi' => '/comben/deklarasi',
        'monitoring-plafon' => '/comben/monitoring',
        'cuti' => '/comben/cuti',
        'master-comben' => '/comben/master-data',
    ];

    /** Menus whose pages list employees: they only show the account's locations. */
    public const SCOPED = ['org-structure', 'mpp', 'karyawan', 'update-data', 'roster', 'absensi', 'trip-leave', 'pengajuan-dinas', 'deklarasi', 'monitoring-plafon', 'cuti'];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::MODULES)));
    }

    /** Every menu at one level, e.g. for the Super Admin. */
    public static function all(string $level = self::EDIT): array
    {
        return array_fill_keys(self::keys(), $level);
    }
}
