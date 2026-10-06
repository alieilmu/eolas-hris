<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Section: the organisation level under Departemen. A position now belongs to
 * exactly one unit — a BOD, Divisi, Departemen or Section (one of the four
 * columns is set). Reporting lines (parent_jabatan_id) stay inside a unit; the
 * superior of a unit's top position comes from the unit above it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('departemen_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
            $table->unique(['departemen_id', 'name']);
        });

        Schema::table('jabatans', function (Blueprint $table) {
            // MySQL lets the departemen foreign key lean on the unique index; give it its own first.
            $table->index('departemen_id');
            $table->dropUnique(['departemen_id', 'name']);
        });

        Schema::table('jabatans', function (Blueprint $table) {
            $table->foreignId('bod_id')->nullable()->after('sub_group_id')->constrained()->restrictOnDelete();
            $table->foreignId('divisi_id')->nullable()->after('bod_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('departemen_id')->nullable()->change();
            $table->foreignId('section_id')->nullable()->after('departemen_id')->constrained()->restrictOnDelete();
        });

        // Lines that crossed departemen become unit-to-unit relations, which the structure now implies.
        $departemenOf = DB::table('jabatans')->pluck('departemen_id', 'id');
        DB::table('jabatans')->whereNotNull('parent_jabatan_id')->get(['id', 'parent_jabatan_id', 'departemen_id'])
            ->filter(fn ($j) => ($departemenOf[$j->parent_jabatan_id] ?? null) !== $j->departemen_id)
            ->each(fn ($j) => DB::table('jabatans')->where('id', $j->id)->update(['parent_jabatan_id' => null]));
    }

    public function down(): void
    {
        Schema::table('jabatans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
            $table->dropConstrainedForeignId('divisi_id');
            $table->dropConstrainedForeignId('bod_id');
            $table->unique(['departemen_id', 'name']);
        });
        Schema::dropIfExists('sections');
    }
};
