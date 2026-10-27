<?php

namespace App\Support\Payroll;

use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\PayrollRate;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Models\Payslip;
use Carbon\CarbonImmutable;

/**
 * One employee's pay for one period, from what Eolas already holds: Gaji Pokok (Struktur
 * Skala Upah) and Tunjangan Jabatan, approved overtime, potong gapok (ALPA / Izin Potong
 * Gapok), BPJS and PPh21. Simulasi Gaji shows it; a payroll run stores it (PayrollRunner).
 *
 * Joining or leaving within the period: Gaji Pokok and Tunjangan Jabatan are paid for the
 * calendar days employed. The month an employee leaves, like December, works PPh21 out for
 * the year, on the slips of the earlier months as stored by approved payroll runs.
 *
 * Gross for PPh21 = the pay + the company's BPJS Kesehatan, JKK and JKM (+ the tax allowance
 * under gross-up). Take home pay = the pay − the employee's BPJS − PPh21 (unless it is net,
 * borne by the company).
 */
class PayrollCalculator
{
    private const PER_HOUR = 173;

    /** @param  int|null  $runId  the run being worked out: its own slip is not an earlier month */
    public static function month(Employee $k, PayrollPeriod $p, ?int $runId = null): array
    {
        $k->loadMissing(['profile.children', 'workLocation', 'jobLevel', 'position', 'salaries.salaryScale.grade', 'payrollProfile']);
        $settings = PayrollSetting::current();
        $rates = PayrollRate::forYear($p->year);
        $method = $k->payrollProfile?->tax_method ?? $settings->default_tax_method;
        $ptkp = Ptkp::status($k);
        $category = Ptkp::terCategory($ptkp);
        $hasTaxId = filled($k->profile?->tax_id_number) || filled($k->profile?->national_id_number);

        $base = Salary::base($k, $p->end);
        $result = [
            'employee' => [
                'id' => $k->id, 'employee_number' => $k->employee_number, 'name' => $k->name,
                'position' => $k->position?->name, 'job_level' => $k->jobLevel?->name, 'location' => $k->workLocation?->name,
            ],
            'period' => ['code' => $p->code(), 'label' => $p->label(), 'range' => $p->range()],
            'ptkp' => $ptkp, 'ter_category' => $category, 'has_tax_id' => $hasTaxId,
            'tax_method' => $method, 'mode' => 'ter',
            'warnings' => [],
            'prorate' => ['factor' => 1.0, 'days' => null, 'period_days' => null],
        ];
        if ($base === null) {
            return $result + ['error' => 'Gaji pokok belum ada: isi golongan & step di Data Gaji, dan nilai Struktur Skala Upah untuk lokasi/tahun ini.'];
        }

        $hired = $k->profile?->hire_date ? CarbonImmutable::parse($k->profile->hire_date) : null;
        $left = $k->termination_date ? CarbonImmutable::parse($k->termination_date) : null;
        $leaving = $left && $left->between($p->start, $p->end);
        $result['prorate'] = self::prorate($p, $hired, $left);
        if ($hired && $hired->between($p->start->addDay(), $p->end)) {
            $result['warnings'][] = 'Masuk '.$hired->format('d.m.Y').', di tengah periode: gaji pokok & tunjangan dibayar pro-rata '.$result['prorate']['days'].'/'.$result['prorate']['period_days'].' hari.';
        }
        if ($leaving) {
            $result['warnings'][] = 'Keluar '.$left->format('d.m.Y').': '.($left->lt($p->end) ? 'gaji pro-rata '.$result['prorate']['days'].'/'.$result['prorate']['period_days'].' hari, ' : '').'PPh21 dihitung setahun di bulan terakhir.';
        }
        $result['mode'] = $p->isLastOfYear() || $leaving ? 'annual' : 'ter';
        $line = self::lines($k, $p, $settings, $rates, $base, $result['prorate']);
        $taxOn = $result['mode'] === 'annual'
            ? self::annualTax($k, $p, $settings, $rates, $line, $ptkp, $hasTaxId, $method, $runId, $result)
            : fn (int $gross) => Pph21::monthly($category, $gross, $rates, $hasTaxId);

        $taxAllowance = $method === 'gross_up' ? Pph21::grossUp($line['gross'], $taxOn) : 0;
        $gross = $line['gross'] + $taxAllowance;
        $tax = $method === 'gross_up' ? $taxAllowance : $taxOn($gross);
        if ($method === 'gross_up' && $p->isLastOfYear()) {
            $taxOn($gross); // the steps shown are those of the final gross
        }
        $taxDeducted = $method === 'net' ? 0 : $tax;

        $earnings = $line['earnings'];
        if ($taxAllowance) {
            $earnings[] = ['code' => 'tax_allowance', 'label' => 'Tunjangan PPh21 (gross-up)', 'amount' => $taxAllowance, 'note' => null];
        }
        $extra = (int) ($k->payrollProfile?->health_extra_members ?? 0);
        $deductions = [
            ['code' => 'bpjs_health', 'label' => 'BPJS Kesehatan (karyawan)', 'amount' => $line['bpjs']['employee']['health'],
                'note' => self::pct($rates->health_employee_rate).($extra ? " + {$extra} anggota tambahan" : '')],
            ['code' => 'bpjs_jht', 'label' => 'BPJS JHT (karyawan)', 'amount' => $line['bpjs']['employee']['jht'], 'note' => self::pct($rates->jht_employee_rate)],
            ['code' => 'bpjs_jp', 'label' => 'BPJS JP (karyawan)', 'amount' => $line['bpjs']['employee']['jp'], 'note' => self::pct($rates->jp_employee_rate)],
        ];
        if ($taxDeducted) {
            $deductions[] = ['code' => 'pph21', 'label' => 'PPh21', 'amount' => $taxDeducted, 'note' => $method === 'gross_up' ? 'ditutup tunjangan PPh21' : null];
        }
        $paid = array_sum(array_column($earnings, 'amount'));
        $deducted = array_sum(array_column($deductions, 'amount'));

        if ($result['mode'] === 'ter') {
            $result['tax_steps'] = [
                ['label' => 'Penghasilan bruto', 'amount' => $gross],
                ['label' => 'Tarif TER kategori '.$category.' ('.$ptkp.')', 'amount' => null, 'note' => self::pct(Pph21::terRate($category, $gross))],
                ['label' => 'PPh21 bulan ini'.($hasTaxId ? '' : ' (+'.self::pct($rates->no_tax_id_surcharge).', tanpa NPWP/NIK)'), 'amount' => $tax],
            ];
        }

        return $result + [
            'earnings' => $earnings,
            'deductions' => $deductions,
            'company' => [
                ['code' => 'bpjs_health_company', 'label' => 'BPJS Kesehatan (perusahaan)', 'amount' => $line['bpjs']['company']['health'], 'note' => self::pct($rates->health_company_rate)],
                ['code' => 'bpjs_jkk', 'label' => 'BPJS JKK', 'amount' => $line['bpjs']['company']['jkk'], 'note' => self::pct($line['jkk_rate'])],
                ['code' => 'bpjs_jkm', 'label' => 'BPJS JKM', 'amount' => $line['bpjs']['company']['jkm'], 'note' => self::pct($rates->jkm_rate)],
                ['code' => 'bpjs_jht_company', 'label' => 'BPJS JHT (perusahaan)', 'amount' => $line['bpjs']['company']['jht'], 'note' => self::pct($rates->jht_company_rate)],
                ['code' => 'bpjs_jp_company', 'label' => 'BPJS JP (perusahaan)', 'amount' => $line['bpjs']['company']['jp'], 'note' => self::pct($rates->jp_company_rate)],
                ...($method === 'net' ? [['code' => 'pph21_company', 'label' => 'PPh21 ditanggung perusahaan', 'amount' => $tax, 'note' => 'metode net']] : []),
            ],
            'absence' => $line['absence'],
            'overtime' => $line['overtime'],
            'wage' => $line['wage'],
            'gross' => $gross,
            'tax' => $tax,
            'tax_withheld' => $taxDeducted,
            'employee_contributions' => $line['bpjs']['employee']['jht'] + $line['bpjs']['employee']['jp'],
            'total_earnings' => $paid,
            'total_deductions' => $deducted,
            'take_home_pay' => $paid - $deducted,
        ];
    }

    /**
     * The month's pay before tax: earnings, BPJS and the gross for PPh21.
     *
     * @return array{earnings: list<array>, bpjs: array, gross: int, wage: int, jkk_rate: float, absence: array, overtime: array}
     */
    private static function lines(Employee $k, PayrollPeriod $p, PayrollSetting $s, PayrollRate $r, int $base, ?array $prorate = null): array
    {
        $allowance = Salary::positionAllowance($k);
        $wage = $base + $allowance;
        $factor = $prorate['factor'] ?? 1.0;
        $absence = AbsenceDeduction::for($k, $p, $s, $base);
        $overtime = OvertimeRecord::where('employee_id', $k->id)->where('status', 'approved')
            ->whereBetween('date', [$p->start->toDateString(), $p->end->toDateString()])->get();
        $weighted = round($overtime->sum('weighted_hours'), 2);
        $overtimePay = (int) round($weighted * $wage / self::PER_HOUR);
        $jkkRate = $k->workLocation?->jkk_rate ?? 0.24;
        $profile = $k->payrollProfile;
        $bpjs = Bpjs::calculate($wage, $r, $jkkRate, $profile?->bpjs_health ?? true, $profile?->bpjs_employment ?? true,
            $k->workLocation?->minimum_wage, (int) ($profile?->health_extra_members ?? 0));

        $part = $factor < 1 ? "pro-rata {$prorate['days']}/{$prorate['period_days']} hari" : null;
        $earnings = [
            ['code' => 'base_salary', 'label' => 'Gaji Pokok', 'amount' => (int) round($base * $factor), 'note' => $part],
            ['code' => 'position_allowance', 'label' => 'Tunjangan Jabatan', 'amount' => (int) round($allowance * $factor), 'note' => trim(($k->jobLevel?->name ?? '').($part ? " · {$part}" : ''), ' ·') ?: null],
        ];
        if ($absence['amount']) {
            $earnings[] = ['code' => 'absence_deduction', 'label' => 'Potong Gapok', 'amount' => -$absence['amount'],
                'note' => "{$absence['days']} hari × gapok ÷ {$absence['divisor']}"];
        }
        if ($overtimePay) {
            $earnings[] = ['code' => 'overtime', 'label' => 'Lembur', 'amount' => $overtimePay,
                'note' => str_replace('.', ',', (string) $weighted).' jam tertimbang × upah ÷ '.self::PER_HOUR];
        }
        $pay = array_sum(array_column($earnings, 'amount'));

        return [
            'earnings' => $earnings,
            'bpjs' => $bpjs,
            'gross' => $pay + $bpjs['company']['health'] + $bpjs['company']['jkk'] + $bpjs['company']['jkm'],
            'wage' => $wage,
            'jkk_rate' => $jkkRate,
            'absence' => $absence,
            'overtime' => ['records' => $overtime->count(), 'weighted_hours' => $weighted, 'amount' => $overtimePay],
        ];
    }

    /** Days employed within the period, of its calendar days (joining or leaving within it). */
    private static function prorate(PayrollPeriod $p, ?CarbonImmutable $hired, ?CarbonImmutable $left): array
    {
        $total = (int) $p->start->diffInDays($p->end) + 1;
        $from = $hired && $hired->gt($p->start) ? $hired : $p->start;
        $to = $left && $left->lt($p->end) ? $left : $p->end;
        $days = $to->lt($from) ? 0 : (int) $from->diffInDays($to) + 1;

        return ['factor' => $days >= $total ? 1.0 : round($days / $total, 4), 'days' => $days, 'period_days' => $total];
    }

    /**
     * December, or the month an employee leaves: the tax of the year less what the earlier
     * months withheld. An earlier month comes from its stored slip (an approved, paid or
     * locked payroll run); a month without one is worked out again from the same data.
     *
     * @return callable(int): int  this month's tax for this month's gross
     */
    private static function annualTax(Employee $k, PayrollPeriod $p, PayrollSetting $s, PayrollRate $r, array $line, string $ptkp, bool $hasTaxId, string $method, ?int $runId, array &$result): callable
    {
        $hired = $k->profile?->hire_date ? CarbonImmutable::parse($k->profile->hire_date) : null;
        $category = Ptkp::terCategory($ptkp);
        $stored = Payslip::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->where('payslips.employee_id', $k->id)->whereNull('payslips.error')
            ->whereIn('payroll_runs.status', PayrollRun::FINAL)
            ->where('payroll_runs.period', '>=', sprintf('%04d-01', $p->year))->where('payroll_runs.period', '<', $p->code())
            ->when($runId, fn ($q) => $q->where('payroll_runs.id', '!=', $runId))
            ->get(['payroll_runs.period', 'payslips.gross', 'payslips.tax', 'payslips.tax_withheld', 'payslips.employee_contributions', 'payslips.snapshot'])
            ->groupBy('period');
        [$grossBefore, $withheld, $contributions, $months, $redone] = [0, 0, 0, 0, 0];
        for ($m = 1; $m < $p->month; $m++) {
            $code = sprintf('%04d-%02d', $p->year, $m);
            if ($stored->has($code)) {
                foreach ($stored[$code] as $slip) {
                    $grossBefore += $slip->gross;
                    // Under net the company bears the tax: it still counts as paid for the year.
                    $withheld += ($slip->snapshot['tax_method'] ?? null) === 'net' ? $slip->tax : $slip->tax_withheld;
                    $contributions += $slip->employee_contributions;
                }
                $months++;

                continue;
            }
            $earlier = PayrollPeriod::of($p->year, $m, $s->cutoff_day);
            $base = Salary::base($k, $earlier->end);
            if ($base === null || ($hired && $hired->gt($earlier->end))) {
                continue;
            }
            $l = self::lines($k, $earlier, $s, PayrollRate::forYear($p->year), $base, self::prorate($earlier, $hired, null));
            $taxOn = fn (int $gross) => Pph21::monthly($category, $gross, $r, $hasTaxId);
            $allowance = $method === 'gross_up' ? Pph21::grossUp($l['gross'], $taxOn) : 0;
            $grossBefore += $l['gross'] + $allowance;
            $withheld += $method === 'gross_up' ? $allowance : $taxOn($l['gross']);
            $contributions += $l['bpjs']['employee']['jht'] + $l['bpjs']['employee']['jp'];
            $months++;
            $redone++;
        }
        $contributions += $line['bpjs']['employee']['jht'] + $line['bpjs']['employee']['jp'];
        $months++;
        $label = $p->isLastOfYear() ? 'Desember' : 'Bulan terakhir';
        $result['warnings'][] = $redone
            ? "{$label}: PPh21 dihitung setahun. {$redone} bulan sebelumnya belum punya slip tersimpan (payroll run disetujui), jadi dihitung ulang dari data yang ada."
            : "{$label}: PPh21 dihitung setahun dari slip tersimpan bulan-bulan sebelumnya.";

        return function (int $gross) use ($grossBefore, $contributions, $months, $ptkp, $r, $withheld, $hasTaxId, $p, &$result) {
            $a = Pph21::annual($grossBefore + $gross, $contributions, $months, $ptkp, $r, $withheld, $hasTaxId);
            $before = $p->month > 1 ? 'Januari–'.self::monthName($p->month - 1) : 'bulan sebelumnya';
            $result['tax_steps'] = [
                ['label' => "Bruto setahun ({$months} bulan)", 'amount' => $a['gross']],
                ['label' => 'Biaya jabatan', 'amount' => -$a['position_cost']],
                ['label' => 'Iuran JHT + JP karyawan', 'amount' => -$a['contributions']],
                ['label' => 'Penghasilan neto', 'amount' => $a['net']],
                ['label' => "PTKP ({$ptkp})", 'amount' => -$a['ptkp']],
                ['label' => 'PKP (dibulatkan ke ribuan)', 'amount' => $a['pkp']],
                ['label' => 'PPh21 setahun (Pasal 17)', 'amount' => $a['tax_year']],
                ['label' => "Sudah dipotong {$before}", 'amount' => -$a['withheld']],
                ['label' => 'PPh21 '.self::monthName($p->month), 'amount' => $a['tax']],
            ];

            return $a['tax'];
        };
    }

    private static function monthName(int $m): string
    {
        return ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][$m - 1];
    }

    private static function pct(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, ',', '.'), '0'), ',').'%';
    }
}
