<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll phase 3: stored payroll runs.
 *  - payroll_runs: one period (regular) for all work locations or one, and its approval flow;
 *  - payslips: one employee's pay in a run, with a snapshot of who they were then and the
 *    full calculation, so a paid slip never changes when master data does;
 *  - payslip_lines: the slip's earnings, deductions and company contributions, for recaps.
 * Also: the UMK per work location (floor of the BPJS Kesehatan base), extra BPJS Kesehatan
 * family members per employee, and whether slip PDFs carry a password.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_runs')) {
            Schema::create('payroll_runs', function (Blueprint $t) {
                $t->id();
                $t->string('period', 7); // 2026-10
                $t->string('type', 20)->default('regular');
                $t->foreignId('work_location_id')->nullable()->constrained('work_locations')->nullOnDelete();
                $t->date('period_start');
                $t->date('period_end');
                $t->date('pay_date')->nullable();
                $t->string('status', 20)->default('draft');
                $t->unsignedInteger('employee_count')->default(0);
                $t->unsignedInteger('progress')->default(0);
                $t->json('totals')->nullable();
                $t->text('notes')->nullable();
                $t->text('rejection_note')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                foreach (['calculated', 'submitted', 'approved', 'paid', 'locked'] as $step) {
                    $t->foreignId("{$step}_by")->nullable()->constrained('users')->nullOnDelete();
                    $t->timestamp("{$step}_at")->nullable();
                }
                $t->timestamps();
                $t->index(['period', 'type']);
            });
        }

        if (! Schema::hasTable('payslips')) {
            Schema::create('payslips', function (Blueprint $t) {
                $t->id();
                $t->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
                $t->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $t->foreignId('work_location_id')->nullable()->constrained('work_locations')->nullOnDelete();
                $t->string('employee_number', 30)->nullable();
                $t->string('name', 150);
                $t->json('snapshot');
                $t->bigInteger('wage')->default(0);
                $t->bigInteger('gross')->default(0);
                $t->bigInteger('total_earnings')->default(0);
                $t->bigInteger('total_deductions')->default(0);
                $t->bigInteger('tax')->default(0);
                $t->bigInteger('tax_withheld')->default(0);
                $t->bigInteger('employee_contributions')->default(0); // JHT + JP employee, for the annual tax
                $t->bigInteger('company_cost')->default(0);
                $t->bigInteger('take_home_pay')->default(0);
                $t->decimal('prorate', 6, 4)->default(1);
                $t->text('error')->nullable();
                $t->json('warnings')->nullable();
                $t->json('detail')->nullable();
                $t->timestamps();
                $t->unique(['payroll_run_id', 'employee_id']);
                $t->index('employee_id');
            });
        }

        if (! Schema::hasTable('payslip_lines')) {
            Schema::create('payslip_lines', function (Blueprint $t) {
                $t->id();
                $t->foreignId('payslip_id')->constrained('payslips')->cascadeOnDelete();
                $t->string('kind', 12); // earning, deduction, company
                $t->string('code', 40);
                $t->string('label', 100);
                $t->bigInteger('amount');
                $t->string('note', 150)->nullable();
                $t->unsignedSmallInteger('sort')->default(0);
                $t->index(['payslip_id', 'kind']);
            });
        }

        if (! Schema::hasColumn('work_locations', 'minimum_wage')) {
            Schema::table('work_locations', fn (Blueprint $t) => $t->unsignedBigInteger('minimum_wage')->nullable());
        }
        if (! Schema::hasColumn('employee_payroll_profiles', 'health_extra_members')) {
            Schema::table('employee_payroll_profiles', fn (Blueprint $t) => $t->unsignedTinyInteger('health_extra_members')->default(0));
        }
        if (! Schema::hasColumn('payroll_settings', 'payslip_password')) {
            Schema::table('payroll_settings', fn (Blueprint $t) => $t->boolean('payslip_password')->default(true));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        if (Schema::hasColumn('work_locations', 'minimum_wage')) {
            Schema::table('work_locations', fn (Blueprint $t) => $t->dropColumn('minimum_wage'));
        }
        if (Schema::hasColumn('employee_payroll_profiles', 'health_extra_members')) {
            Schema::table('employee_payroll_profiles', fn (Blueprint $t) => $t->dropColumn('health_extra_members'));
        }
        if (Schema::hasColumn('payroll_settings', 'payslip_password')) {
            Schema::table('payroll_settings', fn (Blueprint $t) => $t->dropColumn('payslip_password'));
        }
    }
};
