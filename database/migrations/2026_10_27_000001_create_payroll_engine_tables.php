<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll phase 2 (docs/payroll-plan.md): what the calculation engine reads.
 *  - shift_codes.deducts_base_salary: days with this code cut the gaji pokok (ALPA, Izin Potong Gapok);
 *  - payroll_settings: cutoff, default tax method, how a day's deduction is divided;
 *  - payroll_rates: BPJS, biaya jabatan and PTKP per year; ter_rates: PP 58/2023 monthly TER;
 *  - work_locations.jkk_rate: JKK risk rate per location;
 *  - employee_payroll_profiles: per-employee tax method and BPJS participation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_codes', function (Blueprint $t) {
            $t->boolean('deducts_base_salary')->default(false)->after('type');
        });
        DB::table('shift_codes')->where('type', 'absent')->update(['deducts_base_salary' => true]);
        if (! DB::table('shift_codes')->where('code', 'IP')->exists()) {
            DB::table('shift_codes')->insert([
                'code' => 'IP', 'daily_work_schedule' => 'IZINPG', 'description' => 'Izin potong gapok', 'type' => 'permission',
                'deducts_base_salary' => true, 'sort_order' => (int) DB::table('shift_codes')->max('sort_order') + 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::create('payroll_settings', function (Blueprint $t) {
            $t->id();
            // Last day of a period: 20 = the 21st of the month before to the 20th; 31 = calendar month.
            $t->unsignedTinyInteger('cutoff_day')->default(31);
            $t->string('default_tax_method', 10)->default('gross'); // gross | gross_up | net
            // A deducted day = gaji pokok / working days in the roster of the period, or / a fixed number.
            $t->string('absence_divisor_mode', 10)->default('schedule'); // schedule | fixed
            $t->unsignedTinyInteger('absence_divisor_days')->default(21);
            $t->timestamps();
        });
        DB::table('payroll_settings')->insert(['created_at' => now(), 'updated_at' => now()]);

        Schema::create('payroll_rates', function (Blueprint $t) {
            $t->id();
            $t->unsignedSmallInteger('year')->unique();
            $t->decimal('health_company_rate', 5, 2);
            $t->decimal('health_employee_rate', 5, 2);
            $t->unsignedBigInteger('health_wage_cap');
            $t->decimal('jht_company_rate', 5, 2);
            $t->decimal('jht_employee_rate', 5, 2);
            $t->decimal('jp_company_rate', 5, 2);
            $t->decimal('jp_employee_rate', 5, 2);
            $t->unsignedBigInteger('jp_wage_cap');
            $t->decimal('jkm_rate', 5, 2);
            $t->decimal('position_cost_rate', 5, 2);
            $t->unsignedBigInteger('position_cost_monthly_cap');
            $t->unsignedBigInteger('ptkp_base');
            $t->unsignedBigInteger('ptkp_married');
            $t->unsignedBigInteger('ptkp_dependant');
            $t->decimal('no_tax_id_surcharge', 5, 2);
            $t->timestamps();
        });
        DB::table('payroll_rates')->insert([
            'year' => 2026,
            'health_company_rate' => 4, 'health_employee_rate' => 1, 'health_wage_cap' => 12_000_000,
            'jht_company_rate' => 3.7, 'jht_employee_rate' => 2,
            'jp_company_rate' => 2, 'jp_employee_rate' => 1, 'jp_wage_cap' => 10_547_400,
            'jkm_rate' => 0.3,
            'position_cost_rate' => 5, 'position_cost_monthly_cap' => 500_000,
            'ptkp_base' => 54_000_000, 'ptkp_married' => 4_500_000, 'ptkp_dependant' => 4_500_000,
            'no_tax_id_surcharge' => 20,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::create('ter_rates', function (Blueprint $t) {
            $t->id();
            $t->char('category', 1); // A | B | C
            $t->unsignedBigInteger('lower_bound');
            $t->unsignedBigInteger('upper_bound')->nullable(); // null = and above
            $t->decimal('rate', 5, 2);
            $t->index(['category', 'lower_bound']);
        });
        $rows = [];
        foreach (self::TER as $category => $brackets) {
            $lower = 0;
            foreach ($brackets as [$upper, $rate]) {
                $rows[] = ['category' => $category, 'lower_bound' => $lower, 'upper_bound' => $upper, 'rate' => $rate];
                $lower = $upper === null ? null : $upper + 1;
            }
        }
        DB::table('ter_rates')->insert($rows);

        Schema::table('work_locations', function (Blueprint $t) {
            $t->decimal('jkk_rate', 4, 2)->default(0.24)->after('daily_normal_hours');
        });
        // Mining sites: risk class V (PP 44/2015); offices class I.
        DB::table('work_locations')->where('type', 'site')->update(['jkk_rate' => 1.74]);

        Schema::create('employee_payroll_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('tax_method', 10)->nullable(); // null = payroll_settings.default_tax_method
            $t->boolean('bpjs_health')->default(true);
            $t->boolean('bpjs_employment')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_payroll_profiles');
        Schema::table('work_locations', fn (Blueprint $t) => $t->dropColumn('jkk_rate'));
        Schema::dropIfExists('ter_rates');
        Schema::dropIfExists('payroll_rates');
        Schema::dropIfExists('payroll_settings');
        DB::table('shift_codes')->where('code', 'IP')->where('daily_work_schedule', 'IZINPG')
            ->whereNotExists(fn ($q) => $q->from('attendance_records')->whereColumn('attendance_records.shift_code_id', 'shift_codes.id'))
            ->whereNotExists(fn ($q) => $q->from('shift_schedules')->whereColumn('shift_schedules.shift_code_id', 'shift_codes.id'))
            ->delete();
        Schema::table('shift_codes', fn (Blueprint $t) => $t->dropColumn('deducts_base_salary'));
    }

    /** Monthly TER, PP 58/2023 attachment: [upper bound of the gross, rate %]; null = and above. */
    private const TER = [
        'A' => [
            [5_400_000, 0], [5_650_000, 0.25], [5_950_000, 0.5], [6_300_000, 0.75], [6_750_000, 1], [7_500_000, 1.25], [8_550_000, 1.5],
            [9_650_000, 1.75], [10_050_000, 2], [10_350_000, 2.25], [10_700_000, 2.5], [11_050_000, 3], [11_600_000, 3.5], [12_500_000, 4],
            [13_750_000, 5], [15_100_000, 6], [16_950_000, 7], [19_750_000, 8], [24_150_000, 9], [26_450_000, 10], [28_000_000, 11],
            [30_050_000, 12], [32_400_000, 13], [35_400_000, 14], [39_100_000, 15], [43_850_000, 16], [47_800_000, 17], [51_400_000, 18],
            [56_300_000, 19], [62_200_000, 20], [68_600_000, 21], [77_500_000, 22], [89_000_000, 23], [103_000_000, 24], [125_000_000, 25],
            [157_000_000, 26], [206_000_000, 27], [337_000_000, 28], [454_000_000, 29], [550_000_000, 30], [695_000_000, 31],
            [910_000_000, 32], [1_400_000_000, 33], [null, 34],
        ],
        'B' => [
            [6_200_000, 0], [6_500_000, 0.25], [6_850_000, 0.5], [7_300_000, 0.75], [9_200_000, 1], [10_750_000, 1.5], [11_250_000, 2],
            [11_600_000, 2.5], [12_600_000, 3], [13_600_000, 4], [14_950_000, 5], [16_400_000, 6], [18_450_000, 7], [21_850_000, 8],
            [26_000_000, 9], [27_700_000, 10], [29_350_000, 11], [31_450_000, 12], [33_950_000, 13], [37_100_000, 14], [41_100_000, 15],
            [45_800_000, 16], [49_500_000, 17], [53_800_000, 18], [58_500_000, 19], [64_000_000, 20], [71_000_000, 21], [80_000_000, 22],
            [93_000_000, 23], [109_000_000, 24], [129_000_000, 25], [163_000_000, 26], [211_000_000, 27], [374_000_000, 28],
            [459_000_000, 29], [555_000_000, 30], [704_000_000, 31], [957_000_000, 32], [1_405_000_000, 33], [null, 34],
        ],
        'C' => [
            [6_600_000, 0], [6_950_000, 0.25], [7_350_000, 0.5], [7_800_000, 0.75], [8_850_000, 1], [9_800_000, 1.25], [10_950_000, 1.5],
            [11_200_000, 1.75], [12_050_000, 2], [12_950_000, 3], [14_150_000, 4], [15_550_000, 5], [17_050_000, 6], [19_500_000, 7],
            [22_700_000, 8], [26_600_000, 9], [28_100_000, 10], [30_100_000, 11], [32_600_000, 12], [35_400_000, 13], [38_900_000, 14],
            [43_000_000, 15], [47_400_000, 16], [51_200_000, 17], [55_800_000, 18], [60_400_000, 19], [66_700_000, 20], [74_500_000, 21],
            [83_200_000, 22], [95_600_000, 23], [110_000_000, 24], [134_000_000, 25], [169_000_000, 26], [221_000_000, 27],
            [390_000_000, 28], [463_000_000, 29], [561_000_000, 30], [709_000_000, 31], [965_000_000, 32], [1_419_000_000, 33], [null, 34],
        ],
    ];
};
