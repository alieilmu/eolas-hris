<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuti Tahunan & Cuti Besar (Comben): the rules, the field-leave allowance per
 * location, leave taken before the app (imported / typed by HR), the Cuti Besar
 * decisions, and what each cuti in Trip & Leave takes from them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuti_aturans', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('jatah_tahunan')->default(12);
            $table->unsignedSmallInteger('masa_aktif_tahun')->default(2);
            $table->unsignedSmallInteger('besar_interval_tahun')->default(5);
            $table->unsignedSmallInteger('besar_hari')->default(25);
            $table->decimal('besar_gapok_diambil', 4, 1)->default(1);
            $table->decimal('besar_gapok_diuangkan', 4, 1)->default(2);
            $table->unsignedSmallInteger('besar_batas_bulan')->default(12);
            $table->timestamps();
        });
        DB::table('cuti_aturans')->insert(['created_at' => now(), 'updated_at' => now()]);

        // Cuti lapangan: leave days plus travel days, per work location (or one of its sub locations).
        Schema::create('cuti_lapangans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sub_location_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('hari_cuti')->default(14);
            $table->unsignedSmallInteger('hari_berangkat')->default(2);
            $table->unsignedSmallInteger('hari_kembali')->default(2);
            $table->timestamps();
            $table->unique(['work_location_id', 'sub_location_id']);
        });

        // Leave taken outside Trip & Leave: the history imported from Excel, or typed by HR.
        Schema::create('cuti_pengambilans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->constrained()->cascadeOnDelete();
            $table->string('jenis', 20)->default('tahunan');
            // The year the days count in (the FIFO ledger is per year).
            $table->unsignedSmallInteger('tahun');
            $table->date('tanggal_awal')->nullable();
            $table->date('tanggal_akhir')->nullable();
            $table->string('keterangan_tanggal')->nullable();
            $table->unsignedSmallInteger('hari');
            $table->string('sumber', 20)->default('manual');
            $table->string('catatan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['karyawan_id', 'tahun']);
        });

        // Cuti Besar: the employee's choice for each 5-year entitlement (none yet = not chosen).
        Schema::create('cuti_besars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('periode_ke');
            $table->string('keputusan', 20);
            $table->string('status', 20)->default('disetujui');
            $table->string('sumber', 20)->default('hr');
            $table->string('catatan')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['karyawan_id', 'periode_ke']);
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->string('kategori_cuti', 20)->nullable()->after('jenis');
            $table->unsignedSmallInteger('hari_cuti')->nullable()->after('kategori_cuti');
            $table->unsignedSmallInteger('potong_tahunan')->default(0)->after('hari_cuti');
            $table->unsignedSmallInteger('potong_besar')->default(0)->after('potong_tahunan');
            $table->unsignedSmallInteger('cuti_besar_periode')->nullable()->after('potong_besar');
        });

        // Admins of Deklarasi get the new menu at the same level.
        foreach (DB::table('users')->whereNotNull('permissions')->get(['id', 'permissions']) as $user) {
            $permissions = json_decode($user->permissions, true) ?: [];
            if (isset($permissions['deklarasi']) && ! isset($permissions['cuti'])) {
                $permissions['cuti'] = $permissions['deklarasi'];
                DB::table('users')->where('id', $user->id)->update(['permissions' => json_encode($permissions)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['kategori_cuti', 'hari_cuti', 'potong_tahunan', 'potong_besar', 'cuti_besar_periode']);
        });
        Schema::dropIfExists('cuti_besars');
        Schema::dropIfExists('cuti_pengambilans');
        Schema::dropIfExists('cuti_lapangans');
        Schema::dropIfExists('cuti_aturans');
    }
};
