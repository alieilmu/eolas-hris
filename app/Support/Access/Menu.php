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
        'Rekrutmen' => [
            'recruitment' => 'Rekrutmen (Pipeline, Pelamar, Lowongan)',
        ],
        'Core HR' => [
            'org-chart' => 'Struktur Organisasi',
            'workforce-planning' => 'Man Power Planning',
            // "Ubah" = may approve the plan revisions of its locations (and change the plan straight away).
            'workforce-planning-approval' => 'Persetujuan Revisi MPP',
            'employees' => 'Data Karyawan',
            'data-change-requests' => 'Pengajuan Update Data',
            'core-hr-settings' => 'Pengaturan Core HR',
        ],
        'Waktu & Kehadiran' => [
            'shift-schedule' => 'Jadwal Shift (Roster)',
            'attendance-records' => 'Absensi',
            'time-clock' => 'Kehadiran (CI/CO & mesin absen)',
            // "Ubah" = may approve or reject overtime.
            'overtime-approval' => 'Persetujuan Lembur',
            'leave-travel' => 'Cuti & Dinas (Cuti & Dinas)',
            'travel-requests' => 'Pengajuan Dinas',
            'leave-balances' => 'Saldo Cuti (Tahunan & Besar)',
            'time-settings' => 'Pengaturan Waktu & Kehadiran',
        ],
        'Kompensasi & Benefit' => [
            'benefit-claims' => 'Deklarasi Benefit',
            'benefit-balances' => 'Monitoring Plafon',
            'employee-compensation' => 'Data Gaji (SSU, tunjangan, PTKP)',
            // "Ubah" = may create runs, work them out and submit them.
            'payroll-run' => 'Proses Gaji',
            // "Ubah" = may approve or send back runs, mark them paid and lock them.
            'payroll-approval' => 'Persetujuan Payroll',
            'compensation-settings' => 'Pengaturan Kompensasi & Benefit',
        ],
    ];

    /** The page each menu opens on, in the order "/" tries them. */
    public const HOME = [
        'recruitment' => '/recruitment/pipeline',
        'org-chart' => '/core-hr/org-chart',
        'workforce-planning' => '/core-hr/workforce-planning',
        'workforce-planning-approval' => '/core-hr/workforce-planning',
        'employees' => '/core-hr/employees',
        'data-change-requests' => '/core-hr/change-requests',
        'core-hr-settings' => '/core-hr/settings',
        'shift-schedule' => '/time/shift-schedule',
        'attendance-records' => '/time/attendance',
        'time-clock' => '/time/clock',
        'overtime-approval' => '/time/overtime',
        'leave-travel' => '/time/leave-travel',
        'travel-requests' => '/time/travel-requests',
        'leave-balances' => '/time/leave-balances',
        'time-settings' => '/time/settings',
        'benefit-claims' => '/compensation/claims',
        'benefit-balances' => '/compensation/benefit-balances',
        'employee-compensation' => '/compensation/salaries',
        'payroll-run' => '/compensation/payroll',
        'payroll-approval' => '/compensation/payroll',
        'compensation-settings' => '/compensation/settings',
    ];

    /** Menus whose pages list employees: they only show the account's locations. */
    public const SCOPED = ['org-chart', 'workforce-planning', 'employees', 'data-change-requests', 'shift-schedule', 'attendance-records', 'leave-travel', 'travel-requests', 'benefit-claims', 'benefit-balances', 'leave-balances', 'time-clock', 'overtime-approval', 'employee-compensation', 'payroll-run', 'payroll-approval'];

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
