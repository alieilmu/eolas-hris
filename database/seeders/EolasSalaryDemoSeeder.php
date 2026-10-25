<?php

namespace Database\Seeders;

use App\Models\Grade;
use App\Models\Employee;
use App\Models\SalaryScale;
use App\Models\SalaryScalePeriod;
use App\Models\SalaryScaleAmount;
use App\Models\JobLevel;
use App\Models\JobLevelAllowance;
use App\Support\Payroll\Salary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo salaries: the Struktur Skala Upah completed (the STAFF golongan had no amounts, and
 * a general 2026 column at +5%), Tunjangan Jabatan per SubGroup, and every employee placed
 * on their golongan (a step of it for the NONOM scale), some raised a step this year.
 */
class EolasSalaryDemoSeeder extends Seeder
{
    /** 2025 gaji pokok of the STAFF golongan. */
    private const STAFF = [
        '3A' => 5800000, '3B' => 6200000, '3C' => 6700000, '3D' => 7200000, '3E' => 7800000,
        '4A' => 8600000, '4B' => 9500000, '4C' => 10500000, '4D' => 11600000, '4E' => 12800000,
        '5A' => 15000000, '5B' => 17500000, '5C' => 20500000, '5D' => 24000000, '5E' => 28000000,
    ];

    private const TUNJANGAN = [
        'BOD' => 25000000, 'General Manager' => 15000000, 'Manager' => 8000000, 'Project Manager' => 10000000,
        'Deputy Project Manager' => 7000000, 'Departement Head' => 6000000, 'Section Head HO' => 4000000,
        'Section Head Site' => 4500000, 'Officer HO/ Group Leader' => 2000000, 'Junior Staff' => 1000000,
        'FGDP' => 750000, 'Operator' => 500000, 'Mekanik' => 600000, 'Welder' => 600000, 'Nonom' => 300000,
    ];

    /** Golongan by SubGroup for an employee whose profile has none. */
    private const GOLONGAN_SUBGROUP = ['Operator' => 'D2J', 'Mekanik' => 'G2J', 'Welder' => 'W2J', 'Nonom' => '1C'];

    public function run(): void
    {
        mt_srand(2028);
        $k2025 = SalaryScalePeriod::where('year', 2025)->whereNull('work_location_id')->first();
        if ($k2025) {
            foreach (self::STAFF as $code => $amount) {
                $grade = Grade::where('code', $code)->first();
                foreach ($grade?->salaryScales ?? [] as $s) {
                    SalaryScaleAmount::firstOrCreate(['salary_scale_id' => $s->id, 'salary_scale_period_id' => $k2025->id], ['amount' => $amount]);
                }
            }
            $k2026 = SalaryScalePeriod::firstOrCreate(['year' => 2026, 'work_location_id' => null], ['sort_order' => (SalaryScalePeriod::max('sort_order') ?? 0) + 1]);
            foreach (SalaryScaleAmount::where('salary_scale_period_id', $k2025->id)->get() as $n) {
                SalaryScaleAmount::firstOrCreate(['salary_scale_id' => $n->salary_scale_id, 'salary_scale_period_id' => $k2026->id], ['amount' => (int) (round($n->amount * 1.05 / 1000) * 1000)]);
            }
        }

        foreach (JobLevel::all() as $g) {
            JobLevelAllowance::firstOrCreate(['job_level_id' => $g->id], ['amount' => self::TUNJANGAN[$g->name] ?? 0]);
        }

        $scale = SalaryScale::with('grade')->orderBy('sort_order')->orderBy('id')->get()->groupBy(fn (SalaryScale $s) => $s->grade->code);
        $today = CarbonImmutable::today();
        foreach (Employee::withoutGlobalScope('access')->with(['profile', 'jobLevel'])->doesntHave('salaries')->get() as $k) {
            $code = $k->profile?->grade ?: (self::GOLONGAN_SUBGROUP[$k->jobLevel?->name] ?? null);
            $lines = $code ? ($scale[$code] ?? collect()) : collect();
            if ($lines->isEmpty()) {
                continue;
            }
            $i = mt_rand(0, max(0, intdiv($lines->count() - 1, 2)));
            $hired = $k->profile?->hire_date ?? $today->subYears(2)->startOfYear();
            $k->salaries()->create(['salary_scale_id' => $lines[$i]->id, 'effective_date' => $hired->toDateString(), 'reason' => 'initial']);
            if (isset($lines[$i + 1]) && $hired->lt($today->startOfYear()) && mt_rand(1, 100) <= 40) {
                $k->salaries()->create(['salary_scale_id' => $lines[$i + 1]->id, 'effective_date' => $today->startOfYear()->toDateString(), 'reason' => 'step_increase', 'notes' => 'Kenaikan step tahunan']);
            }
            if (! $k->profile?->grade && $k->profile) {
                $k->profile->update(['grade' => $code]);
            }
        }
        Salary::flush();
    }
}
