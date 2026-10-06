<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Master data karyawan (122 field / 19 kelompok).
 * Derived values (usia, masa kerja, status akhir PKWT) are computed on read
 * in the models and deliberately not stored here.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('karyawans', function (Blueprint $table) {
            $table->id();
            $table->string('nrp', 20)->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('status')->default('Aktif')->index(); // Aktif | Nonaktif | Keluar
            // For "Keluar" this stays as the last position held.
            $table->foreignId('jabatan_id')->constrained()->restrictOnDelete();
            // Where this employee works; compared with the NRP's lokasi penerimaan for status asal.
            $table->foreignId('work_location_id')->constrained()->restrictOnDelete();
            $table->foreignId('candidate_id')->nullable()->unique()->constrained()->nullOnDelete();

            // Filled when status becomes "Keluar".
            $table->date('tanggal_keluar')->nullable();
            $table->string('alasan_keluar')->nullable();
            $table->text('keterangan_keluar')->nullable();
            $table->timestamps();
        });

        Schema::create('karyawan_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_id')->unique()->constrained()->cascadeOnDelete();

            // 1. Identitas & Pekerjaan
            $table->unsignedInteger('no_urut')->nullable();
            $table->string('nrp_sap')->nullable()->unique();
            $table->string('klasifikasi')->nullable(); // Staff | Non Staff
            $table->string('klasifikasi_teknis')->nullable(); // Staff/Operator | Mekanik/Nonom

            // 2. Kelahiran & Masa Kerja
            $table->string('tempat_lahir')->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->date('tanggal_masuk')->nullable();

            // 3. Status Pegawai & Perkawinan
            $table->string('status_pegawai')->nullable();
            $table->string('status_perkawinan')->nullable();
            $table->date('tanggal_perkawinan')->nullable();

            // 4. Golongan & Hak Normatif
            $table->string('golongan')->nullable();
            $table->decimal('plafon_pengobatan', 15, 2)->nullable();
            // Tempat penerimaan and status asal are read from the NRP (see App\Support\Karyawan\Nrp).

            // 6. Pengangkatan Pegawai (5. PKWT lives in pkwt_periods)
            $table->boolean('status_pegawai_tetap')->default(false);
            $table->string('nomor_sk')->nullable();
            $table->date('tanggal_sk')->nullable();

            // 7. Ukuran Seragam & APD
            $table->string('ukuran_baju')->nullable();
            $table->string('ukuran_celana')->nullable();
            $table->string('ukuran_sepatu')->nullable();
            $table->string('ukuran_apd')->nullable();

            // 8. Tanda Pengenal & Kepesertaan
            $table->string('nik', 16)->nullable()->unique();
            $table->string('nik_provinsi')->nullable();
            $table->string('nik_kabupaten_kota')->nullable();
            $table->string('npwp', 16)->nullable()->unique();
            $table->date('npwp_tanggal_daftar')->nullable();
            $table->string('bpjs_tk')->nullable()->unique();
            $table->date('bpjs_tk_tanggal_daftar')->nullable();
            $table->string('bpjs_kesehatan')->nullable()->unique();

            // 9. Rekening Bank
            $table->string('no_rekening')->nullable();
            $table->string('nama_bank')->nullable();
            $table->string('nama_pemilik_rekening')->nullable();

            // 10. Data Pribadi
            $table->string('agama')->nullable();
            $table->string('jenis_kelamin')->nullable();
            $table->string('suku_bangsa')->nullable();

            // 11. Riwayat Pendidikan
            $table->string('pendidikan_terakhir')->nullable();
            $table->string('jurusan')->nullable();
            $table->string('asal_sekolah')->nullable();
            $table->unsignedSmallInteger('tahun_lulus')->nullable();

            // 12. Alamat Tinggal
            $table->text('alamat_tinggal')->nullable();
            $table->string('alamat_tinggal_rt', 5)->nullable();
            $table->string('alamat_tinggal_rw', 5)->nullable();
            $table->string('alamat_tinggal_kab_kota')->nullable();
            $table->string('alamat_tinggal_provinsi')->nullable();
            $table->string('alamat_tinggal_kode_pos', 10)->nullable();

            // 13. Alamat Surat Menyurat
            $table->text('alamat_surat')->nullable();
            $table->string('alamat_surat_rt', 5)->nullable();
            $table->string('alamat_surat_rw', 5)->nullable();
            $table->string('alamat_surat_kab_kota')->nullable();
            $table->string('alamat_surat_kode_pos', 10)->nullable();
            $table->string('alamat_surat_telepon')->nullable();
            $table->string('alamat_surat_hp')->nullable();

            // 14. Kontak Darurat & Nomor Pribadi
            $table->string('emergency_telepon')->nullable();
            $table->string('emergency_nama')->nullable();
            $table->string('emergency_hubungan')->nullable();
            $table->string('no_hp')->nullable();
            $table->string('no_whatsapp')->nullable();

            // 15. Email
            $table->string('email_pribadi')->nullable();

            // 16. Keluarga Inti (pasangan) — 17. Data Anak lives in karyawan_anak
            $table->string('nama_pasangan')->nullable();
            $table->date('tanggal_lahir_pasangan')->nullable();
            $table->string('pekerjaan_pasangan')->nullable();

            // 18. Data Orang Tua
            $table->string('nama_ayah')->nullable();
            $table->date('tanggal_lahir_ayah')->nullable();
            $table->string('nama_ibu')->nullable();
            $table->date('tanggal_lahir_ibu')->nullable();
            $table->string('ortu_alamat_rt', 5)->nullable();
            $table->string('ortu_alamat_rw', 5)->nullable();
            $table->string('ortu_alamat_kab_kota')->nullable();
            $table->string('ortu_alamat_kode_pos', 10)->nullable();
            $table->string('ortu_telepon')->nullable();
            $table->string('ortu_hp')->nullable();

            // 19. Data Mertua
            $table->string('nama_ayah_mertua')->nullable();
            $table->date('tanggal_lahir_ayah_mertua')->nullable();
            $table->string('nama_ibu_mertua')->nullable();
            $table->date('tanggal_lahir_ibu_mertua')->nullable();
            $table->string('mertua_alamat_rt', 5)->nullable();
            $table->string('mertua_alamat_rw', 5)->nullable();
            $table->string('mertua_alamat_kab_kota')->nullable();
            $table->string('mertua_alamat_kode_pos', 10)->nullable();
            $table->string('mertua_hp')->nullable();

            $table->timestamps();
        });

        // 5. Kontrak Kerja (PKWT). The 6-period maximum is enforced in validation, not here.
        Schema::create('pkwt_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('periode_ke');
            $table->date('tanggal_kontrak');
            $table->date('tanggal_berakhir');
            $table->timestamps();

            $table->unique(['karyawan_profile_id', 'periode_ke']);
        });

        // 17. Data Anak — no cap on the number of children.
        Schema::create('karyawan_anak', function (Blueprint $table) {
            $table->id();
            $table->foreignId('karyawan_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('urutan');
            $table->string('nama');
            $table->date('tanggal_lahir')->nullable();
            $table->string('pendidikan')->nullable();
            $table->string('status_bekerja')->nullable();
            $table->string('status_menikah')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('karyawan_anak');
        Schema::dropIfExists('pkwt_periods');
        Schema::dropIfExists('karyawan_profiles');
        Schema::dropIfExists('karyawans');
    }
};
