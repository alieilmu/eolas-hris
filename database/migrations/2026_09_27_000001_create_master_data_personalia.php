<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Master Data Personalia.
 *
 * NRP = J LL YY NNN: jenis karyawan (1 digit), lokasi penerimaan (2), tahun
 * masuk (2), nomor urut (3). Jenis, tempat penerimaan and status asal are
 * derived from the NRP through the two code tables below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jenis_karyawans', function (Blueprint $table) {
            $table->id();
            $table->char('kode', 1)->unique(); // first NRP digit
            $table->string('nama');
            $table->timestamps();
        });

        Schema::create('lokasi_penerimaans', function (Blueprint $table) {
            $table->id();
            $table->char('kode', 2)->unique(); // NRP digits 2–3
            $table->string('nama');
            // Decides status asal: Lokal when it matches the position's work location.
            $table->foreignId('work_location_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // Choice lists of the profile form (agama, pendidikan, ukuran, ...) and alasan keluar.
        Schema::create('master_options', function (Blueprint $table) {
            $table->id();
            $table->string('kategori', 40);
            $table->string('nilai');
            $table->unsignedInteger('urutan')->default(0);
            // Values the program relies on (e.g. "Menikah") cannot be renamed or removed.
            $table->boolean('sistem')->default(false);
            $table->timestamps();

            $table->unique(['kategori', 'nilai']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_options');
        Schema::dropIfExists('lokasi_penerimaans');
        Schema::dropIfExists('jenis_karyawans');
    }
};
