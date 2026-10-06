<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan Struktur Organisasi:
 *  - Sub Lokasi Kerja: a place under a work location (e.g. a port or an office in
 *    town) for people assigned away from their site; it has its own positions and
 *    hangs under a position (usually the Project Manager) in the plan.
 *  - An employee keeps their own placement (SubGroup, unit, Nama Jabatan, Sub
 *    Lokasi). They hold a position once one matching it exists in the plan;
 *    until then they wait to be placed (jabatan_id empty).
 */
return new class extends Migration
{
    private const PLACEMENT = ['sub_group_id', 'bod_id', 'divisi_id', 'departemen_id', 'section_id', 'bidang'];

    public function up(): void
    {
        Schema::create('sub_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_location_id')->constrained()->restrictOnDelete();
            $table->string('name');
            // Where its box hangs in the plan; empty = not in the plan yet.
            $table->foreignId('parent_jabatan_id')->nullable()->constrained('jabatans')->nullOnDelete();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
            $table->unique(['work_location_id', 'name']);
        });

        Schema::table('jabatans', function (Blueprint $table) {
            $table->foreignId('sub_location_id')->nullable()->after('work_location_id')->constrained()->restrictOnDelete();
        });

        Schema::table('karyawans', function (Blueprint $table) {
            $table->unsignedBigInteger('jabatan_id')->nullable()->change();
            $table->foreignId('sub_location_id')->nullable()->after('work_location_id')->constrained()->nullOnDelete();
            $table->foreignId('sub_group_id')->nullable()->after('sub_location_id')->constrained()->nullOnDelete();
            $table->foreignId('bod_id')->nullable()->after('sub_group_id')->constrained()->nullOnDelete();
            $table->foreignId('divisi_id')->nullable()->after('bod_id')->constrained()->nullOnDelete();
            $table->foreignId('departemen_id')->nullable()->after('divisi_id')->constrained('departemens')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->after('departemen_id')->constrained()->nullOnDelete();
            $table->string('bidang', 100)->nullable()->after('section_id');
        });

        // Everyone's placement so far is the one of their position.
        $jabatans = DB::table('jabatans')->get(['id', ...self::PLACEMENT])->keyBy('id');
        DB::table('karyawans')->whereNotNull('jabatan_id')->get(['id', 'jabatan_id'])->each(function ($k) use ($jabatans) {
            if ($j = $jabatans->get($k->jabatan_id)) {
                DB::table('karyawans')->where('id', $k->id)->update(collect(self::PLACEMENT)->mapWithKeys(fn ($c) => [$c => $j->{$c}])->all());
            }
        });
        // Lists group people by departemen: those in a section get its departemen.
        $departemenOf = DB::table('sections')->pluck('departemen_id', 'id');
        DB::table('karyawans')->whereNotNull('section_id')->whereNull('departemen_id')->get(['id', 'section_id'])
            ->each(fn ($k) => DB::table('karyawans')->where('id', $k->id)->update(['departemen_id' => $departemenOf[$k->section_id] ?? null]));
    }

    public function down(): void
    {
        Schema::table('karyawans', function (Blueprint $table) {
            foreach (['sub_location_id', 'sub_group_id', 'bod_id', 'divisi_id', 'departemen_id', 'section_id'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
            $table->dropColumn('bidang');
        });
        Schema::table('jabatans', fn (Blueprint $table) => $table->dropConstrainedForeignId('sub_location_id'));
        Schema::dropIfExists('sub_locations');
    }
};
