<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** BPJS, biaya jabatan and PTKP figures of one year (percentages are in %, e.g. 4 = 4%). */
#[Fillable([
    'year', 'health_company_rate', 'health_employee_rate', 'health_wage_cap', 'jht_company_rate', 'jht_employee_rate',
    'jp_company_rate', 'jp_employee_rate', 'jp_wage_cap', 'jkm_rate', 'position_cost_rate', 'position_cost_monthly_cap',
    'ptkp_base', 'ptkp_married', 'ptkp_dependant', 'no_tax_id_surcharge',
])]
class PayrollRate extends Model
{
    public const RATES = ['health_company_rate', 'health_employee_rate', 'jht_company_rate', 'jht_employee_rate', 'jp_company_rate', 'jp_employee_rate', 'jkm_rate', 'position_cost_rate', 'no_tax_id_surcharge'];

    public const AMOUNTS = ['health_wage_cap', 'jp_wage_cap', 'position_cost_monthly_cap', 'ptkp_base', 'ptkp_married', 'ptkp_dependant'];

    protected function casts(): array
    {
        return array_fill_keys(self::RATES, 'float') + array_fill_keys(self::AMOUNTS, 'integer') + ['year' => 'integer'];
    }

    /** The figures in force in $year: that year's row, else the latest earlier one, else the earliest. */
    public static function forYear(int $year): self
    {
        return self::where('year', '<=', $year)->orderByDesc('year')->first()
            ?? self::orderBy('year')->first()
            ?? throw new \RuntimeException('Tarif payroll belum diisi.');
    }
}
