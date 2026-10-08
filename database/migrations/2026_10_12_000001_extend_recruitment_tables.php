<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Recruitment on Laravel: what a vacancy says about itself, which question holds the
 * name / email / phone / position / CV, the applicant's status, and the candidate's link to
 * the application, phone, CV and rejection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_forms', function (Blueprint $table) {
            $table->string('posisi', 120)->nullable()->after('description');
            $table->string('lokasi', 120)->nullable()->after('posisi');
            $table->date('batas_lamaran')->nullable()->after('lokasi');
        });

        Schema::table('form_fields', function (Blueprint $table) {
            // nama | email | no_hp | posisi | portofolio | cv: what the answer is used for in Tracking.
            $table->string('kunci', 20)->nullable()->after('type');
        });

        Schema::table('applicants', function (Blueprint $table) {
            // baru | diproses (in Tracking) | ditolak
            $table->string('status', 20)->default('baru')->after('data')->index();
            $table->string('catatan')->nullable()->after('status');
        });

        Schema::table('candidates', function (Blueprint $table) {
            $table->foreignId('applicant_id')->nullable()->after('recruitment_stage_id')->constrained()->nullOnDelete();
            $table->string('no_hp', 30)->nullable()->after('email');
            // The CV on the private disk (from the application, or uploaded by HR).
            $table->string('cv_path')->nullable()->after('cv_url');
            $table->string('cv_nama')->nullable()->after('cv_path');
            $table->timestamp('ditolak_at')->nullable()->after('order');
            $table->string('alasan_ditolak')->nullable()->after('ditolak_at');
        });

        DB::table('applicants')->where('is_processed', true)->update(['status' => 'diproses']);
        // The questions of existing forms, by their usual labels.
        foreach (['nama' => ['Nama Lengkap', 'Nama'], 'email' => ['Email'], 'no_hp' => ['No HP', 'Nomor HP', 'No. HP', 'WhatsApp'], 'posisi' => ['Posisi', 'Position'],
            'portofolio' => ['Portofolio', 'Portfolio Link', 'Portfolio'], 'cv' => ['CV', 'CV (PDF)']] as $kunci => $labels) {
            DB::table('form_fields')->whereIn('label', $labels)->update(['kunci' => $kunci]);
        }
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('applicant_id');
            $table->dropColumn(['no_hp', 'cv_path', 'cv_nama', 'ditolak_at', 'alasan_ditolak']);
        });
        Schema::table('applicants', fn (Blueprint $table) => $table->dropColumn(['status', 'catatan']));
        Schema::table('form_fields', fn (Blueprint $table) => $table->dropColumn('kunci'));
        Schema::table('application_forms', fn (Blueprint $table) => $table->dropColumn(['posisi', 'lokasi', 'batas_lamaran']));
    }
};
