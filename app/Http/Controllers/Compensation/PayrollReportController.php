<?php

namespace App\Http\Controllers\Compensation;

use App\Http\Controllers\Controller;
use App\Models\Branding;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Support\Audit\Audit;
use App\Support\Excel\TableExcel;
use App\Support\Payroll\Reports\AnnualTax;
use App\Support\Payroll\Reports\RunReports;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Laporan Payroll: from an approved run, the bank transfer file, BPJS contributions, PPh 21
 * for Coretax and the journal; per year, the 1721-A1 of every employee. Only approved runs
 * hand files on, and every download is in the audit log.
 */
class PayrollReportController extends Controller
{
    public function run(Request $request, PayrollRun $run, string $type): HttpResponse
    {
        $ids = \App\Support\Access\AccessScope::workLocationIds();
        abort_unless($ids === null || $run->work_location_id === null || in_array($run->work_location_id, $ids, true), 404);
        abort_unless(in_array($run->status, PayrollRun::FINAL, true), 422, 'Laporan dan file bank hanya dari proses gaji yang sudah disetujui.');
        $format = (string) $request->query('format', 'generic');
        abort_if($type === 'bank' && ! array_key_exists($format, RunReports::BANK_FORMATS), 404);
        Audit::log("payroll.report_{$type}", $run, $run->label().($type === 'bank' ? ' · '.RunReports::BANK_FORMATS[$format] : ''));

        return match ($type) {
            'bank' => RunReports::bank($run, $format),
            'bpjs' => RunReports::bpjs($run),
            'pph21' => RunReports::pph21($run),
            'journal' => RunReports::journal($run),
        };
    }

    /** Laporan Tahunan: the 1721-A1 of every employee of the year. */
    public function annual(Request $request): Response
    {
        $year = (int) $request->query('year', now()->year);
        $rows = AnnualTax::year($year, Employee::query()->pluck('id')->all())->sortBy('employee_number')->values();
        $years = PayrollRun::whereIn('status', PayrollRun::FINAL)->pluck('period')->map(fn ($p) => (int) substr($p, 0, 4))->push(now()->year)->unique()->sortDesc()->values();

        return Inertia::render('Compensation/PayrollAnnual', [
            'year' => $year,
            'years' => $years,
            'rows' => $rows->map(fn (array $r) => collect($r)->except('lines')->all() + ['gross' => $r['lines'][8], 'tax_year' => $r['lines'][17], 'withheld' => $r['lines'][20]]),
            'settings_ready' => filled(PayrollSetting::current()->company_tax_id),
        ]);
    }

    public function annualPdf(Request $request, Employee $employee): HttpResponse
    {
        $year = (int) $request->query('year', now()->year);
        $row = AnnualTax::year($year, [$employee->id])->first();
        abort_unless($row, 404);
        Audit::log('payroll.a1_downloaded', $employee, "{$employee->employee_number} {$employee->name} · {$year}");

        return self::a1Pdf($row, $year, $employee->profile?->birth_date ? \Carbon\CarbonImmutable::parse($employee->profile->birth_date)->format('dmY') : null, false);
    }

    /** Every 1721-A1 of the year: the Coretax import (BPA1) and a recap. */
    public function annualExport(Request $request): HttpResponse
    {
        $year = (int) $request->query('year', now()->year);
        $settings = PayrollSetting::current();
        $rows = AnnualTax::year($year, Employee::query()->pluck('id')->all())->sortBy('employee_number')->values();
        $digits = fn ($v) => preg_replace('/\D/', '', (string) $v);
        Audit::log('payroll.a1_exported', null, "1721-A1 {$year} · {$rows->count()} karyawan");
        $l = fn (array $r, int $n) => $r['lines'][$n];

        return TableExcel::download("1721-a1-{$year}.xlsx", [
            [
                'title' => 'BPA1 Coretax',
                'info' => TableExcel::info(["1721-A1 {$year}", 'NPWP pemotong '.($settings->company_tax_id ?: '(isi di Pengaturan)'), 'Cocokkan kolom dengan template BPA1 Coretax terbaru']),
                'freeze' => 4,
                'columns' => [['TaxPeriodMonthStart', 8, 'int'], ['TaxPeriodMonthEnd', 8, 'int'], ['TaxPeriodYear', 8, 'int'], ['CounterpartOpt', 11], ['CounterpartTin', 20, 'text'],
                    ['StatusTaxExemption', 9], ['Position', 22], ['TaxObjectCode', 11], ['NumberOfMonths', 8, 'int'], ['SalaryPensionJhtTht', 15, 'rupiah', 'sum' => true],
                    ['IncomeTaxBenefit', 13, 'rupiah', 'sum' => true], ['OtherBenefit', 15, 'rupiah', 'sum' => true], ['Honorarium', 12, 'rupiah'], ['InsurancePaidByEmployer', 14, 'rupiah', 'sum' => true],
                    ['Natura', 10, 'rupiah'], ['TantiemBonusThr', 14, 'rupiah', 'sum' => true], ['PensionContributionJhtThtFee', 14, 'rupiah', 'sum' => true],
                    ['PrevWhTaxSlip', 10, 'rupiah'], ['Article21IncomeTax', 14, 'rupiah', 'sum' => true], ['IDPlaceOfBusinessActivity', 24, 'text'], ['WithholdingDate', 12]],
                'rows' => $rows->map(fn (array $r) => [
                    $r['month_start'], $r['month_end'], $year, 'Resident', $digits($r['tax_id']) ?: $digits($r['national_id']), $r['ptkp'], $r['position'], '21-100-01', $r['months'],
                    $l($r, 1), $l($r, 2), $l($r, 3), 0, $l($r, 5), 0, $l($r, 7), $l($r, 10), 0, $l($r, 20), $digits($settings->company_nitku), "{$year}-12-31",
                ])->all(),
            ],
            [
                'title' => 'Recap', 'info' => TableExcel::info(["1721-A1 {$year}"]),
                'columns' => [['NRP', 12, 'text'], ['Name', 26], ['Months', 7, 'int'], ['Gross', 16, 'rupiah', 'sum' => true], ['Net', 16, 'rupiah', 'sum' => true],
                    ['PTKP', 14, 'rupiah'], ['PKP', 15, 'rupiah', 'sum' => true], ['PPh 21 Year', 14, 'rupiah', 'sum' => true], ['Withheld', 14, 'rupiah', 'sum' => true],
                    ['Difference', 13, 'rupiah', 'sum' => true], ['Complete', 9]],
                'rows' => $rows->map(fn (array $r) => [$r['employee_number'], $r['name'], $r['months'], $l($r, 8), $l($r, 14), $l($r, 15), $l($r, 16), $l($r, 17), $l($r, 20), $r['difference'], $r['complete'] ? 'Ya' : 'Belum'])->all(),
            ],
        ]);
    }

    /** The 1721-A1 summary as a PDF (opened with the birth date when $password is given). */
    public static function a1Pdf(array $row, int $year, ?string $password, bool $protect): HttpResponse
    {
        $settings = PayrollSetting::current();
        $pdf = Pdf::loadView('pdf.a1', ['r' => $row, 'year' => $year, 'labels' => AnnualTax::LABELS, 'branding' => Branding::current(), 'settings' => $settings])
            ->setPaper('a4')->setOption(['isFontSubsettingEnabled' => true, 'isRemoteEnabled' => false]);
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        if ($protect && $password && $settings->payslip_password) {
            $dompdf->getCanvas()->get_cpdf()->setEncryption($password, Str::random(24), ['print']);
        }

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="1721-a1-'.$year.'-'.Str::slug($row['employee_number'] ?? 'x').'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
