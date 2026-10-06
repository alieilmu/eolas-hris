<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Superiors are no longer fixed per SubGroup: they are arranged in the
 * organisation chart, where a superior may not be of a lower layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('atasan_sub_group_id');
            $table->dropColumn('atasan_lokasi');
        });
    }

    public function down(): void
    {
        Schema::table('sub_groups', function (Blueprint $table) {
            $table->foreignId('atasan_sub_group_id')->nullable()->after('gelar_ho')->constrained('sub_groups')->nullOnDelete();
            $table->string('atasan_lokasi', 10)->nullable()->after('atasan_sub_group_id'); // ho | sama
        });
    }
};
