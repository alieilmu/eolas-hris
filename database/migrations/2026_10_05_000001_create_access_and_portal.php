<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Akun & akses:
 *  - Super Admin (role "Admin"): everything, every location.
 *  - Admin (role "HR"): the menus ticked (lihat / ubah) for the work locations
 *    and sub locations chosen (none chosen = all).
 *  - Karyawan: one employee (logs in with the NRP); the employee portal only.
 * Pengajuan from the portal (deklarasi, cuti, dinas) wait for HR: status
 * diajukan → disetujui / ditolak.
 */
return new class extends Migration
{
    private const MENUS = [
        'recruitment', 'org-structure', 'karyawan', 'master-personalia',
        'roster', 'absensi', 'trip-leave', 'pengajuan-dinas', 'master-attendance',
        'deklarasi', 'monitoring-plafon', 'master-comben',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->foreignId('karyawan_id')->nullable()->unique()->after('role')->constrained()->cascadeOnDelete();
            // {"roster": "edit", "absensi": "view", ...}
            $table->json('permissions')->nullable()->after('karyawan_id');
            // Empty: every location.
            $table->json('work_location_ids')->nullable()->after('permissions');
            $table->json('sub_location_ids')->nullable()->after('work_location_ids');
            $table->boolean('is_active')->default(true)->after('sub_location_ids');
            $table->boolean('must_change_password')->default(false)->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('must_change_password');
        });

        // HR keeps everything it had; Viewer becomes an Admin that may only look at the organisation chart.
        DB::table('users')->where('role', 'HR')->update(['permissions' => json_encode(array_fill_keys(self::MENUS, 'edit'))]);
        DB::table('users')->where('role', 'Viewer')->update(['role' => 'HR', 'permissions' => json_encode(['org-structure' => 'view'])]);

        foreach (['deklarasis', 'trips'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                // diajukan (from the portal, waiting) | disetujui | ditolak
                $table->string('status', 10)->default('disetujui')->index();
                // hr | portal
                $table->string('sumber', 10)->default('hr');
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('verified_at')->nullable();
                $table->string('catatan_verifikasi', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['deklarasis', 'trips'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('verified_by');
                $table->dropColumn(['status', 'sumber', 'verified_at', 'catatan_verifikasi']);
            });
        }
        DB::table('users')->where('role', 'Karyawan')->delete();
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('karyawan_id');
            $table->dropColumn(['permissions', 'work_location_ids', 'sub_location_ids', 'is_active', 'must_change_password', 'last_login_at']);
        });
    }
};
