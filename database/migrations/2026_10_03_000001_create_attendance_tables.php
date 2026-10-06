<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Attendance:
 *  - Kode Shift: the codes used in Roster and Absensi, with their DWS (the code
 *    exported to payroll) and the kind of day they mean (work, off, leave, ...).
 *  - Pola Roster: repeating cycles (10:2, 6:1, ...) to fill a roster quickly.
 *  - Hari Libur: public holidays, marked in the monthly tables.
 *  - Roster (the plan) and Absensi (what happened): one code per employee per day.
 *  - Trip & Leave: business trips and leave; each one fills its days in Absensi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_codes', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 10)->unique();
            $table->string('dws', 30)->nullable();
            $table->string('keterangan', 150)->nullable();
            // hadir | dinas | off | cuti | sakit | izin | mangkir — drives the percentages and Man Onsite.
            $table->string('jenis', 20);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('roster_patterns', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 60)->unique();
            // [{"shift_code_id": 1, "hari": 10}, {"shift_code_id": 3, "hari": 2}]
            $table->json('langkah');
            $table->timestamps();
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal')->unique();
            $table->string('nama', 120);
            $table->timestamps();
        });

        Schema::create('rosters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->constrained()->cascadeOnDelete();
            $table->date('tanggal')->index();
            $table->foreignId('shift_code_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['karyawan_id', 'tanggal']);
        });

        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->constrained()->cascadeOnDelete();
            $table->string('jenis', 10); // dinas | cuti
            $table->date('tanggal_awal');
            $table->date('tanggal_akhir');
            $table->string('kota_asal', 100);
            $table->string('kota_tujuan', 100);
            $table->string('keterangan', 255)->nullable();
            // A value of the "Status Pengajuan Uang" list (master_options).
            $table->string('status_uang', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tanggal_awal', 'tanggal_akhir']);
        });

        Schema::create('absensis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->constrained()->cascadeOnDelete();
            $table->date('tanggal')->index();
            $table->foreignId('shift_code_id')->constrained()->restrictOnDelete();
            // manual | roster (copied from it) | import | trip (filled by a Trip & Leave entry)
            $table->string('sumber', 10)->default('manual');
            // Days filled by a trip go with it when it is removed or moved.
            $table->foreignId('trip_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['karyawan_id', 'tanggal']);
        });

        $now = now();
        $codes = [
            ['D', 'DAY', 'Day shift', 'hadir'],
            ['N', 'NIGHT', 'Night shift', 'hadir'],
            ['OFF', 'OFF', 'Off / libur roster', 'off'],
            ['CT', 'CUTI', 'Cuti', 'cuti'],
            ['DL', 'DINAS', 'Dinas luar / perjalanan dinas', 'dinas'],
            ['S', 'SAKIT', 'Sakit', 'sakit'],
            ['I', 'IZIN', 'Izin', 'izin'],
            ['A', 'ALPA', 'Mangkir / tanpa keterangan', 'mangkir'],
        ];
        foreach ($codes as $i => [$kode, $dws, $keterangan, $jenis]) {
            DB::table('shift_codes')->insert(compact('kode', 'dws', 'keterangan', 'jenis') + ['order' => $i, 'created_at' => $now, 'updated_at' => $now]);
        }
        $id = DB::table('shift_codes')->pluck('id', 'kode');
        $patterns = [
            '5:2 (Senin–Jumat)' => [['D', 5], ['OFF', 2]],
            '6:1' => [['D', 6], ['OFF', 1]],
            '10:2' => [['D', 10], ['OFF', 2]],
            '7D 7N 7OFF' => [['D', 7], ['N', 7], ['OFF', 7]],
        ];
        foreach ($patterns as $nama => $steps) {
            DB::table('roster_patterns')->insert([
                'nama' => $nama,
                'langkah' => json_encode(array_map(fn ($s) => ['shift_code_id' => $id[$s[0]], 'hari' => $s[1]], $steps)),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (['Belum diajukan', 'Diajukan', 'Disetujui', 'Dicairkan', 'Selesai', 'Ditolak'] as $urutan => $nilai) {
            DB::table('master_options')->insertOrIgnore(['kategori' => 'status_uang', 'nilai' => $nilai, 'urutan' => $urutan, 'sistem' => false, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('absensis');
        Schema::dropIfExists('trips');
        Schema::dropIfExists('rosters');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('roster_patterns');
        Schema::dropIfExists('shift_codes');
        DB::table('master_options')->where('kategori', 'status_uang')->delete();
    }
};
