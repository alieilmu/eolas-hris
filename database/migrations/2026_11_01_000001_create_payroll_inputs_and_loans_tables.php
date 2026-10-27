<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll phase 4:
 *  - payroll_components: what can be paid or deducted besides the fixed pay (bonus, insentif …);
 *  - payroll_inputs: an amount of a component for one employee in one period, approved before
 *    it is paid;
 *  - loans / loan_installments: employee loans and their monthly deductions;
 *  - payslip_lines.ref: which input or installment a slip line came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_components')) {
            Schema::create('payroll_components', function (Blueprint $t) {
                $t->id();
                $t->string('code', 30)->unique();
                $t->string('name', 100);
                $t->string('kind', 12); // earning, deduction
                $t->string('nature', 12)->default('variable'); // variable, irregular
                // Earning: part of the gross for PPh21. Deduction: taken before tax (lowers the gross) or after it.
                $t->boolean('taxable')->default(true);
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->boolean('is_active')->default(true);
                $t->boolean('is_system')->default(false);
                $t->timestamps();
            });
            $now = now();
            DB::table('payroll_components')->insert(array_map(fn (array $c) => [
                'code' => $c[0], 'name' => $c[1], 'kind' => $c[2], 'nature' => $c[3], 'taxable' => $c[4], 'sort_order' => $c[5],
                'is_active' => true, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now,
            ], [
                ['bonus', 'Bonus', 'earning', 'irregular', true, 10],
                ['incentive', 'Insentif', 'earning', 'variable', true, 20],
                ['commission', 'Komisi', 'earning', 'variable', true, 30],
                ['meal_allowance', 'Uang Makan', 'earning', 'variable', true, 40],
                ['transport_allowance', 'Uang Transport', 'earning', 'variable', true, 50],
                ['reimbursement', 'Reimbursement', 'earning', 'irregular', true, 60],
                ['correction_plus', 'Koreksi (tambah)', 'earning', 'irregular', true, 90],
                ['correction_minus', 'Koreksi (kurang)', 'deduction', 'irregular', true, 91],
                ['cooperative', 'Potongan Koperasi', 'deduction', 'variable', false, 100],
                ['other_deduction', 'Potongan Lain', 'deduction', 'variable', false, 110],
            ]));
        }

        if (! Schema::hasTable('payroll_inputs')) {
            Schema::create('payroll_inputs', function (Blueprint $t) {
                $t->id();
                $t->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $t->string('period', 7);
                $t->foreignId('payroll_component_id')->constrained('payroll_components');
                $t->bigInteger('amount');
                $t->string('notes', 255)->nullable();
                $t->string('status', 12)->default('pending'); // pending, approved, rejected
                $t->string('source', 12)->default('manual'); // manual, import
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('approved_at')->nullable();
                $t->string('rejection_note', 255)->nullable();
                $t->foreignId('payslip_id')->nullable()->constrained('payslips')->nullOnDelete();
                $t->timestamps();
                $t->index(['period', 'status']);
                $t->index(['employee_id', 'period']);
            });
        }

        if (! Schema::hasTable('loans')) {
            Schema::create('loans', function (Blueprint $t) {
                $t->id();
                $t->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $t->bigInteger('principal');
                $t->unsignedSmallInteger('tenor');
                $t->bigInteger('installment');
                $t->string('start_period', 7);
                $t->string('purpose', 255)->nullable();
                $t->string('status', 12)->default('pending'); // pending, active, paid_off, rejected
                $t->string('source', 12)->default('hr'); // hr, portal
                $t->text('notes')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('approved_at')->nullable();
                $t->string('rejection_note', 255)->nullable();
                $t->timestamps();
                $t->index(['employee_id', 'status']);
            });
        }

        if (! Schema::hasTable('loan_installments')) {
            Schema::create('loan_installments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
                $t->unsignedSmallInteger('sequence');
                $t->string('period', 7);
                $t->bigInteger('amount');
                $t->string('status', 12)->default('scheduled'); // scheduled, deducted, settled
                $t->foreignId('payslip_id')->nullable()->constrained('payslips')->nullOnDelete();
                $t->string('notes', 255)->nullable();
                $t->timestamps();
                $t->index(['period', 'status']);
            });
        }

        if (! Schema::hasColumn('payslip_lines', 'ref')) {
            Schema::table('payslip_lines', fn (Blueprint $t) => $t->string('ref', 40)->nullable());
        }
        if (! Schema::hasColumn('payroll_settings', 'loan_max_ratio')) {
            Schema::table('payroll_settings', fn (Blueprint $t) => $t->unsignedTinyInteger('loan_max_ratio')->default(50));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_installments');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('payroll_inputs');
        Schema::dropIfExists('payroll_components');
        if (Schema::hasColumn('payslip_lines', 'ref')) {
            Schema::table('payslip_lines', fn (Blueprint $t) => $t->dropColumn('ref'));
        }
        if (Schema::hasColumn('payroll_settings', 'loan_max_ratio')) {
            Schema::table('payroll_settings', fn (Blueprint $t) => $t->dropColumn('loan_max_ratio'));
        }
    }
};
