<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The return flight of a round trip (PP), and the advance money (uang muka) asked with a dinas. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->date('pulang_tanggal')->nullable()->after('pesawat_pp');
            $table->string('pulang_bandara_asal', 3)->nullable()->after('pulang_tanggal');
            $table->string('pulang_bandara_tujuan', 3)->nullable()->after('pulang_bandara_asal');
            $table->boolean('uang_muka')->default(false)->after('tiket_kereta');
            $table->unsignedBigInteger('uang_muka_nominal')->nullable()->after('uang_muka');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['pulang_tanggal', 'pulang_bandara_asal', 'pulang_bandara_tujuan', 'uang_muka', 'uang_muka_nominal']);
        });
    }
};
