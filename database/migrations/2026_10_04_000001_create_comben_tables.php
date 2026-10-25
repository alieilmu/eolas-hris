<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Comben:
 *  - Golongan per group (NONOM, OPERATOR, ...); an employee's profile names one
 *    (karyawan_profiles.golongan holds its code).
 *  - Struktur Skala Upah: a wage per golongan (and step), per column (a year,
 *    optionally for one work location).
 *  - Plafon Pengobatan (per family, per year: lajang / keluarga) and Plafon
 *    Kacamata (per family member: bingkai every 2 years, lensa every year), each
 *    row covering a set of SubGroups and the golongan expected for them.
 *  - Deklarasi: a claim form with its lines (perawatan / pengobatan / bingkai /
 *    lensa, for the employee, the spouse or a child), booked to a month that
 *    closes on the 8th.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('golongans', function (Blueprint $table) {
            $table->id();
            $table->string('grup', 30);
            $table->string('kode', 10)->unique();
            $table->unsignedInteger('urutan')->default(0);
            $table->timestamps();
        });

        Schema::create('skala_upah_kolom', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('tahun');
            // Empty: every work location.
            $table->foreignId('work_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('urutan')->default(0);
            $table->timestamps();
            $table->unique(['tahun', 'work_location_id']);
        });

        Schema::create('skala_upahs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('golongan_id')->constrained()->cascadeOnDelete();
            // e.g. "Step 1", "MID"; empty when the group has no steps.
            $table->string('step', 20)->nullable();
            $table->unsignedInteger('urutan')->default(0);
            $table->timestamps();
        });

        Schema::create('skala_upah_nilai', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skala_upah_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kolom_id')->constrained('skala_upah_kolom')->cascadeOnDelete();
            $table->unsignedBigInteger('nominal');
            $table->unique(['skala_upah_id', 'kolom_id']);
        });

        foreach (['plafon_pengobatans', 'plafon_kacamatas'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->string('layer', 10);
                // golongan ids expected for this row, and the SubGroups it applies to.
                $table->json('golongan_ids');
                $table->json('sub_group_ids');
                $table->string('jabatan_ho', 100)->nullable();
                $table->string('jabatan_site', 100)->nullable();
                if ($name === 'plafon_pengobatans') {
                    $table->unsignedBigInteger('lajang');
                    $table->unsignedBigInteger('keluarga');
                } else {
                    $table->unsignedBigInteger('bingkai');
                    $table->unsignedBigInteger('lensa');
                }
                $table->unsignedInteger('urutan')->default(0);
                $table->timestamps();
            });
        }

        Schema::create('deklarasis', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();
            $table->foreignId('karyawan_id')->constrained()->cascadeOnDelete();
            $table->date('tanggal');
            $table->string('tempat', 100)->nullable();
            $table->unsignedSmallInteger('jumlah_kwitansi')->default(1);
            // The book month ("2026-10": 9 Sep – 8 Oct) and the plafon year it counts against.
            $table->string('bulan_buku', 7)->index();
            $table->unsignedSmallInteger('tahun_plafon')->index();
            $table->string('keterangan', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('deklarasi_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deklarasi_id')->constrained()->cascadeOnDelete();
            // perawatan | pengobatan (Plafon Pengobatan) | bingkai | lensa (Plafon Kacamata)
            $table->string('jenis', 12);
            // karyawan | pasangan | anak
            $table->string('penerima', 10);
            $table->foreignId('karyawan_anak_id')->nullable()->constrained('karyawan_anak')->nullOnDelete();
            $table->unsignedBigInteger('nominal');
        });

        // Plafon Pengobatan now follows from SubGroup, golongan and family; it is no longer typed in.
        Schema::table('karyawan_profiles', function (Blueprint $table) {
            $table->dropColumn('plafon_pengobatan');
        });

        // The live database was given its Comben master data here when this ran (Oct 2026); a fresh
        // one is seeded by CompensationSeeder after every migration (the classes now use the new names).
    }

    public function down(): void
    {
        Schema::dropIfExists('deklarasi_items');
        Schema::dropIfExists('deklarasis');
        Schema::dropIfExists('plafon_kacamatas');
        Schema::dropIfExists('plafon_pengobatans');
        Schema::dropIfExists('skala_upah_nilai');
        Schema::dropIfExists('skala_upahs');
        Schema::dropIfExists('skala_upah_kolom');
        Schema::dropIfExists('golongans');
        Schema::table('karyawan_profiles', function (Blueprint $table) {
            $table->decimal('plafon_pengobatan', 15, 2)->nullable();
        });
    }
};
