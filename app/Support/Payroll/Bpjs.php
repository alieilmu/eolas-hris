<?php

namespace App\Support\Payroll;

use App\Models\PayrollRate;

/**
 * BPJS contributions on the monthly wage (gaji pokok + tunjangan tetap). Kesehatan and JP
 * are counted on the wage up to their caps; JKK at the risk rate of the work location.
 * BPJS Kesehatan is counted on at least the UMK of the work location, and each extra family
 * member (beyond spouse and three children) adds the employee's rate again.
 */
class Bpjs
{
    /**
     * @return array{company: array{health: int, jkk: int, jkm: int, jht: int, jp: int}, employee: array{health: int, jht: int, jp: int}}
     */
    public static function calculate(int $wage, PayrollRate $r, float $jkkRate, bool $health = true, bool $employment = true, ?int $minimumWage = null, int $extraMembers = 0): array
    {
        $pct = fn (int $base, float $rate) => (int) round($base * $rate / 100);
        $healthBase = $health ? max(min($wage, $r->health_wage_cap), min($minimumWage ?? 0, $r->health_wage_cap)) : 0;
        $jpBase = $employment ? min($wage, $r->jp_wage_cap) : 0;
        $wage = $employment ? $wage : 0;

        return [
            'company' => [
                'health' => $pct($healthBase, $r->health_company_rate),
                'jkk' => $pct($wage, $jkkRate),
                'jkm' => $pct($wage, $r->jkm_rate),
                'jht' => $pct($wage, $r->jht_company_rate),
                'jp' => $pct($jpBase, $r->jp_company_rate),
            ],
            'employee' => [
                'health' => $pct($healthBase, $r->health_employee_rate) * (1 + max(0, $extraMembers)),
                'jht' => $pct($wage, $r->jht_employee_rate),
                'jp' => $pct($jpBase, $r->jp_employee_rate),
            ],
        ];
    }
}
