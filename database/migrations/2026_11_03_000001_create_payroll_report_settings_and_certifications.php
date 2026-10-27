<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Week 5:
 *  - payroll_settings: the company as tax withholder (NPWP, NITKU) and payer (bank account),
 *    and the accounts of the payroll journal;
 *  - certification_types / employee_certifications: licences and certificates that expire
 *    (STR, SIP, SIMPER, KIMPER, SKK …), with reminders before they do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('payroll_settings', 'company_tax_id')) {
                $t->string('company_tax_id', 20)->nullable();
                $t->string('company_nitku', 30)->nullable();
                $t->string('payer_bank', 30)->nullable();
                $t->string('payer_account', 40)->nullable();
                $t->json('journal_accounts')->nullable();
            }
        });

        if (! Schema::hasTable('certification_types')) {
            Schema::create('certification_types', function (Blueprint $t) {
                $t->id();
                $t->string('code', 20)->unique();
                $t->string('name', 120);
                $t->string('description', 255)->nullable();
                $t->boolean('expires')->default(true);
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
            $now = now();
            DB::table('certification_types')->insert(array_map(fn (array $c) => [
                'code' => $c[0], 'name' => $c[1], 'description' => $c[2], 'expires' => true, 'sort_order' => $c[3], 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ], [
                ['STR', 'Surat Tanda Registrasi (STR)', 'Tenaga kesehatan: dokter, perawat, bidan, apoteker', 10],
                ['SIP', 'Surat Izin Praktik (SIP)', 'Tenaga kesehatan, per fasilitas', 20],
                ['SIMPER', 'SIMPER (izin mengemudi di area tambang)', 'Pertambangan', 30],
                ['KIMPER', 'KIMPER (izin mengoperasikan unit)', 'Pertambangan', 40],
                ['POP', 'Pengawas Operasional Pertama (POP)', 'Pertambangan', 50],
                ['POM', 'Pengawas Operasional Madya (POM)', 'Pertambangan', 60],
                ['SKK', 'Sertifikat Kompetensi Kerja (SKK) Konstruksi', 'Konstruksi', 70],
                ['K3', 'Sertifikat Ahli K3 Umum', 'Semua industri', 80],
                ['SIO', 'Surat Izin Operator (SIO) Kemnaker', 'Operator alat angkat & angkut', 90],
                ['HALAL', 'Sertifikat Penyelia Halal', 'F&B, manufaktur pangan', 100],
            ]));
        }

        if (! Schema::hasTable('employee_certifications')) {
            Schema::create('employee_certifications', function (Blueprint $t) {
                $t->id();
                $t->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $t->foreignId('certification_type_id')->constrained('certification_types');
                $t->string('number', 80)->nullable();
                $t->string('issuer', 120)->nullable();
                $t->date('issued_at')->nullable();
                $t->date('expires_at')->nullable();
                $t->string('notes', 255)->nullable();
                // The last reminder sent (90, 60, 30, 0 days): each once.
                $t->unsignedSmallInteger('reminded_days')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['expires_at']);
                $t->index(['employee_id', 'certification_type_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_certifications');
        Schema::dropIfExists('certification_types');
        Schema::table('payroll_settings', function (Blueprint $t) {
            if (Schema::hasColumn('payroll_settings', 'company_tax_id')) {
                $t->dropColumn(['company_tax_id', 'company_nitku', 'payer_bank', 'payer_account', 'journal_accounts']);
            }
        });
    }
};
