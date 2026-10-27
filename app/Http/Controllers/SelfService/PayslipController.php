<?php

namespace App\Http\Controllers\SelfService;

use App\Http\Controllers\Compensation\PayrollRunController;
use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Models\Payslip;
use App\Support\Payroll\PayslipPdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Portal Karyawan → Slip Gaji: the employee's own slips, once their payroll run is approved. */
class PayslipController extends Controller
{
    public function index(Request $request): Response
    {
        $slips = $this->own($request)->with('run')->get()->sortByDesc(fn (Payslip $s) => $s->run->period)->values();
        $chosen = $request->integer('slip') ? $slips->firstWhere('id', $request->integer('slip')) : $slips->first();

        return Inertia::render('SelfService/Payslips', [
            'slips' => $slips->map(fn (Payslip $s) => [
                'id' => $s->id, 'period' => $s->run->period,
                'label' => match ($s->run->type) {
                    'thr' => 'THR '.(PayrollRun::HOLIDAYS[$s->run->holiday][0] ?? '').' '.$s->run->holiday_date?->year,
                    'final' => 'Final pay '.$s->run->periodOf()->label(),
                    default => $s->run->periodOf()->label(),
                },
                'pay_date' => $s->run->pay_date?->toDateString(), 'take_home_pay' => $s->take_home_pay,
            ]),
            'slip' => $chosen ? PayrollRunController::slipData($chosen) : null,
            'password' => (bool) PayrollSetting::current()->payslip_password,
        ]);
    }

    public function pdf(Request $request, Payslip $payslip): HttpResponse
    {
        abort_unless($this->own($request)->whereKey($payslip->id)->exists(), 404);

        return PayslipPdf::download($payslip, protect: true);
    }

    private function own(Request $request)
    {
        return Payslip::where('employee_id', $request->user()->employee_id ?? 0)->whereNull('error')
            ->whereHas('run', fn ($q) => $q->whereIn('status', PayrollRun::FINAL));
    }
}
