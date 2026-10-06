<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Position names are composed, not typed: a SubGroup says where the rest of the
 * name comes from (a BOD, Divisi, Departemen, Section, the work location, or a
 * "bidang" from the master list) and which title goes in front. Every position
 * belongs to one work location, so the same title at HO and at a site are two
 * positions. Layer Jabatan is gone: the SubGroup layer (1–6) is the one level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_groups', function (Blueprint $table) {
            // bod | divisi | departemen | section | work_location | bidang
            $table->string('sumber', 20)->default('bidang')->after('layer');
            $table->string('gelar', 60)->nullable()->after('sumber');
            // Title used instead at a Head Office location (e.g. OFFICER rather than GROUP LEADER).
            $table->string('gelar_ho', 60)->nullable()->after('gelar');
            // For positions named after the work location (Project Manager): who they report to.
            $table->foreignId('atasan_sub_group_id')->nullable()->after('gelar_ho')->constrained('sub_groups')->nullOnDelete();
            $table->string('atasan_lokasi', 10)->nullable()->after('atasan_sub_group_id'); // ho | sama
        });

        Schema::table('work_locations', function (Blueprint $table) {
            // Short name used in titles, e.g. PROJECT MANAGER RMTU.
            $table->string('kode', 20)->nullable()->after('name');
        });

        Schema::table('jabatans', function (Blueprint $table) {
            $table->foreignId('work_location_id')->nullable()->after('section_id')->constrained()->restrictOnDelete();
            // The master-list value the name was built from (e.g. PRODUCTION).
            $table->string('bidang', 100)->nullable()->after('work_location_id');
        });

        Schema::table('jabatans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('layer_jabatan_id');
        });
        Schema::dropIfExists('layer_jabatans');
    }

    public function down(): void
    {
        Schema::create('layer_jabatans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedTinyInteger('level');
            $table->timestamps();
        });
        Schema::table('jabatans', function (Blueprint $table) {
            $table->foreignId('layer_jabatan_id')->nullable()->constrained()->restrictOnDelete();
            $table->dropConstrainedForeignId('work_location_id');
            $table->dropColumn('bidang');
        });
        Schema::table('work_locations', fn (Blueprint $table) => $table->dropColumn('kode'));
        Schema::table('sub_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('atasan_sub_group_id');
            $table->dropColumn(['sumber', 'gelar', 'gelar_ho', 'atasan_lokasi']);
        });
    }
};
