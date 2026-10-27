<?php

namespace App\Support\Payroll;

use App\Models\Employee;
use App\Models\PayrollRate;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Models\Payslip;
use Carbon\CarbonImmutable;

/**
 * THR keagamaan (Permenaker 6/2016): one month's wage (gaji pokok + tunjangan tetap) after
 * 12 months of service, months / 12 of it before that (from one full month). PPh21 is the TER
 * of the month it is paid in, on that month's pay and the THR together, less what the pay
 * alone bears. No BPJS on THR.
 */
class ThrCalculator
{
    public static function for(Employee $k, PayrollRun $run): array
    {
        $k->loadMissing(['profile.children', 'workLocation', 'jobLevel', 'position', 'salaries.salaryScale.grade', 'payrollProfile']);
        $p = $run->periodOf();
        $day = CarbonImmutable::parse($run->holiday_date ?? $p->end);
        $settings = PayrollSetting::current();
        $rates = PayrollRate::forYear($p->year);
        $method = $k->payrollProfile?->tax_method ?? $settings->default_tax_method;
        $ptkp = Ptkp::status($k);
        $category = Ptkp::terCategory($ptkp);
        $hasTaxId = filled($k->profile?->tax_id_number) || filled($k->profile?->national_id_number);
        $result = [
            'employee' => ['id' => $k->id, 'employee_number' => $k->employee_number, 'name' => $k->name, 'position' => $k->position?->name,
                'job_level' => $k->jobLevel?->name, 'location' => $k->workLocation?->name],
            'period' => ['code' => $p->code(), 'label' => 'THR '.(PayrollRun::HOLIDAYS[$run->holiday][0] ?? '').' '.$day->year, 'range' => 'Hari raya '.$day->format('d.m.Y')],
            'ptkp' => $ptkp, 'ter_category' => $category, 'has_tax_id' => $hasTaxId, 'tax_method' => $method, 'mode' => 'ter', 'warnings' => [],
            'prorate' => ['factor' => 1.0, 'days' => null, 'period_days' => null],
        ];
        $wage = Salary::fixedPay($k, $day);
        if ($wage === null) {
            return $result + ['error' => 'Gaji pokok belum ada: isi golongan & step di Data Gaji.'];
        }
        $hired = $k->profile?->hire_date ? CarbonImmutable::parse($k->profile->hire_date) : null;
        $months = $hired ? (int) floor($hired->diffInMonths($day)) : 12;
        $thr = $months >= 12 ? $wage : (int) round($wage * $months / 12);
        $service = $months >= 12 ? 'masa kerja ≥ 12 bulan: 1 × upah' : "masa kerja {$months} bulan: {$months}/12 × upah";

        // The month's pay this THR is taxed with: its stored slip, else worked out now.
        $monthGross = (int) (Payslip::where('employee_id', $k->id)->whereNull('error')
            ->whereHas('run', fn ($q) => $q->where('type', 'regular')->where('period', $p->code())->where('status', '!=', 'draft'))
            ->value('gross') ?? (PayrollCalculator::month($k, $p)['gross'] ?? 0));
        $taxOn = fn (int $extra) => max(0, Pph21::monthly($category, $monthGross + $extra, $rates, $hasTaxId) - Pph21::monthly($category, $monthGross, $rates, $hasTaxId));
        $allowance = $method === 'gross_up' ? Pph21::grossUp($thr, $taxOn) : 0;
        $gross = $thr + $allowance;
        $tax = $method === 'gross_up' ? $allowance : $taxOn($gross);
        $withheld = $method === 'net' ? 0 : $tax;

        $earnings = [['code' => 'thr', 'label' => 'THR', 'amount' => $thr, 'note' => $service]];
        if ($allowance) {
            $earnings[] = ['code' => 'tax_allowance', 'label' => 'Tunjangan PPh21 (gross-up)', 'amount' => $allowance, 'note' => null];
        }
        $deductions = $withheld ? [['code' => 'pph21', 'label' => 'PPh21 atas THR', 'amount' => $withheld, 'note' => $method === 'gross_up' ? 'ditutup tunjangan PPh21' : null]] : [];
        $company = $method === 'net' ? [['code' => 'pph21_company', 'label' => 'PPh21 ditanggung perusahaan', 'amount' => $tax, 'note' => 'metode net']] : [];
        $paid = array_sum(array_column($earnings, 'amount'));
        $deducted = array_sum(array_column($deductions, 'amount'));

        return $result + [
            'earnings' => $earnings, 'deductions' => $deductions, 'company' => $company,
            'tax_steps' => [
                ['label' => 'Bruto gaji '.$p->label(), 'amount' => $monthGross],
                ['label' => 'THR'.($allowance ? ' + tunjangan PPh21' : ''), 'amount' => $gross],
                ['label' => "TER {$category} atas gaji + THR", 'amount' => Pph21::monthly($category, $monthGross + $gross, $rates, $hasTaxId), 'note' => rtrim(rtrim(number_format(Pph21::terRate($category, $monthGross + $gross), 2, ',', '.'), '0'), ',').'%'],
                ['label' => 'Dikurangi PPh21 atas gaji saja', 'amount' => -Pph21::monthly($category, $monthGross, $rates, $hasTaxId)],
                ['label' => 'PPh21 atas THR', 'amount' => $tax],
            ],
            'absence' => null, 'overtime' => null,
            'wage' => $wage, 'gross' => $gross, 'tax' => $tax, 'tax_withheld' => $withheld, 'employee_contributions' => 0,
            'total_earnings' => $paid, 'total_deductions' => $deducted, 'take_home_pay' => $paid - $deducted,
        ];
    }
}
