<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Man Power Planning: one running plan per work location, kept per period (quarter or
 * semester, set in Master Data). A new period starts from the previous one. Changes by an
 * account without "Persetujuan MPP" wait as a revision for an approver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mpp_aturans', function (Blueprint $table) {
            $table->id();
            // kuartal | semester
            $table->string('periode', 10)->default('kuartal');
            // sub_group_id => golongan_id: the golongan a position's cost is estimated with.
            $table->json('golongan_sub_group')->nullable();
            $table->timestamps();
        });

        Schema::create('mpp_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_location_id')->constrained()->cascadeOnDelete();
            $table->string('jenis', 10); // kuartal | semester
            $table->unsignedSmallInteger('tahun');
            $table->unsignedTinyInteger('ke'); // 1–4 or 1–2
            $table->date('mulai');
            $table->date('selesai');
            // The plan this one was copied from when the period began.
            $table->foreignId('dari_plan_id')->nullable()->constrained('mpp_plans')->nullOnDelete();
            $table->timestamps();
            $table->unique(['work_location_id', 'jenis', 'tahun', 'ke']);
        });

        Schema::create('mpp_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mpp_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('jabatan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('plan')->default(0);
            // Expected to leave the position this period (typed by HR).
            $table->unsignedSmallInteger('promosi')->default(0);
            $table->unsignedSmallInteger('rotasi')->default(0);
            $table->unsignedSmallInteger('demosi')->default(0);
            $table->unsignedSmallInteger('keluar')->default(0);
            // Retirement and long sickness are counted from the employees; a number here replaces that count.
            $table->unsignedSmallInteger('pensiun')->nullable();
            $table->unsignedSmallInteger('sakit')->nullable();
            $table->unsignedTinyInteger('bulan_pindah')->nullable();
            $table->unsignedTinyInteger('bulan_penuhi')->nullable();
            $table->string('sumber', 10)->nullable(); // internal | eksternal
            $table->foreignId('golongan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('keterangan')->nullable();
            $table->timestamps();
            $table->unique(['mpp_plan_id', 'jabatan_id']);
        });

        Schema::create('mpp_revisis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mpp_plan_id')->constrained()->cascadeOnDelete();
            // diajukan | disetujui | ditolak | dibatalkan
            $table->string('status', 12)->index();
            // {"<jabatan id>": {"<field>": [before, after]}}
            $table->json('perubahan');
            // Saved straight away by an approver (kept as history).
            $table->boolean('langsung')->default(false);
            $table->string('catatan')->nullable();
            $table->string('catatan_verifikasi')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        // Long sickness: still counted as Actual, also as expected to be away.
        Schema::table('karyawan_profiles', function (Blueprint $table) {
            $table->date('sakit_mulai')->nullable();
            $table->date('sakit_selesai')->nullable();
            $table->string('sakit_keterangan')->nullable();
        });

        // A vacancy opened from a position in the plan.
        Schema::table('application_forms', function (Blueprint $table) {
            $table->foreignId('jabatan_id')->nullable()->after('lokasi')->constrained()->nullOnDelete();
        });

        DB::table('mpp_aturans')->insert(['periode' => 'kuartal', 'created_at' => now(), 'updated_at' => now()]);

        // Who could see Struktur Organisasi may open Man Power Planning at the same level.
        foreach (DB::table('users')->where('role', 'HR')->whereNotNull('permissions')->get(['id', 'permissions']) as $user) {
            $permissions = json_decode($user->permissions, true) ?: [];
            if (isset($permissions['org-structure']) && ! isset($permissions['mpp'])) {
                $permissions['mpp'] = $permissions['org-structure'];
                DB::table('users')->where('id', $user->id)->update(['permissions' => json_encode($permissions)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('application_forms', fn (Blueprint $table) => $table->dropConstrainedForeignId('jabatan_id'));
        Schema::table('karyawan_profiles', fn (Blueprint $table) => $table->dropColumn(['sakit_mulai', 'sakit_selesai', 'sakit_keterangan']));
        Schema::dropIfExists('mpp_revisis');
        Schema::dropIfExists('mpp_plan_items');
        Schema::dropIfExists('mpp_plans');
        Schema::dropIfExists('mpp_aturans');
    }
};
