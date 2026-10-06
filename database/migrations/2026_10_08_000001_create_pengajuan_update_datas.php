<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pengajuan Update Data: changes an employee asks for their own profile (and
 * children) from the portal, applied once HR approves them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengajuan_update_datas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->constrained()->cascadeOnDelete();
            // field => {before, after}
            $table->json('perubahan');
            // [{aksi: tambah|ubah|hapus, anak_id, before, after}]
            $table->json('anak')->nullable();
            $table->string('catatan', 500)->nullable();
            $table->string('status', 20)->default('diajukan')->index();
            $table->string('catatan_verifikasi')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Admins who handle Man Power data get the new menu at the same level.
        foreach (DB::table('users')->whereNotNull('permissions')->get(['id', 'permissions']) as $user) {
            $permissions = json_decode($user->permissions, true) ?: [];
            if (isset($permissions['karyawan']) && ! isset($permissions['update-data'])) {
                $permissions['update-data'] = $permissions['karyawan'];
                DB::table('users')->where('id', $user->id)->update(['permissions' => json_encode($permissions)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pengajuan_update_datas');
    }
};
