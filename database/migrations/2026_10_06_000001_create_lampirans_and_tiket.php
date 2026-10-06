<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attachments (nota of a deklarasi, the signed cuti / dinas form) and the
 * flight / train tickets asked with a cuti or dinas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lampirans', function (Blueprint $table) {
            $table->id();
            $table->morphs('lampirable');
            $table->string('path');
            $table->string('nama');
            $table->string('mime', 100);
            $table->unsignedInteger('ukuran');
            // Images are one page; a PDF counts its pages (each page one nota).
            $table->unsignedSmallInteger('halaman')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->boolean('tiket_pesawat')->default(false)->after('keterangan');
            $table->string('bandara_asal', 3)->nullable()->after('tiket_pesawat');
            $table->string('bandara_tujuan', 3)->nullable()->after('bandara_asal');
            $table->boolean('pesawat_pp')->default(false)->after('bandara_tujuan');
            $table->boolean('tiket_kereta')->default(false)->after('pesawat_pp');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['tiket_pesawat', 'bandara_asal', 'bandara_tujuan', 'pesawat_pp', 'tiket_kereta']);
        });
        Schema::dropIfExists('lampirans');
    }
};
