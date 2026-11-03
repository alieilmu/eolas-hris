<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 week 7: clocking in and out from the phone — the places a work location's
 * employees may do it from (geofences), the location, accuracy and selfie of each punch,
 * the employee's consent (UU PDP), and how long the selfies are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_locations', function (Blueprint $table) {
            // off: no clocking from the phone | reject: only inside a geofence | flag: outside is taken, marked
            $table->string('mobile_attendance', 10)->default('off');
        });

        Schema::create('work_location_geofences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_location_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('radius_m')->default(200);
            $table->timestamps();
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('accuracy_m')->nullable();
            // Nearest geofence and the distance to it; outside = beyond its radius.
            $table->foreignId('geofence_id')->nullable()->constrained('work_location_geofences')->nullOnDelete();
            $table->unsignedInteger('distance_m')->nullable();
            $table->boolean('outside_geofence')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamp('photo_deleted_at')->nullable();
            $table->string('client', 160)->nullable();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->timestamp('attendance_consent_at')->nullable();
            $table->string('attendance_consent_version', 20)->nullable();
        });

        Schema::create('mobile_attendance_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('photo_retention_days')->default(90);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_attendance_settings');
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['attendance_consent_at', 'attendance_consent_version']));
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('geofence_id');
            $table->dropColumn(['latitude', 'longitude', 'accuracy_m', 'distance_m', 'outside_geofence', 'photo_path', 'photo_deleted_at', 'client']);
        });
        Schema::dropIfExists('work_location_geofences');
        Schema::table('work_locations', fn (Blueprint $table) => $table->dropColumn('mobile_attendance'));
    }
};
