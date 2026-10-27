<?php

namespace App\Http\Controllers\Compensation;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\PayrollSetting;
use App\Support\Audit\Audit;
use App\Support\Notifications\RequestNotifier;
use App\Support\Payroll\Loans;
use App\Support\Payroll\PayrollPeriod;
use App\Support\Payroll\PayrollPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pinjaman Karyawan: loans entered by HR ("loans") or asked for in the portal, approved by
 * "payroll-approval" (not by whoever entered them, except the Super Admin), then repaid by
 * monthly installments that Proses Gaji deducts.
 */
class LoanController extends Controller
{
    public function index(Request $request): Response
    {
        $settings = PayrollSetting::current();
        $status = $request->query('status');
        $loans = Loan::with(['employee:id,employee_number,name,work_location_id', 'installments'])
            ->whereIn('employee_id', Employee::query()->select('id'))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByRaw("case status when 'pending' then 0 when 'active' then 1 else 2 end")->orderByDesc('id')->get();
        $user = $request->user();

        return Inertia::render('Compensation/Loans', [
            'loans' => $loans->map(fn (Loan $l) => self::share($l) + ['own' => $l->created_by === $user->id]),
            'status' => $status,
            'statuses' => Loan::STATUS,
            'employees' => Employee::query()->where('status', '!=', Employee::STATUS_TERMINATED)->orderBy('name')->get(['id', 'employee_number', 'name'])
                ->map(fn (Employee $e) => ['id' => $e->id, 'label' => "{$e->employee_number} · {$e->name}"]),
            'next_period' => self::nextPeriod($settings),
            'max_ratio' => $settings->loan_max_ratio,
            'can' => ['edit' => $user->canEdit('loans'), 'approve' => $user->canEdit('payroll-approval'), 'approve_own' => $user->isAdmin()],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = self::validated($request);
        $employee = Employee::findOrFail($request->integer('employee_id')); // scoped
        $loan = Loan::create($data + ['employee_id' => $employee->id, 'status' => 'pending', 'source' => 'hr', 'created_by' => $request->user()->id]);
        Audit::log('payroll.loan_created', $loan, "{$employee->employee_number} {$employee->name} · Rp ".number_format($loan->principal, 0, ',', '.')." × {$loan->tenor} bln");
        Inertia::flash('success', 'Pinjaman dicatat, menunggu persetujuan.');

        return back();
    }

    public function approve(Request $request, Loan $loan): RedirectResponse
    {
        $this->assertVisible($loan);
        abort_unless($loan->status === 'pending', 422);
        abort_if($loan->created_by === $request->user()->id && ! $request->user()->isAdmin(), 403, 'Pinjaman yang Anda catat disetujui oleh akun lain.');
        Loans::assertAffordable($loan->employee, $loan->installment, $loan);
        if (PayrollPeriods::closed($loan->employee, $loan->start_period)) {
            throw ValidationException::withMessages(['tenor' => 'Gaji periode mulai sudah disetujui. Ubah periode mulai ke periode berikutnya.']);
        }
        Loans::activate($loan, $request->user()->id);
        RequestNotifier::close("loan:{$loan->id}");
        Audit::log('payroll.loan_approved', $loan, "{$loan->employee->employee_number} {$loan->employee->name} · Rp ".number_format($loan->principal, 0, ',', '.'));
        Inertia::flash('success', "Pinjaman disetujui: {$loan->tenor} angsuran mulai ".PayrollPeriod::fromCode($loan->start_period, 31)->label().'.');

        return back();
    }

    public function reject(Request $request, Loan $loan): RedirectResponse
    {
        $this->assertVisible($loan);
        abort_unless($loan->status === 'pending', 422);
        $note = $request->validate(['note' => ['required', 'string', 'max:255']])['note'];
        $loan->update(['status' => 'rejected', 'rejection_note' => $note]);
        RequestNotifier::close("loan:{$loan->id}");
        Audit::log('payroll.loan_rejected', $loan, $note);
        Inertia::flash('success', 'Pinjaman ditolak.');

        return back();
    }

    /** A waiting loan's terms, changed by HR before it is approved. */
    public function update(Request $request, Loan $loan): RedirectResponse
    {
        $this->assertVisible($loan);
        abort_unless($loan->status === 'pending', 422);
        $loan->update(self::validated($request));
        Inertia::flash('success', 'Pinjaman diperbarui.');

        return back();
    }

    public function destroy(Loan $loan): RedirectResponse
    {
        $this->assertVisible($loan);
        abort_unless(in_array($loan->status, ['pending', 'rejected'], true), 422, 'Pinjaman yang berjalan dilunasi, tidak dihapus.');
        $loan->delete();
        Inertia::flash('success', 'Pinjaman dihapus.');

        return back();
    }

    public function postpone(LoanInstallment $installment): RedirectResponse
    {
        $this->assertVisible($installment->loan);
        if (PayrollPeriods::closed($installment->loan->employee, $installment->period)) {
            throw ValidationException::withMessages(['installment' => 'Gaji periode angsuran ini sudah disetujui.']);
        }
        Loans::postpone($installment);
        Audit::log('payroll.loan_postponed', $installment->loan, "Angsuran ke-{$installment->sequence} ditunda");
        Inertia::flash('success', "Angsuran ke-{$installment->sequence} dan sesudahnya mundur satu bulan.");

        return back();
    }

    /** The rest at once: in a payroll period, or settled outside pay. */
    public function payOff(Request $request, Loan $loan): RedirectResponse
    {
        $this->assertVisible($loan);
        abort_unless($loan->status === 'active', 422);
        $data = $request->validate(['how' => ['required', 'in:payroll,outside'], 'period' => ['required_if:how,payroll', 'nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'note' => ['nullable', 'string', 'max:255']]);
        $left = $loan->remaining();
        if ($data['how'] === 'payroll') {
            if (PayrollPeriods::closed($loan->employee, $data['period'])) {
                throw ValidationException::withMessages(['period' => 'Gaji periode itu sudah disetujui.']);
            }
            Loans::payOffIn($loan, $data['period']);
        } else {
            Loans::settle($loan, $data['note'] ?? null);
        }
        Audit::log('payroll.loan_paid_off', $loan, ($data['how'] === 'payroll' ? "Lewat gaji {$data['period']}" : 'Di luar gaji').' · Rp '.number_format($left, 0, ',', '.'));
        Inertia::flash('success', $data['how'] === 'payroll' ? 'Sisa pinjaman dipotong sekaligus di periode itu.' : 'Pinjaman dilunasi.');

        return back();
    }

    public static function share(Loan $l): array
    {
        return [
            'id' => $l->id, 'employee_id' => $l->employee_id, 'employee_number' => $l->employee?->employee_number, 'name' => $l->employee?->name,
            'principal' => (int) $l->principal, 'tenor' => $l->tenor, 'installment' => (int) $l->installment, 'start_period' => $l->start_period,
            'purpose' => $l->purpose, 'status' => $l->status, 'status_label' => Loan::STATUS[$l->status] ?? $l->status, 'source' => $l->source,
            'rejection_note' => $l->rejection_note, 'created_at' => $l->created_at?->toDateString(),
            'paid' => (int) $l->installments->whereIn('status', ['deducted', 'settled'])->sum('amount'),
            'remaining' => (int) $l->installments->where('status', 'scheduled')->sum('amount'),
            'installments' => $l->installments->map(fn (LoanInstallment $i) => [
                'id' => $i->id, 'sequence' => $i->sequence, 'period' => $i->period, 'amount' => (int) $i->amount,
                'status' => $i->status, 'status_label' => LoanInstallment::STATUS[$i->status], 'notes' => $i->notes,
            ])->values(),
        ];
    }

    public static function nextPeriod(PayrollSetting $s): string
    {
        $t = CarbonImmutable::today()->addMonth();

        return PayrollPeriod::of($t->year, $t->month, $s->cutoff_day)->code();
    }

    /** @return array{principal: int, tenor: int, installment: int, start_period: string, purpose: ?string, notes: ?string} */
    public static function validated(Request $request): array
    {
        $data = $request->validate([
            'principal' => ['required', 'integer', 'min:100000', 'max:10000000000'],
            'tenor' => ['required', 'integer', 'min:1', 'max:60'],
            'start_period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['principal' => 'Jumlah pinjaman', 'tenor' => 'Tenor', 'start_period' => 'Mulai dipotong']);
        $data['installment'] = Loans::amounts($data['principal'], $data['tenor'])[0];

        return $data;
    }

    private function assertVisible(Loan $loan): void
    {
        abort_unless(Employee::whereKey($loan->employee_id)->exists(), 404); // scoped
    }
}
