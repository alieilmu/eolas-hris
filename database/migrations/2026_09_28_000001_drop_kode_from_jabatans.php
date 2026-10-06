<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A position is known by its name within its departemen; the separate
 * position code is dropped (imports now match on name + departemen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jabatans', function (Blueprint $table) {
            $table->dropUnique(['kode']);
            $table->dropColumn('kode');
            $table->unique(['departemen_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('jabatans', function (Blueprint $table) {
            $table->dropUnique(['departemen_id', 'name']);
            $table->string('kode')->nullable()->unique()->after('name');
        });
    }
};
