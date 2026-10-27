<?php

namespace App\Support\Payroll\Reports;

use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Models\Payslip;
use App\Support\Access\AccessScope;
use App\Support\Excel\TableExcel;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The files an approved payroll run hands on: the bank transfer, BPJS contributions, PPh 21
 * for Coretax, and the journal. Bank and Coretax layouts follow the banks' and DJP's import
 * templates; check them against the template the bank or Coretax gives at the time.
 */
class RunReports
{
    public const BANK_FORMATS = ['generic' => 'Format umum (Excel)', 'bca' => 'BCA (CSV transfer massal)', 'mandiri' => 'Mandiri (CSV MCM bulk)'];

    /** Slips of the run the account may see, with lines. */
    public static function slips(PayrollRun $run): Collection
    {
        $ids = AccessScope::workLocationIds();

        return Payslip::with('lines')->where('payroll_run_id', $run->id)->whereNull('error')
            ->when($ids !== null, fn ($q) => $q->whereIn('work_location_id', $ids))
            ->orderBy('employee_number')->get();
    }

    private static function remark(PayrollRun $run): string
    {
        return Str::limit(match ($run->type) {
            'thr' => 'THR '.(PayrollRun::HOLIDAYS[$run->holiday][0] ?? '').' '.$run->holiday_date?->year,
            'final' => 'Final pay '.$run->period,
            default => 'Gaji '.$run->periodOf()->label(),
        }, 30, '');
    }

    private static function digits(?string $v): string
    {
        return preg_replace('/\D/', '', (string) $v);
    }

    public static function bank(PayrollRun $run, string $format): StreamedResponse
    {
        $slips = self::slips($run)->filter(fn (Payslip $s) => $s->take_home_pay > 0);
        $has = fn (Payslip $s) => self::digits($s->snapshot['bank_account_number'] ?? null) !== '';
        $bankIs = fn (Payslip $s, string $bank) => str_contains(strtoupper((string) ($s->snapshot['bank_name'] ?? '')), strtoupper($bank));
        $name = "transfer-{$run->type}-{$run->period}";
        $remark = self::remark($run);

        if ($format === 'generic') {
            $info = TableExcel::info(['Transfer '.$run->label(), 'Rekening sumber '.(PayrollSetting::current()->payer_bank ?? '-').' '.(PayrollSetting::current()->payer_account ?? '')]);
            $missing = $slips->reject($has);

            return TableExcel::download("{$name}.xlsx", [
                [
                    'title' => 'Bank Transfer', 'info' => $info, 'freeze' => 4,
                    'columns' => [['No', 5, 'int'], ['Bank', 12], ['Account Number', 20, 'text'], ['Account Holder', 28], ['NRP', 12, 'text'], ['Name', 28], ['Amount', 16, 'rupiah', 'sum' => true], ['Remark', 30]],
                    'rows' => $slips->filter($has)->values()->map(fn (Payslip $s, int $i) => [
                        $i + 1, $s->snapshot['bank_name'] ?? null, self::digits($s->snapshot['bank_account_number'] ?? null),
                        $s->snapshot['bank_account_holder'] ?? $s->name, $s->employee_number, $s->name, $s->take_home_pay, $remark,
                    ])->all(),
                ],
                [
                    'title' => 'Without Account', 'info' => 'Dibayar terpisah: nomor rekening kosong di profil.',
                    'columns' => [['NRP', 12, 'text'], ['Name', 28], ['Location', 20], ['Amount', 16, 'rupiah', 'sum' => true]],
                    'rows' => $missing->map(fn (Payslip $s) => [$s->employee_number, $s->name, $s->snapshot['location'] ?? null, $s->take_home_pay])->values()->all(),
                ],
            ]);
        }

        $bank = $format === 'bca' ? 'BCA' : 'MANDIRI';
        $rows = $slips->filter(fn (Payslip $s) => $has($s) && $bankIs($s, $bank))->values();
        $date = ($run->pay_date ?? now())->format('Ymd');
        $lines = [];
        if ($format === 'mandiri') {
            // MCM bulk: a header (P, date, source account, count, total), then one line per transfer.
            $lines[] = ['P', $date, self::digits(PayrollSetting::current()->payer_account), $rows->count(), (int) $rows->sum('take_home_pay')];
            foreach ($rows as $s) {
                $lines[] = [self::digits($s->snapshot['bank_account_number']), Str::limit(Str::ascii($s->snapshot['bank_account_holder'] ?? $s->name), 40, ''), '', '', '', 'IDR', (int) $s->take_home_pay, $remark, '', 'IBU', '', '', '', '', '', '', 'N', '', '', '', 'Y', ''];
            }
        } else {
            // BCA transfer massal: account, amount, name, remark.
            foreach ($rows as $s) {
                $lines[] = [self::digits($s->snapshot['bank_account_number']), (int) $s->take_home_pay, Str::limit(Str::ascii($s->snapshot['bank_account_holder'] ?? $s->name), 35, ''), $remark];
            }
        }

        return response()->streamDownload(function () use ($lines) {
            $out = fopen('php://output', 'w');
            foreach ($lines as $l) {
                fputcsv($out, $l, ',', '"', '');
            }
            fclose($out);
        }, "{$name}-".strtolower($bank).'.csv', ['Content-Type' => 'text/csv']);
    }

    public static function bpjs(PayrollRun $run): StreamedResponse
    {
        $slips = self::slips($run);
        $amt = fn (Payslip $s, string $code) => (int) $s->lines->firstWhere('code', $code)?->amount;
        $info = TableExcel::info(['Iuran BPJS '.$run->label()]);
        $perLocation = $slips->groupBy(fn ($s) => $s->snapshot['location'] ?? '-');

        return TableExcel::download("bpjs-{$run->period}.xlsx", [
            [
                'title' => 'BPJS Ketenagakerjaan', 'info' => $info, 'freeze' => 4,
                'columns' => [['NIK', 18, 'text'], ['KPJ Number', 16, 'text'], ['NRP', 12, 'text'], ['Name', 26], ['Location', 18], ['Wage', 14, 'rupiah', 'sum' => true],
                    ['JKK', 12, 'rupiah', 'sum' => true], ['JKM', 12, 'rupiah', 'sum' => true], ['JHT Company', 13, 'rupiah', 'sum' => true], ['JHT Employee', 13, 'rupiah', 'sum' => true],
                    ['JP Company', 12, 'rupiah', 'sum' => true], ['JP Employee', 12, 'rupiah', 'sum' => true], ['Total', 14, 'rupiah', 'sum' => true]],
                'rows' => $slips->map(fn (Payslip $s) => [
                    $s->snapshot['national_id_number'] ?? null, $s->snapshot['bpjs_employment_number'] ?? null, $s->employee_number, $s->name, $s->snapshot['location'] ?? null, $s->wage,
                    $amt($s, 'bpjs_jkk'), $amt($s, 'bpjs_jkm'), $amt($s, 'bpjs_jht_company'), $amt($s, 'bpjs_jht'), $amt($s, 'bpjs_jp_company'), $amt($s, 'bpjs_jp'),
                    $amt($s, 'bpjs_jkk') + $amt($s, 'bpjs_jkm') + $amt($s, 'bpjs_jht_company') + $amt($s, 'bpjs_jht') + $amt($s, 'bpjs_jp_company') + $amt($s, 'bpjs_jp'),
                ])->values()->all(),
            ],
            [
                'title' => 'BPJS Kesehatan', 'info' => $info, 'freeze' => 4,
                'columns' => [['NIK', 18, 'text'], ['JKN Number', 16, 'text'], ['NRP', 12, 'text'], ['Name', 26], ['Location', 18], ['Wage', 14, 'rupiah', 'sum' => true],
                    ['Company 4%', 14, 'rupiah', 'sum' => true], ['Employee 1%+', 14, 'rupiah', 'sum' => true], ['Total', 14, 'rupiah', 'sum' => true]],
                'rows' => $slips->map(fn (Payslip $s) => [
                    $s->snapshot['national_id_number'] ?? null, $s->snapshot['bpjs_health_number'] ?? null, $s->employee_number, $s->name, $s->snapshot['location'] ?? null, $s->wage,
                    $amt($s, 'bpjs_health_company'), $amt($s, 'bpjs_health'), $amt($s, 'bpjs_health_company') + $amt($s, 'bpjs_health'),
                ])->values()->all(),
            ],
            [
                'title' => 'Per Location', 'info' => $info,
                'columns' => [['Location', 22], ['Employees', 10, 'int', 'sum' => true], ['JKK', 13, 'rupiah', 'sum' => true], ['JKM', 13, 'rupiah', 'sum' => true],
                    ['JHT', 14, 'rupiah', 'sum' => true], ['JP', 14, 'rupiah', 'sum' => true], ['Kesehatan', 14, 'rupiah', 'sum' => true]],
                'rows' => $perLocation->map(fn ($rows, $loc) => [
                    $loc, $rows->count(),
                    $rows->sum(fn ($s) => $amt($s, 'bpjs_jkk')), $rows->sum(fn ($s) => $amt($s, 'bpjs_jkm')),
                    $rows->sum(fn ($s) => $amt($s, 'bpjs_jht_company') + $amt($s, 'bpjs_jht')), $rows->sum(fn ($s) => $amt($s, 'bpjs_jp_company') + $amt($s, 'bpjs_jp')),
                    $rows->sum(fn ($s) => $amt($s, 'bpjs_health_company') + $amt($s, 'bpjs_health')),
                ])->values()->all(),
            ],
        ]);
    }

    /** Bukti Potong PPh 21 bulanan (BPMP) for the Coretax Excel import, and a recap. */
    public static function pph21(PayrollRun $run): StreamedResponse
    {
        $settings = PayrollSetting::current();
        $slips = self::slips($run);
        [$year, $month] = array_map('intval', explode('-', $run->period));
        $date = ($run->pay_date ?? $run->period_end)->format('Y-m-d');

        return TableExcel::download("pph21-{$run->type}-{$run->period}.xlsx", [
            [
                'title' => 'BPMP Coretax',
                'info' => TableExcel::info(['PPh 21 '.$run->label(), 'NPWP pemotong '.($settings->company_tax_id ?: '(isi di Pengaturan)'), 'Cocokkan kolom dengan template BPMP Coretax terbaru']),
                'freeze' => 4,
                'columns' => [['TaxPeriodMonth', 9, 'int'], ['TaxPeriodYear', 9, 'int'], ['CounterpartOpt', 12], ['CounterpartPassport', 12], ['CounterpartTin', 20, 'text'],
                    ['StatusTaxExemption', 10], ['Position', 24], ['TaxCertificate', 10], ['TaxObjectCode', 12], ['Gross', 15, 'rupiah', 'sum' => true],
                    ['Rate', 8], ['IDPlaceOfBusinessActivity', 24, 'text'], ['WithholdingDate', 12], ['Article21IncomeTax', 15, 'rupiah', 'sum' => true]],
                'rows' => $slips->map(fn (Payslip $s) => [
                    $month, $year, 'Resident', null, self::digits($s->snapshot['tax_id_number'] ?? null) ?: self::digits($s->snapshot['national_id_number'] ?? null),
                    $s->snapshot['ptkp'] ?? null, $s->snapshot['position'] ?? null, 'N/A', '21-100-01', $s->gross,
                    $s->gross > 0 ? round($s->tax / $s->gross * 100, 2) : 0, self::digits($settings->company_nitku), $date, $s->tax,
                ])->values()->all(),
            ],
            [
                'title' => 'Recap', 'info' => TableExcel::info(['PPh 21 '.$run->label()]),
                'columns' => [['NRP', 12, 'text'], ['Name', 26], ['NPWP / NIK', 20, 'text'], ['PTKP', 7], ['TER', 5], ['Method', 9], ['Gross', 15, 'rupiah', 'sum' => true],
                    ['PPh 21', 14, 'rupiah', 'sum' => true], ['Withheld', 14, 'rupiah', 'sum' => true], ['Borne by Company', 14, 'rupiah', 'sum' => true]],
                'rows' => $slips->map(fn (Payslip $s) => [
                    $s->employee_number, $s->name, self::digits($s->snapshot['tax_id_number'] ?? null) ?: self::digits($s->snapshot['national_id_number'] ?? null),
                    $s->snapshot['ptkp'] ?? null, $s->snapshot['ter_category'] ?? null, $s->snapshot['tax_method'] ?? null, $s->gross, $s->tax, $s->tax_withheld,
                    ($s->snapshot['tax_method'] ?? null) === 'net' ? $s->tax : 0,
                ])->values()->all(),
            ],
        ]);
    }

    /** The payroll journal per location: expenses debited, take home pay and deductions credited. */
    public static function journal(PayrollRun $run): StreamedResponse
    {
        $chart = Accounts::chart();
        $book = [];
        foreach (self::slips($run) as $s) {
            $loc = $s->snapshot['location'] ?? '-';
            $post = function (string $group, int $debit, int $credit) use (&$book, $loc) {
                $book[$loc][$group] ??= [0, 0];
                $book[$loc][$group][0] += $debit;
                $book[$loc][$group][1] += $credit;
            };
            foreach ($s->lines as $l) {
                match ($l->kind) {
                    'earning' => $post(Accounts::expenseGroup('earning', $l->code), (int) $l->amount, 0),
                    'deduction' => $post(Accounts::creditGroup('deduction', $l->code), 0, (int) $l->amount),
                    'company' => [$post(Accounts::expenseGroup('company', $l->code), (int) $l->amount, 0), $post(Accounts::creditGroup('company', $l->code), 0, (int) $l->amount)],
                    default => null,
                };
            }
            $post('salary_payable', 0, (int) $s->take_home_pay);
        }
        $rows = [];
        foreach ($book as $loc => $groups) {
            foreach ($chart as $group => [$number, $name]) {
                if (! isset($groups[$group])) {
                    continue;
                }
                [$d, $c] = $groups[$group];
                $net = $d - $c;
                if ($net !== 0) {
                    $rows[] = [$number, $name, $loc, self::remark($run), max($net, 0), max(-$net, 0)];
                }
            }
        }

        return TableExcel::download("journal-{$run->type}-{$run->period}.xlsx", [[
            'title' => 'Payroll Journal', 'info' => TableExcel::info(['Jurnal '.$run->label(), 'Debit = Kredit']),
            'columns' => [['Account', 10, 'text'], ['Account Name', 34], ['Cost Center', 20], ['Description', 30], ['Debit', 16, 'rupiah', 'sum' => true], ['Credit', 16, 'rupiah', 'sum' => true]],
            'rows' => $rows,
        ]]);
    }
}
