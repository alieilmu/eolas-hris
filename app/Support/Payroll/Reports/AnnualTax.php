<?php

namespace App\Support\Payroll\Reports;

use App\Models\PayrollRate;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Support\Payroll\Pph21;
use Illuminate\Support\Collection;

/**
 * A year of PPh 21 per employee, from the slips of approved runs (monthly, THR, final pay),
 * laid out as the 1721-A1: income lines 1–7, the deductions, the tax of the year and what
 * was withheld. Complete once December (or the final pay) is approved.
 */
class AnnualTax
{
    /** @return Collection<int, array> per employee id */
    public static function year(int $year, ?array $employeeIds = null): Collection
    {
        $slips = Payslip::with('lines', 'run')->whereNull('error')
            ->whereHas('run', fn ($q) => $q->whereIn('status', PayrollRun::FINAL)->where('period', 'like', "{$year}-%"))
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds ?: [0]))
            ->get()->groupBy('employee_id');
        $rates = PayrollRate::forYear($year);

        return $slips->map(fn (Collection $rows) => self::summarise($rows, $year, $rates));
    }

    private static function summarise(Collection $rows, int $year, PayrollRate $r): array
    {
        $rows = $rows->sortBy(fn (Payslip $s) => $s->run->period.$s->run->id)->values();
        $last = $rows->last();
        $sum = fn (array $codes, string $kind = 'earning') => (int) $rows->sum(fn (Payslip $s) => $s->lines->where('kind', $kind)->whereIn('code', $codes)->sum('amount'));
        $monthly = $rows->filter(fn (Payslip $s) => in_array($s->run->type, ['regular', 'final'], true));
        $months = $monthly->map(fn ($s) => $s->run->period)->unique()->sort()->values();

        $gross = (int) $rows->sum('gross');
        $salary = $sum(['base_salary', 'absence_deduction']);
        $taxAllowance = $sum(['tax_allowance']);
        $insurance = $sum(['bpjs_health_company', 'bpjs_jkk', 'bpjs_jkm'], 'company');
        $bonus = $sum(['bonus', 'thr', 'incentive', 'commission']);
        // Line 3 takes every other taxable earning (allowances, overtime, reimbursement …).
        $other = $gross - $salary - $taxAllowance - $insurance - $bonus;
        $contributions = (int) $rows->sum('employee_contributions');
        $withheld = (int) $rows->sum(fn (Payslip $s) => ($s->snapshot['tax_method'] ?? null) === 'net' ? $s->tax : $s->tax_withheld);
        $ptkp = $last->snapshot['ptkp'] ?? 'TK/0';
        $hasTaxId = (bool) ($last->snapshot['has_tax_id'] ?? true);
        $a = Pph21::annual($gross, $contributions, max(1, $months->count()), $ptkp, $r, $withheld, $hasTaxId);
        $complete = $rows->contains(fn (Payslip $s) => ($s->run->type === 'regular' && $s->run->period === "{$year}-12") || $s->run->type === 'final');
        // Until December (or the final pay) the year's tax is not worked out yet: what was withheld stands.
        $taxYear = $complete ? $a['tax_year'] : $withheld;

        return [
            'employee_id' => $last->employee_id, 'employee_number' => $last->employee_number, 'name' => $last->name,
            'tax_id' => $last->snapshot['tax_id_number'] ?? null, 'national_id' => $last->snapshot['national_id_number'] ?? null,
            'position' => $last->snapshot['position'] ?? null, 'location' => $last->snapshot['location'] ?? null,
            'ptkp' => $ptkp, 'tax_method' => $last->snapshot['tax_method'] ?? null,
            'month_start' => (int) substr((string) ($months->first() ?? "{$year}-01"), 5, 2),
            'month_end' => (int) substr((string) ($months->last() ?? "{$year}-12"), 5, 2),
            'months' => $months->count(), 'complete' => $complete,
            'lines' => [
                1 => $salary, 2 => $taxAllowance, 3 => $other, 4 => 0, 5 => $insurance, 6 => 0, 7 => $bonus,
                8 => $gross, 9 => $a['position_cost'], 10 => $contributions, 11 => $a['position_cost'] + $contributions,
                12 => $a['net'], 13 => 0, 14 => $a['net'], 15 => $a['ptkp'], 16 => $a['pkp'], 17 => $taxYear,
                18 => 0, 19 => $taxYear, 20 => $withheld,
            ],
            'difference' => $taxYear - $withheld,
        ];
    }

    /** The 1721-A1 lines' labels. */
    public const LABELS = [
        1 => 'Gaji / pensiun atau THT / JHT',
        2 => 'Tunjangan PPh',
        3 => 'Tunjangan lainnya, uang lembur dan sebagainya',
        4 => 'Honorarium dan imbalan lain sejenisnya',
        5 => 'Premi asuransi yang dibayar pemberi kerja',
        6 => 'Penerimaan dalam bentuk natura dan kenikmatan lainnya',
        7 => 'Tantiem, bonus, gratifikasi, jasa produksi dan THR',
        8 => 'Jumlah penghasilan bruto (1 s.d. 7)',
        9 => 'Biaya jabatan / biaya pensiun',
        10 => 'Iuran terkait pensiun atau hari tua (JHT, JP)',
        11 => 'Jumlah pengurangan (9 s.d. 10)',
        12 => 'Jumlah penghasilan neto (8 − 11)',
        13 => 'Penghasilan neto masa sebelumnya',
        14 => 'Jumlah penghasilan neto setahun / disetahunkan',
        15 => 'Penghasilan tidak kena pajak (PTKP)',
        16 => 'Penghasilan kena pajak setahun / disetahunkan',
        17 => 'PPh 21 atas penghasilan kena pajak setahun',
        18 => 'PPh 21 yang telah dipotong masa sebelumnya',
        19 => 'PPh 21 terutang',
        20 => 'PPh 21 yang telah dipotong / dilunasi',
    ];
}
