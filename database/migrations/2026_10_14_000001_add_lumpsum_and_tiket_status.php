<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lumpsum Cuti Lapangan per site × POH (lokasi penerimaan of the NRP) × klasifikasi
 * (Staff / Non Staff), the lumpsum a cuti got, and whether its tickets are bought.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lumpsum_cutis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lokasi_penerimaan_id')->constrained()->cascadeOnDelete();
            $table->string('klasifikasi', 50);
            $table->unsignedBigInteger('nominal');
            $table->timestamps();
            $table->unique(['work_location_id', 'lokasi_penerimaan_id', 'klasifikasi'], 'lumpsum_cutis_unik');
        });

        Schema::table('trips', function (Blueprint $table) {
            // Set when the cuti is entered / changed, from the table above.
            $table->unsignedBigInteger('lumpsum')->nullable()->after('cuti_besar_periode');
            $table->timestamp('tiket_pergi_dibeli_at')->nullable()->after('uang_muka_nominal');
            $table->foreignId('tiket_pergi_dibeli_by')->nullable()->after('tiket_pergi_dibeli_at')->constrained('users')->nullOnDelete();
            $table->timestamp('tiket_pulang_dibeli_at')->nullable()->after('tiket_pergi_dibeli_by');
            $table->foreignId('tiket_pulang_dibeli_by')->nullable()->after('tiket_pulang_dibeli_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tiket_pergi_dibeli_by');
            $table->dropConstrainedForeignId('tiket_pulang_dibeli_by');
            $table->dropColumn(['lumpsum', 'tiket_pergi_dibeli_at', 'tiket_pulang_dibeli_at']);
        });
        Schema::dropIfExists('lumpsum_cutis');
    }
};
