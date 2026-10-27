<?php

namespace App\Support\Payroll;

use App\Models\Branding;
use App\Models\PayrollSetting;
use App\Models\Payslip;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The payslip as a PDF, in the company's branding. Downloaded by the employee it opens with
 * their birth date (ddmmyyyy) when Pengaturan Kompensasi → Payroll says so.
 */
class PayslipPdf
{
    public static function render(Payslip $slip, bool $protect): string
    {
        $slip->loadMissing('run', 'employee.profile');
        $branding = Branding::current();
        $logo = null;
        if ($branding->logo_full_path && Storage::disk('local')->exists($branding->logo_full_path)) {
            $mime = Storage::disk('local')->mimeType($branding->logo_full_path) ?: 'image/png';
            $logo = $mime === 'image/svg+xml' ? null : 'data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($branding->logo_full_path));
        }

        $pdf = Pdf::loadView('pdf.payslip', [
            'slip' => $slip,
            'data' => \App\Http\Controllers\Compensation\PayrollRunController::slipData($slip),
            'run' => $slip->run,
            'branding' => $branding,
            'logo' => $logo,
        ])->setPaper('a4')->setOption(['isFontSubsettingEnabled' => true, 'isRemoteEnabled' => false]);
        $dompdf = $pdf->getDomPDF();
        $dompdf->render();
        $password = $protect ? self::password($slip) : null;
        if ($password) {
            $dompdf->getCanvas()->get_cpdf()->setEncryption($password, Str::random(24), ['print']);
        }

        return $dompdf->output();
    }

    public static function download(Payslip $slip, bool $protect): Response
    {
        $name = 'slip-gaji-'.($slip->run?->period ?? 'x').'-'.Str::slug($slip->employee_number ?: (string) $slip->employee_id).'.pdf';

        return response(self::render($slip, $protect), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** The birth date as ddmmyyyy, when slips carry a password and the date is known. */
    public static function password(Payslip $slip): ?string
    {
        $birth = $slip->employee?->profile?->birth_date;
        if (! PayrollSetting::current()->payslip_password || ! $birth) {
            return null;
        }

        return \Carbon\CarbonImmutable::parse($birth)->format('dmY');
    }
}
