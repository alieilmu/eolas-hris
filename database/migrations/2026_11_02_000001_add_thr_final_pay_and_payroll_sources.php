<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll phase 5:
 *  - payroll_runs.holiday / holiday_date: a THR run is for one religious holiday (or everyone);
 *  - payroll_runs.employee_ids: a final pay run is for the employees who leave;
 *  - payroll_inputs.source_ref: an input pulled from another module (deklarasi, Cuti Besar,
 *    lumpsum cuti lapangan), pulled once;
 *  - two components for those pulled amounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $t) {
            if (! Schema::hasColumn('payroll_runs', 'holiday')) {
                $t->string('holiday', 20)->nullable();
                $t->date('holiday_date')->nullable();
                $t->json('employee_ids')->nullable();
            }
        });
        if (! Schema::hasColumn('payroll_inputs', 'source_ref')) {
            Schema::table('payroll_inputs', function (Blueprint $t) {
                $t->string('source_ref', 40)->nullable()->unique();
            });
        }
        $now = now();
        foreach ([
            ['long_service_pay', 'Uang Cuti Besar', 70],
            ['field_leave_allowance', 'Lumpsum Cuti Lapangan', 75],
        ] as [$code, $name, $sort]) {
            if (! DB::table('payroll_components')->where('code', $code)->exists()) {
                DB::table('payroll_components')->insert(['code' => $code, 'name' => $name, 'kind' => 'earning', 'nature' => 'irregular', 'taxable' => true,
                    'sort_order' => $sort, 'is_active' => true, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $t) {
            if (Schema::hasColumn('payroll_runs', 'holiday')) {
                $t->dropColumn(['holiday', 'holiday_date', 'employee_ids']);
            }
        });
        if (Schema::hasColumn('payroll_inputs', 'source_ref')) {
            Schema::table('payroll_inputs', function (Blueprint $t) {
                $t->dropUnique(['source_ref']);
                $t->dropColumn('source_ref');
            });
        }
        DB::table('payroll_components')->whereIn('code', ['long_service_pay', 'field_leave_allowance'])->delete();
    }
};
