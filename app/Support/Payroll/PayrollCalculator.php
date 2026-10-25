<?php

namespace App\Support\Payroll;

use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Models\PayrollRate;
use App\Models\PayrollSetting;
use Carbon\CarbonImmutable;

/**
 * One employee's pay for one period, from what Eolas already holds: Gaji Pokok (Struktur
 * Skala Upah) and Tunjangan Jabatan, approved overtime, potong gapok (ALPA / Izin Potong
 * Gapok), BPJS and PPh21. Nothing is stored: payroll runs (phase 3) will snapshot this.
 *
 * Gross for PPh21 = the pay + the company's BPJS Kesehatan, JKK and JKM (+ the tax allowance
 * under gross-up). Take home pay = the pay − the employee's BPJS − PPh21 (unless it is net,
 * borne by the company).
 */
class PayrollCalculator
{
    private const PER_HOUR = 173;

    public static function month(Employee $k, PayrollPeriod $p): array
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
            'tax_method' => $method, 'mode' => $p->isLastOfYear() ? 'annual' : 'ter',
            'warnings' => [],
        ];
        if ($base === null) {
            return $result + ['error' => 'Gaji pokok belum ada: isi golongan & step di Data Gaji, dan nilai Struktur Skala Upah untuk lokasi/tahun ini.'];
        }

        $hired = $k->profile?->hire_date ? CarbonImmutable::parse($k->profile->hire_date) : null;
        if ($hired && $hired->between($p->start->addDay(), $p->end)) {
            $result['warnings'][] = 'Masuk '.$hired->format('d.m.Y').', di tengah periode: gaji belum dihitung pro-rata (menyusul di proses payroll bulanan).';
        }
        if ($k->termination_date && $k->termination_date->between($p->start, $p->end->subDay())) {
            $result['warnings'][] = 'Keluar '.$k->termination_date->format('d.m.Y').', di tengah periode: final pay (pro-rata & PPh21 setahun) menyusul di proses payroll bulanan.';
        }
        $line = self::lines($k, $p, $settings, $rates, $base);
        $taxOn = $p->isLastOfYear()
            ? self::decemberTax($k, $p, $settings, $rates, $line, $ptkp, $hasTaxId, $method, $result)
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
            $earnings[] = ['label' => 'Tunjangan PPh21 (gross-up)', 'amount' => $taxAllowance, 'note' => null];
        }
        $deductions = [
            ['label' => 'BPJS Kesehatan (karyawan)', 'amount' => $line['bpjs']['employee']['health'], 'note' => self::pct($rates->health_employee_rate)],
            ['label' => 'BPJS JHT (karyawan)', 'amount' => $line['bpjs']['employee']['jht'], 'note' => self::pct($rates->jht_employee_rate)],
            ['label' => 'BPJS JP (karyawan)', 'amount' => $line['bpjs']['employee']['jp'], 'note' => self::pct($rates->jp_employee_rate)],
        ];
        if ($taxDeducted) {
            $deductions[] = ['label' => 'PPh21', 'amount' => $taxDeducted, 'note' => $method === 'gross_up' ? 'ditutup tunjangan PPh21' : null];
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
                ['label' => 'BPJS Kesehatan (perusahaan)', 'amount' => $line['bpjs']['company']['health'], 'note' => self::pct($rates->health_company_rate)],
                ['label' => 'BPJS JKK', 'amount' => $line['bpjs']['company']['jkk'], 'note' => self::pct($line['jkk_rate'])],
                ['label' => 'BPJS JKM', 'amount' => $line['bpjs']['company']['jkm'], 'note' => self::pct($rates->jkm_rate)],
                ['label' => 'BPJS JHT (perusahaan)', 'amount' => $line['bpjs']['company']['jht'], 'note' => self::pct($rates->jht_company_rate)],
                ['label' => 'BPJS JP (perusahaan)', 'amount' => $line['bpjs']['company']['jp'], 'note' => self::pct($rates->jp_company_rate)],
                ...($method === 'net' ? [['label' => 'PPh21 ditanggung perusahaan', 'amount' => $tax, 'note' => 'metode net']] : []),
            ],
            'absence' => $line['absence'],
            'overtime' => $line['overtime'],
            'wage' => $line['wage'],
            'gross' => $gross,
            'tax' => $tax,
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
    private static function lines(Employee $k, PayrollPeriod $p, PayrollSetting $s, PayrollRate $r, int $base): array
    {
        $allowance = Salary::positionAllowance($k);
        $wage = $base + $allowance;
        $absence = AbsenceDeduction::for($k, $p, $s, $base);
        $overtime = OvertimeRecord::where('employee_id', $k->id)->where('status', 'approved')
            ->whereBetween('date', [$p->start->toDateString(), $p->end->toDateString()])->get();
        $weighted = round($overtime->sum('weighted_hours'), 2);
        $overtimePay = (int) round($weighted * $wage / self::PER_HOUR);
        $jkkRate = $k->workLocation?->jkk_rate ?? 0.24;
        $profile = $k->payrollProfile;
        $bpjs = Bpjs::calculate($wage, $r, $jkkRate, $profile?->bpjs_health ?? true, $profile?->bpjs_employment ?? true);

        $earnings = [
            ['label' => 'Gaji Pokok', 'amount' => $base, 'note' => null],
            ['label' => 'Tunjangan Jabatan', 'amount' => $allowance, 'note' => $k->jobLevel?->name],
        ];
        if ($absence['amount']) {
            $earnings[] = ['label' => 'Potong Gapok', 'amount' => -$absence['amount'],
                'note' => "{$absence['days']} hari × gapok ÷ {$absence['divisor']}"];
        }
        if ($overtimePay) {
            $earnings[] = ['label' => 'Lembur', 'amount' => $overtimePay,
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

    /**
     * December: the tax of the year less January–November. Until payroll runs are stored
     * (phase 3) those months are worked out again here from the same data.
     *
     * @return callable(int): int  the December tax for a December gross
     */
    private static function decemberTax(Employee $k, PayrollPeriod $p, PayrollSetting $s, PayrollRate $r, array $line, string $ptkp, bool $hasTaxId, string $method, array &$result): callable
    {
        $hired = $k->profile?->hire_date ? CarbonImmutable::parse($k->profile->hire_date) : null;
        $category = Ptkp::terCategory($ptkp);
        [$grossBefore, $withheld, $contributions, $months] = [0, 0, 0, 0];
        for ($m = 1; $m <= 11; $m++) {
            $earlier = PayrollPeriod::of($p->year, $m, $s->cutoff_day);
            $base = Salary::base($k, $earlier->end);
            if ($base === null || ($hired && $hired->gt($earlier->end))) {
                continue;
            }
            $l = self::lines($k, $earlier, $s, PayrollRate::forYear($p->year), $base);
            $taxOn = fn (int $gross) => Pph21::monthly($category, $gross, $r, $hasTaxId);
            $allowance = $method === 'gross_up' ? Pph21::grossUp($l['gross'], $taxOn) : 0;
            $grossBefore += $l['gross'] + $allowance;
            $withheld += $method === 'gross_up' ? $allowance : $taxOn($l['gross']);
            $contributions += $l['bpjs']['employee']['jht'] + $l['bpjs']['employee']['jp'];
            $months++;
        }
        $contributions += $line['bpjs']['employee']['jht'] + $line['bpjs']['employee']['jp'];
        $months++;
        $result['warnings'][] = 'Desember: PPh21 dihitung setahun. Januari–November dihitung ulang dari data yang ada (belum ada payroll run tersimpan).';

        return function (int $gross) use ($grossBefore, $contributions, $months, $ptkp, $r, $withheld, $hasTaxId, &$result) {
            $a = Pph21::annual($grossBefore + $gross, $contributions, $months, $ptkp, $r, $withheld, $hasTaxId);
            $result['tax_steps'] = [
                ['label' => "Bruto setahun ({$months} bulan)", 'amount' => $a['gross']],
                ['label' => 'Biaya jabatan', 'amount' => -$a['position_cost']],
                ['label' => 'Iuran JHT + JP karyawan', 'amount' => -$a['contributions']],
                ['label' => 'Penghasilan neto', 'amount' => $a['net']],
                ['label' => "PTKP ({$ptkp})", 'amount' => -$a['ptkp']],
                ['label' => 'PKP (dibulatkan ke ribuan)', 'amount' => $a['pkp']],
                ['label' => 'PPh21 setahun (Pasal 17)', 'amount' => $a['tax_year']],
                ['label' => 'Sudah dipotong Januari–November', 'amount' => -$a['withheld']],
                ['label' => 'PPh21 Desember', 'amount' => $a['tax']],
            ];

            return $a['tax'];
        };
    }

    private static function pct(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2, ',', '.'), '0'), ',').'%';
    }
}
