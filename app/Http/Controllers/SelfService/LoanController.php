<?php

namespace App\Http\Controllers\SelfService;

use App\Http\Controllers\Compensation\LoanController as HrLoanController;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\PayrollSetting;
use App\Support\Audit\Audit;
use App\Support\Notifications\RequestNotifier;
use App\Support\Payroll\Loans;
use App\Support\Workflow\Workflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Portal Karyawan → Pinjaman: the own loans and their installments, and asking for a new one. */
class LoanController extends Controller
{
    public function index(Request $request): Response
    {
        $settings = PayrollSetting::current();
        $loans = Loan::with('installments')->where('employee_id', $request->user()->employee_id ?? 0)->orderByDesc('id')->get();
        $flows = Workflow::pendingOf(Loan::class, $loans->where('status', 'pending')->pluck('id'));

        return Inertia::render('SelfService/Loans', [
            'loans' => $loans->map(fn (Loan $l) => HrLoanController::share($l) + ['workflow' => Workflow::brief($flows->get($l->id), $request->user())]),
            'next_period' => HrLoanController::nextPeriod($settings),
            'max_ratio' => $settings->loan_max_ratio,
            'has_pending' => $loans->contains('status', 'pending'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $employee = Employee::findOrFail($request->user()->employee_id);
        abort_if(Loan::where('employee_id', $employee->id)->where('status', 'pending')->exists(), 422, 'Masih ada pengajuan pinjaman yang menunggu.');
        $data = HrLoanController::validated($request);
        Loans::assertAffordable($employee, $data['installment']);
        $loan = DB::transaction(function () use ($data, $employee, $request) {
            $loan = Loan::create($data + ['employee_id' => $employee->id, 'status' => 'pending', 'source' => 'portal', 'created_by' => $request->user()->id]);
            $loan->startWorkflow($request->user());

            return $loan;
        });
        Audit::log('payroll.loan_requested', $loan, 'Rp '.number_format($loan->principal, 0, ',', '.')." × {$loan->tenor} bln");
        Inertia::flash('success', 'Pengajuan pinjaman terkirim dan menunggu persetujuan.');

        return back();
    }

    public function cancel(Request $request, Loan $loan): RedirectResponse
    {
        abort_unless($loan->employee_id === $request->user()->employee_id && $loan->status === 'pending', 404);
        // In a Workflow: withdrawn there (which removes it); from before Workflow: removed here.
        $flow = Workflow::of($loan);
        if ($flow?->isPending()) {
            Workflow::cancel($flow, $request->user());
        } else {
            RequestNotifier::close("loan:{$loan->id}");
            $loan->delete();
        }
        Inertia::flash('success', 'Pengajuan pinjaman dibatalkan.');

        return back();
    }
}
