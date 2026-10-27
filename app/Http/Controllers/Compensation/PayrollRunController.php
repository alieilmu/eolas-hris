<?php

namespace App\Http\Controllers\Compensation;

use App\Http\Controllers\Controller;
use App\Jobs\CalculatePayrollRun;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Access\AccessScope;
use App\Support\Audit\Audit;
use App\Support\Excel\TableExcel;
use App\Support\Payroll\PayrollPeriod;
use App\Support\Payroll\PayrollRunner;
use App\Support\Payroll\PayslipPdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Proses Gaji: payroll runs per period, for all work locations or one. "payroll-run" works
 * a run out and submits it; "payroll-approval" approves (or sends back), marks it paid and
 * locks it. Someone who worked a run out or submitted it does not approve it, except the
 * Super Admin. An account limited to locations only acts on runs of its own locations.
 */
class PayrollRunController extends Controller
{
    public function index(): Response
    {
        $settings = PayrollSetting::current();
        $today = CarbonImmutable::today();
        $periods = [];
        for ($d = $today->subMonths(12)->startOfMonth(); $d->lte($today->addMonths(2)); $d = $d->addMonth()) {
            $p = PayrollPeriod::of($d->year, $d->month, $settings->cutoff_day);
            $periods[] = ['code' => $p->code(), 'label' => $p->label().' ('.$p->range().')'];
        }
        $ids = AccessScope::workLocationIds();
        $runs = PayrollRun::with('workLocation:id,name', 'creator:id,name')
            ->when($ids !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('work_location_id')->orWhereIn('work_location_id', $ids)))
            ->orderByDesc('period')->orderBy('work_location_id')->get();

        return Inertia::render('Compensation/PayrollRuns', [
            'runs' => $runs->map(fn (PayrollRun $r) => self::share($r)),
            'periods' => array_reverse($periods),
            'current_period' => PayrollPeriod::of($today->year, $today->month, $settings->cutoff_day)->code(),
            'locations' => AccessScope::locations(),
            'all_locations' => $ids === null,
            'can_run' => Auth::user()->canEdit('payroll-run'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'work_location_id' => ['nullable', 'integer', Rule::exists(WorkLocation::class, 'id')],
            'pay_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $location = $data['work_location_id'] ?? null;
        if ($location === null && AccessScope::workLocationIds() !== null) {
            throw ValidationException::withMessages(['work_location_id' => 'Pilih lokasi kerja: akun Anda hanya untuk lokasi tertentu.']);
        }
        AccessScope::assertWorkLocation($location);
        $clash = PayrollRun::where('type', 'regular')->where('period', $data['period'])
            ->when($location, fn ($q) => $q->where(fn ($q) => $q->whereNull('work_location_id')->orWhere('work_location_id', $location)))
            ->exists();
        if ($clash) {
            throw ValidationException::withMessages(['period' => $location
                ? 'Periode ini sudah punya proses gaji untuk lokasi ini atau untuk semua lokasi.'
                : 'Periode ini sudah punya proses gaji (untuk satu lokasi atau semua). Hapus dulu, atau buat per lokasi.']);
        }
        $period = PayrollPeriod::fromCode($data['period'], PayrollSetting::current()->cutoff_day);
        $run = PayrollRun::create([
            'period' => $data['period'], 'type' => 'regular', 'work_location_id' => $location,
            'period_start' => $period->start->toDateString(), 'period_end' => $period->end->toDateString(),
            'pay_date' => $data['pay_date'] ?? null, 'notes' => $data['notes'] ?? null,
            'status' => 'draft', 'created_by' => $request->user()->id,
        ]);
        $run->update(['employee_count' => PayrollRunner::employees($run)->count()]);
        Audit::log('payroll.run_created', $run, $run->label());
        Inertia::flash('success', 'Proses gaji '.$run->label().' dibuat. Klik Hitung untuk menghitung slip.');

        return redirect("/compensation/payroll/{$run->id}");
    }

    public function show(Request $request, PayrollRun $run): Response
    {
        $this->assertVisible($run);
        $ids = AccessScope::workLocationIds();
        $slips = Payslip::where('payroll_run_id', $run->id)
            ->when($ids !== null, fn ($q) => $q->whereIn('work_location_id', $ids))
            ->orderBy('employee_number')
            ->get(['id', 'employee_id', 'work_location_id', 'employee_number', 'name', 'snapshot', 'total_earnings', 'total_deductions', 'tax', 'company_cost', 'take_home_pay', 'prorate', 'error', 'warnings']);
        $user = $request->user();

        return Inertia::render('Compensation/PayrollRun', [
            'run' => self::share($run->load('workLocation:id,name', 'creator:id,name')) + ['totals' => $run->totals, 'steps' => self::steps($run)],
            'slips' => $slips->map(fn (Payslip $s) => [
                'id' => $s->id, 'employee_id' => $s->employee_id, 'employee_number' => $s->employee_number, 'name' => $s->name,
                'position' => $s->snapshot['position'] ?? null, 'department' => $s->snapshot['department'] ?? null, 'location' => $s->snapshot['location'] ?? null,
                'ptkp' => $s->snapshot['ptkp'] ?? null, 'total_earnings' => $s->total_earnings, 'total_deductions' => $s->total_deductions,
                'tax' => $s->tax, 'company_cost' => $s->company_cost, 'take_home_pay' => $s->take_home_pay, 'prorate' => $s->prorate,
                'error' => $s->error, 'warnings' => $s->warnings ?? [],
            ]),
            'locations' => WorkLocation::orderBy('sort_order')->get(['id', 'name']),
            'can' => [
                'run' => $user->canEdit('payroll-run') && $this->inScope($run),
                'approve' => $user->canEdit('payroll-approval') && $this->inScope($run) && $this->mayApprove($run, $user),
                'approval_menu' => $user->canEdit('payroll-approval'),
            ],
        ]);
    }

    public function calculate(Request $request, PayrollRun $run): RedirectResponse
    {
        $this->assertActionable($run);
        abort_unless($run->editable(), 422, 'Proses gaji ini tidak bisa dihitung ulang pada status sekarang.');
        $run->update(['status' => 'calculating', 'progress' => 0]);
        CalculatePayrollRun::dispatch($run->id, $request->user()->id);
        Audit::log('payroll.run_calculated', $run, $run->label());
        Inertia::flash('success', 'Slip sedang dihitung.');

        return back();
    }

    public function submit(Request $request, PayrollRun $run): RedirectResponse
    {
        $this->assertActionable($run);
        abort_unless($run->status === 'calculated', 422);
        if (Payslip::where('payroll_run_id', $run->id)->whereNotNull('error')->exists()) {
            throw ValidationException::withMessages(['run' => 'Masih ada slip yang gagal dihitung. Lengkapi datanya lalu hitung ulang.']);
        }
        $run->update(['status' => 'submitted', 'submitted_by' => $request->user()->id, 'submitted_at' => now(), 'rejection_note' => null]);
        Audit::log('payroll.run_submitted', $run, $run->label(), ['take_home_pay' => $run->totals['all']['take_home_pay'] ?? null]);
        Inertia::flash('success', 'Proses gaji diajukan untuk disetujui.');

        return back();
    }

    public function approve(Request $request, PayrollRun $run): RedirectResponse
    {
        $this->assertActionable($run);
        abort_unless($run->status === 'submitted', 422);
        abort_unless($this->mayApprove($run, $request->user()), 403, 'Proses gaji tidak bisa disetujui oleh yang menghitung atau mengajukannya.');
        $run->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
        PayrollRunner::settle($run);
        Audit::log('payroll.run_approved', $run, $run->label(), ['take_home_pay' => $run->totals['all']['take_home_pay'] ?? null]);
        Inertia::flash('success', 'Proses gaji disetujui. Slip sudah terlihat di portal karyawan.');

        return back();
    }

    public function reject(Request $request, PayrollRun $run): RedirectResponse
    {
        $this->assertActionable($run);
        abort_unless($run->status === 'submitted', 422);
        $note = $request->validate(['note' => ['required', 'string', 'max:500']])['note'];
        $run->update(['status' => 'calculated', 'rejection_note' => $note, 'submitted_by' => null, 'submitted_at' => null]);
        Audit::log('payroll.run_rejected', $run, $run->label().': '.$note);
        Inertia::flash('success', 'Proses gaji dikembalikan untuk diperbaiki.');

        return back();
    }

    public function paid(Request $request, PayrollRun $run): RedirectResponse
    {
        $this->assertActionable($run);
        abort_unless($run->status === 'approved', 422);
        $data = $request->validate(['pay_date' => ['nullable', 'date_format:Y-m-d']]);
        $run->update(['status' => 'paid', 'paid_by' => $request->user()->id, 'paid_at' => now(), 'pay_date' => $data['pay_date'] ?? $run->pay_date ?? now()->toDateString()]);
        Audit::log('payroll.run_paid', $run, $run->label());
        Inertia::flash('success', 'Proses gaji ditandai sudah dibayar.');

        return back();
    }

    public function lock(Request $request, PayrollRun $run): RedirectResponse
    {
        $this->assertActionable($run);
        abort_unless($run->status === 'paid', 422);
        $run->update(['status' => 'locked', 'locked_by' => $request->user()->id, 'locked_at' => now()]);
        Audit::log('payroll.run_locked', $run, $run->label());
        Inertia::flash('success', 'Proses gaji dikunci. Koreksi berikutnya masuk ke periode selanjutnya.');

        return back();
    }

    public function destroy(PayrollRun $run): RedirectResponse
    {
        $this->assertActionable($run);
        abort_unless(in_array($run->status, ['draft', 'calculated'], true), 422, 'Hanya proses gaji yang belum diajukan yang bisa dihapus.');
        Audit::log('payroll.run_deleted', $run, $run->label());
        $run->delete();
        Inertia::flash('success', 'Proses gaji dihapus.');

        return redirect('/compensation/payroll');
    }

    /** One slip in full, for the drawer. */
    public function slip(PayrollRun $run, Payslip $payslip): JsonResponse
    {
        $this->assertSlip($run, $payslip);
        Audit::log('payroll.slip_viewed', $payslip, "{$payslip->employee_number} {$payslip->name} · {$run->period}");

        return response()->json(self::slipData($payslip));
    }

    public function pdf(PayrollRun $run, Payslip $payslip): HttpResponse
    {
        $this->assertSlip($run, $payslip);
        Audit::log('payroll.slip_downloaded', $payslip, "{$payslip->employee_number} {$payslip->name} · {$run->period}");

        return PayslipPdf::download($payslip, protect: false);
    }

    /** Rekap: one row per employee with every component, plus totals per location. */
    public function export(PayrollRun $run): HttpResponse
    {
        $this->assertVisible($run);
        $ids = AccessScope::workLocationIds();
        $slips = Payslip::with('lines')->where('payroll_run_id', $run->id)
            ->when($ids !== null, fn ($q) => $q->whereIn('work_location_id', $ids))->orderBy('employee_number')->get();
        $components = PayslipLine::whereIn('payslip_id', $slips->pluck('id'))
            ->selectRaw('kind, code, min(label) as label, min(sort) as sort')->groupBy('kind', 'code')->get()
            ->sortBy(fn ($c) => [array_search($c->kind, array_keys(PayslipLine::KINDS), true), $c->sort])->values();
        $amount = fn (Payslip $s, $c) => (int) $s->lines->where('kind', $c->kind)->where('code', $c->code)->sum('amount');
        $info = TableExcel::info(['Proses gaji '.$run->label(), PayrollRun::STATUS[$run->status]]);
        Audit::log('payroll.export', $run, $run->label(), ['slips' => $slips->count()]);

        $perLocation = $slips->groupBy(fn ($s) => $s->snapshot['location'] ?? '-');

        return TableExcel::download("payroll-{$run->period}-".($run->workLocation?->code ?? 'all').'.xlsx', [
            [
                'title' => 'Payroll Recap', 'info' => $info, 'freeze' => 4,
                'columns' => [
                    ['No', 5, 'int'], ['NRP', 12, 'text'], ['Name', 26], ['Position', 26], ['Department', 18], ['Location', 18], ['PTKP', 7], ['Tax Method', 10],
                    ['Bank', 10], ['Account Number', 18, 'text'],
                    ...$components->map(fn ($c) => [$c->label, 14, 'rupiah', 'sum' => true])->all(),
                    ['Total Earnings', 15, 'rupiah', 'sum' => true], ['Total Deductions', 15, 'rupiah', 'sum' => true], ['PPh21', 13, 'rupiah', 'sum' => true],
                    ['Take Home Pay', 15, 'rupiah', 'sum' => true], ['Company Cost', 15, 'rupiah', 'sum' => true], ['Notes', 40],
                ],
                'rows' => $slips->values()->map(fn (Payslip $s, int $i) => [
                    $i + 1, $s->employee_number, $s->name, $s->snapshot['position'] ?? null, $s->snapshot['department'] ?? null, $s->snapshot['location'] ?? null,
                    $s->snapshot['ptkp'] ?? null, $s->snapshot['tax_method'] ?? null, $s->snapshot['bank_name'] ?? null, $s->snapshot['bank_account_number'] ?? null,
                    ...$components->map(fn ($c) => $amount($s, $c))->all(),
                    $s->total_earnings, $s->total_deductions, $s->tax, $s->take_home_pay, $s->company_cost,
                    $s->error ?? implode(' ', $s->warnings ?? []),
                ])->all(),
            ],
            [
                'title' => 'Per Location', 'info' => $info,
                'columns' => [
                    ['Location', 24], ['Employees', 10, 'int', 'sum' => true], ['Total Earnings', 16, 'rupiah', 'sum' => true], ['Total Deductions', 16, 'rupiah', 'sum' => true],
                    ['PPh21', 14, 'rupiah', 'sum' => true], ['Take Home Pay', 16, 'rupiah', 'sum' => true], ['Company Cost', 16, 'rupiah', 'sum' => true],
                ],
                'rows' => $perLocation->map(fn ($rows, $loc) => [
                    $loc, $rows->count(), (int) $rows->sum('total_earnings'), (int) $rows->sum('total_deductions'), (int) $rows->sum('tax'),
                    (int) $rows->sum('take_home_pay'), (int) $rows->sum('company_cost'),
                ])->values()->all(),
            ],
        ]);
    }

    public static function share(PayrollRun $r): array
    {
        return [
            'id' => $r->id, 'period' => $r->period, 'label' => $r->periodOf()->label(), 'range' => $r->periodOf()->range(),
            'type' => $r->type, 'work_location_id' => $r->work_location_id, 'location' => $r->workLocation?->name ?? 'Semua lokasi',
            'status' => $r->status, 'status_label' => PayrollRun::STATUS[$r->status] ?? $r->status,
            'employee_count' => $r->employee_count, 'progress' => $r->progress, 'pay_date' => $r->pay_date?->toDateString(),
            'take_home_pay' => $r->totals['all']['take_home_pay'] ?? null, 'errors' => $r->totals['all']['errors'] ?? 0,
            'notes' => $r->notes, 'rejection_note' => $r->rejection_note, 'created_by' => $r->creator?->name,
            'created_at' => $r->created_at?->toDateTimeString(),
        ];
    }

    /** Who did each step and when. */
    private static function steps(PayrollRun $r): array
    {
        $names = User::whereIn('id', array_filter([$r->created_by, $r->calculated_by, $r->submitted_by, $r->approved_by, $r->paid_by, $r->locked_by]))->pluck('name', 'id');

        return array_values(array_filter(array_map(fn (array $s) => $s[2] ? ['label' => $s[0], 'by' => $names[$s[1]] ?? null, 'at' => $s[2]->toDateTimeString()] : null, [
            ['Dibuat', $r->created_by, $r->created_at],
            ['Dihitung', $r->calculated_by, $r->calculated_at],
            ['Diajukan', $r->submitted_by, $r->submitted_at],
            ['Disetujui', $r->approved_by, $r->approved_at],
            ['Dibayar', $r->paid_by, $r->paid_at],
            ['Dikunci', $r->locked_by, $r->locked_at],
        ])));
    }

    public static function slipData(Payslip $s): array
    {
        $d = $s->detail ?? [];

        return [
            'id' => $s->id,
            'employee' => ['id' => $s->employee_id, 'employee_number' => $s->employee_number, 'name' => $s->name,
                'position' => $s->snapshot['position'] ?? null, 'job_level' => $s->snapshot['job_level'] ?? null, 'location' => $s->snapshot['location'] ?? null],
            'period' => $d['period'] ?? null,
            'ptkp' => $s->snapshot['ptkp'] ?? null, 'ter_category' => $s->snapshot['ter_category'] ?? null, 'has_tax_id' => $s->snapshot['has_tax_id'] ?? true,
            'tax_method' => $s->snapshot['tax_method'] ?? null, 'mode' => $s->snapshot['mode'] ?? 'ter',
            'bank' => array_filter([$s->snapshot['bank_name'] ?? null, $s->snapshot['bank_account_number'] ?? null, $s->snapshot['bank_account_holder'] ?? null]),
            'warnings' => $s->warnings ?? [], 'error' => $s->error,
            'earnings' => $d['earnings'] ?? [], 'deductions' => $d['deductions'] ?? [], 'company' => $d['company'] ?? [], 'tax_steps' => $d['tax_steps'] ?? [],
            'absence' => $d['absence'] ?? null, 'overtime' => $d['overtime'] ?? null,
            'wage' => $s->wage, 'gross' => $s->gross, 'tax' => $s->tax,
            'total_earnings' => $s->total_earnings, 'total_deductions' => $s->total_deductions, 'take_home_pay' => $s->take_home_pay,
        ];
    }

    private function inScope(PayrollRun $run): bool
    {
        $ids = AccessScope::workLocationIds();

        return $ids === null || ($run->work_location_id !== null && in_array($run->work_location_id, $ids, true));
    }

    private function mayApprove(PayrollRun $run, User $user): bool
    {
        return $user->isAdmin() || ! in_array($user->id, array_filter([$run->calculated_by, $run->submitted_by]), true);
    }

    private function assertVisible(PayrollRun $run): void
    {
        $ids = AccessScope::workLocationIds();
        abort_unless($ids === null || $run->work_location_id === null || in_array($run->work_location_id, $ids, true), 404);
    }

    private function assertActionable(PayrollRun $run): void
    {
        $this->assertVisible($run);
        abort_unless($this->inScope($run), 403, 'Proses gaji ini mencakup lokasi di luar akses akun Anda.');
    }

    private function assertSlip(PayrollRun $run, Payslip $payslip): void
    {
        $this->assertVisible($run);
        abort_unless($payslip->payroll_run_id === $run->id, 404);
        $ids = AccessScope::workLocationIds();
        abort_unless($ids === null || in_array($payslip->work_location_id, $ids, true), 404);
    }
}
