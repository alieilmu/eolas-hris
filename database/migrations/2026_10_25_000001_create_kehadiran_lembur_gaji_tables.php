<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kehadiran (clock in / clock out from attendance machines), Lembur, and the salary basis
 * for Payroll:
 *  - Kode Shift get their hours (masuk, keluar, istirahat), so a day's schedule is known.
 *  - Lokasi kerja get their working-time scheme (5 / 6 days, or the ESDM scheme of
 *    Kepmenakertrans 234/2003) and normal hours per day: the basis of overtime.
 *  - Mesin absen (pushing over ADMS, or imported) and their raw logs; a PIN per employee.
 *  - Lembur: overtime per employee per day, counted from CI/CO and approved before payroll.
 *  - Gaji: an employee's place in the Struktur Skala Upah (golongan + step) over time, and
 *    Tunjangan Jabatan per SubGroup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_codes', function (Blueprint $table) {
            $table->time('jam_masuk')->nullable()->after('jenis');
            $table->time('jam_keluar')->nullable()->after('jam_masuk');
            $table->time('istirahat_mulai')->nullable()->after('jam_keluar');
            $table->time('istirahat_selesai')->nullable()->after('istirahat_mulai');
        });

        Schema::table('work_locations', function (Blueprint $table) {
            // 5_hari (8 jam/hari) | 6_hari (7 jam/hari) | esdm (Kepmenakertrans 234/2003, roster)
            $table->string('skema_kerja', 10)->default('5_hari')->after('type');
            $table->decimal('jam_kerja_harian', 3, 1)->default(8)->after('skema_kerja');
        });

        Schema::table('karyawans', function (Blueprint $table) {
            // The employee's number in the attendance machines; empty = the NRP.
            $table->string('pin_absen', 30)->nullable()->unique()->after('nrp');
        });

        Schema::create('lembur_aturans', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('pembulatan_menit')->default(30);
            $table->unsignedSmallInteger('minimal_menit')->default(30);
            // Work before the scheduled start counts only when this is on.
            $table->boolean('hitung_sebelum_jadwal')->default(false);
            $table->unsignedTinyInteger('batas_harian_jam')->default(4);
            $table->unsignedTinyInteger('batas_mingguan_jam')->default(18);
            $table->timestamps();
        });

        Schema::create('sub_group_lembur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_group_id')->constrained()->cascadeOnDelete();
            $table->string('tipe_lokasi', 20); // Head Office | Site
            $table->boolean('berhak')->default(true);
            $table->timestamps();
            $table->unique(['sub_group_id', 'tipe_lokasi']);
        });

        Schema::create('mesin_absens', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 100);
            $table->string('serial_number', 60)->unique();
            $table->string('merek', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->foreignId('work_location_id')->nullable()->constrained()->nullOnDelete();
            // aktif | nonaktif | baru (pushed in but not registered yet: its data is not taken)
            $table->string('status', 10)->default('aktif');
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->string('attlog_stamp', 30)->nullable();
            $table->string('catatan', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('absen_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mesin_absen_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pin', 30);
            $table->foreignId('karyawan_id')->nullable()->constrained()->nullOnDelete();
            // The machine's own (local) clock time.
            $table->dateTime('waktu')->index();
            $table->unsignedTinyInteger('status_code')->nullable(); // 0 in, 1 out, 2 break out, 3 break in, 4 OT in, 5 OT out
            $table->string('verify', 12)->nullable();
            $table->string('sumber', 10)->default('mesin'); // mesin | import | manual
            $table->string('catatan', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['pin', 'waktu']);
            $table->index(['karyawan_id', 'waktu']);
        });

        Schema::create('lemburs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->constrained()->cascadeOnDelete();
            $table->date('tanggal');
            $table->string('jenis_hari', 10); // kerja | istirahat | libur
            $table->dateTime('mulai')->nullable();
            $table->dateTime('selesai')->nullable();
            $table->unsignedSmallInteger('menit_kerja')->default(0);
            $table->unsignedSmallInteger('menit_lembur')->default(0);
            // Hours paid (after rounding) and the same hours weighted by the legal multipliers.
            $table->decimal('jam_dibayar', 5, 2)->default(0);
            $table->decimal('jam_terhitung', 6, 2)->default(0);
            $table->string('status', 10)->default('menunggu'); // menunggu | disetujui | ditolak
            $table->string('sumber', 10)->default('mesin'); // mesin | manual
            $table->string('catatan', 255)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('catatan_verifikasi', 255)->nullable();
            $table->timestamps();
            $table->unique(['karyawan_id', 'tanggal']);
            $table->index(['tanggal', 'status']);
        });

        Schema::create('tunjangan_jabatans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_group_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('nominal')->default(0);
            $table->timestamps();
        });

        Schema::create('karyawan_gajis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skala_upah_id')->constrained()->restrictOnDelete();
            $table->date('berlaku_mulai');
            $table->string('alasan', 30)->default('penyesuaian'); // awal | kenaikan | promosi | penyesuaian
            $table->string('catatan', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['karyawan_id', 'berlaku_mulai']);
        });

        // The main database only: which database (workspace) a machine's pushes belong to.
        if (Schema::getConnection()->getDatabaseName() === config('database.connections.central.database')) {
            Schema::create('mesin_absen_routes', function (Blueprint $table) {
                $table->string('serial_number', 60)->primary();
                $table->string('database', 64)->nullable(); // null = the main application
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mesin_absen_routes');
        foreach (['karyawan_gajis', 'tunjangan_jabatans', 'lemburs', 'absen_logs', 'mesin_absens', 'sub_group_lembur', 'lembur_aturans'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('karyawans', fn (Blueprint $t) => $t->dropColumn('pin_absen'));
        Schema::table('work_locations', fn (Blueprint $t) => $t->dropColumn(['skema_kerja', 'jam_kerja_harian']));
        Schema::table('shift_codes', fn (Blueprint $t) => $t->dropColumn(['jam_masuk', 'jam_keluar', 'istirahat_mulai', 'istirahat_selesai']));
    }
};
