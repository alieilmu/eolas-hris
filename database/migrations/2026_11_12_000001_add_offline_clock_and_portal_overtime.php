<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 week 8: punches taken on the phone without a signal and sent later — the phone's
 * own time of the tap, when the server got it, how far the phone's clock was off, and
 * whether that looks wrong — with how old a queued punch may be and how much a phone's
 * clock may differ before it is marked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            // The phone's id of the punch: sent twice (a retry), taken once.
            $table->string('client_uuid', 36)->nullable()->unique();
            // The phone's clock at the tap, and the server's when the punch arrived.
            $table->timestamp('device_time')->nullable();
            $table->timestamp('received_at')->nullable();
            // Server time minus phone time when sending (seconds); a wrong phone clock shows here.
            $table->integer('clock_offset_s')->nullable();
            // Taken without a signal and sent later (null: not from the phone).
            $table->boolean('offline')->nullable();
            $table->boolean('time_suspect')->nullable();
        });

        Schema::table('mobile_attendance_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('offline_max_hours')->default(24);
            $table->unsignedSmallInteger('clock_tolerance_minutes')->default(5);
        });
    }

    public function down(): void
    {
        Schema::table('mobile_attendance_settings', fn (Blueprint $table) => $table->dropColumn(['offline_max_hours', 'clock_tolerance_minutes']));
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn(['client_uuid', 'device_time', 'received_at', 'clock_offset_s', 'offline', 'time_suspect']);
        });
    }
};
