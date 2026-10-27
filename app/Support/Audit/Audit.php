<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * The audit log of sensitive actions (Administrasi → Audit Log): salary data and payroll runs,
 * exports and imports of employee data, passwords and two-factor sign-in. Writing it never
 * stops the action itself.
 */
class Audit
{
    /** action => label, grouped for the filter. */
    public const ACTIONS = [
        'Akun' => [
            'auth.login' => 'Masuk',
            'auth.login_failed' => 'Gagal masuk',
            'auth.two_factor_failed' => 'Kode 2FA salah',
            'auth.two_factor_enabled' => '2FA diaktifkan',
            'auth.two_factor_disabled' => '2FA dimatikan',
            'auth.two_factor_reset' => '2FA direset admin',
            'auth.recovery_code_used' => 'Kode cadangan 2FA dipakai',
            'users.password_reset' => 'Reset password karyawan',
            'license.installed' => 'Lisensi dipasang',
        ],
        'Payroll' => [
            'payroll.run_created' => 'Proses gaji dibuat',
            'payroll.run_calculated' => 'Proses gaji dihitung',
            'payroll.run_submitted' => 'Proses gaji diajukan',
            'payroll.run_approved' => 'Proses gaji disetujui',
            'payroll.run_rejected' => 'Proses gaji dikembalikan',
            'payroll.run_paid' => 'Proses gaji dibayar',
            'payroll.run_locked' => 'Proses gaji dikunci',
            'payroll.run_deleted' => 'Proses gaji dihapus',
            'payroll.export' => 'Rekap payroll diunduh',
            'payroll.slip_viewed' => 'Slip karyawan dibuka',
            'payroll.slip_downloaded' => 'Slip PDF diunduh',
            'payroll.settings_changed' => 'Pengaturan payroll diubah',
            'payroll.rates_changed' => 'Tarif BPJS / PPh21 diubah',
            'payroll.profile_changed' => 'Pengaturan payroll karyawan diubah',
            'payroll.input_created' => 'Input variabel dibuat',
            'payroll.input_deleted' => 'Input variabel dihapus',
            'payroll.inputs_imported' => 'Input variabel diimpor',
            'payroll.inputs_pulled' => 'Input ditarik dari modul lain',
            'payroll.inputs_approved' => 'Input variabel disetujui',
            'payroll.inputs_rejected' => 'Input variabel ditolak',
            'payroll.loan_created' => 'Pinjaman dicatat HR',
            'payroll.loan_requested' => 'Pinjaman diajukan karyawan',
            'payroll.loan_approved' => 'Pinjaman disetujui',
            'payroll.loan_rejected' => 'Pinjaman ditolak',
            'payroll.loan_postponed' => 'Angsuran ditunda',
            'payroll.loan_paid_off' => 'Pinjaman dilunasi',
            'salary.changed' => 'Data gaji diubah',
            'salary.deleted' => 'Riwayat gaji dihapus',
        ],
        'Data karyawan' => [
            'employees.export' => 'Data karyawan diekspor',
            'employees.import' => 'Data karyawan diimpor',
        ],
    ];

    public static function log(string $action, ?Model $subject = null, ?string $description = null, array $meta = [], ?User $user = null): void
    {
        try {
            $user ??= Auth::user();
            $request = request();
            AuditLog::create([
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'description' => $description ? Str::limit($description, 495) : null,
                'meta' => $meta ?: null,
                'ip' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function label(string $action): string
    {
        foreach (self::ACTIONS as $group) {
            if (isset($group[$action])) {
                return $group[$action];
            }
        }

        return $action;
    }
}
