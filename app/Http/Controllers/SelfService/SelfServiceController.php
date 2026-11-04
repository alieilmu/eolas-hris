<?php

namespace App\Http\Controllers\SelfService;

use App\Http\Controllers\Time\LeaveTravelController;
use App\Http\Controllers\Compensation\BenefitClaimController;
use App\Http\Controllers\Controller;
use App\Models\LeavePolicy;
use App\Models\LongServiceLeave;
use App\Models\BenefitClaim;
use App\Models\Employee;
use App\Models\EmployeeChangeRequest;
use App\Models\LeaveTravelRequest;
use App\Models\AttendanceLog;
use App\Models\AttendanceRecord;
use App\Models\OvertimeRecord;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Support\TimeClock\MobileClock;
use App\Support\TimeClock\OvertimeRequest;
use App\Support\Time\Airport;
use App\Support\Time\LeaveTravelWriter;
use App\Support\Compensation\AccountingMonth;
use App\Support\Compensation\Usage;
use App\Support\Leave\LeaveLedger;
use App\Support\Attachments\Attachments;
use App\Support\Notifications\RequestNotifier;
use App\Support\Workflow\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal Karyawan: an employee's own plafon, and their pengajuan — deklarasi
 * pengobatan / kacamata, cuti, dinas and lembur — which wait in their approval flow.
 */
class SelfServiceController extends Controller
{
    private const RELATIONS = ['position', 'jobLevel', 'department', 'section.department', 'workLocation', 'profile.children'];

    private function me(Request $request): Employee
    {
        return Employee::with(self::RELATIONS)->findOrFail($request->user()->employee_id);
    }

    public function home(Request $request): Response
    {
        $k = $this->me($request);
        $today = CarbonImmutable::today();
        $month = AccountingMonth::of($today);
        $trips = LeaveTravelRequest::with(LeaveTravelController::WITH)->where('employee_id', $k->id)->latest('start_date')->limit(20)->get();
        $flows = Workflow::pendingOf(LeaveTravelRequest::class, $trips->pluck('id'));
        $claims = BenefitClaim::with(['employee.position', 'items.children', 'attachments'])->where('employee_id', $k->id)->latest('date')->latest('id')->limit(20)->get();
        $claimFlows = Workflow::pendingOf(BenefitClaim::class, $claims->pluck('id'));
        $overtime = OvertimeRecord::where('employee_id', $k->id)->where('date', '>=', $today->subDays(60)->toDateString())->latest('date')->limit(20)->get();
        $overtimeFlows = Workflow::pendingOf(OvertimeRecord::class, $overtime->pluck('id'));

        return Inertia::render('SelfService/Home', [
            'employee' => BenefitClaimController::identity($k),
            'benefit_limit' => Usage::summary($k, AccountingMonth::year($month)),
            'accounting_month' => ['value' => $month, 'label' => AccountingMonth::label($month), 'span' => AccountingMonth::span($month)],
            'today' => $today->toDateString(),
            'benefit_claims' => $claims->map(fn (BenefitClaim $d) => BenefitClaimController::row($d) + ['workflow' => Workflow::brief($claimFlows->get($d->id), $request->user())]),
            'leave_travel_requests' => $trips->map(fn (LeaveTravelRequest $t) => [...LeaveTravelController::tripData($t, $today),
                // Where it waits in its approval flow.
                'workflow' => Workflow::brief($flows->get($t->id), $request->user())]),
            'cities' => LeaveTravelRequest::query()->select('origin_city as city')->union(LeaveTravelRequest::query()->select('destination_city as city'))->pluck('city')->unique()->sort()->values(),
            'provider_options' => BenefitClaim::whereNotNull('provider_name')->distinct()->orderBy('provider_name')->pluck('provider_name'),
            'airport' => Airport::options(),
            'attachment_limits' => Attachments::limits(),
            'data_change_request' => EmployeeChangeRequest::where('employee_id', $k->id)->where('status', 'pending')->exists(),
            'leave' => LeaveLedger::info($k),
            'leave_policy' => LeavePolicy::current()->only(['long_service_days', 'long_service_taken_salary_multiplier', 'long_service_cashout_salary_multiplier']),
            'attendance_today' => $this->attendanceToday($k, $today),
            // Absen HP: whether the button shows, the areas, consent and the next punch.
            'mobile_clock' => MobileClock::state($k),
            'mobile_consent' => MobileClock::consented($k) ? null : MobileClock::consent(),
            'latest_payslip' => $this->latestPayslip($k),
            // Lembur: own overtime of the last 60 days (machines and asked for here), and the form.
            'overtime_records' => $overtime->map(fn (OvertimeRecord $o) => [
                'id' => $o->id, 'date' => $o->date->toDateString(), 'summary' => $o->summary(),
                'start' => $o->start_date?->format('H:i'), 'end' => $o->end_date?->format('H:i'),
                'status' => $o->status, 'source' => $o->source, 'reason' => $o->source === 'portal' ? $o->notes : null,
                'verification_notes' => $o->verification_notes, 'workflow' => Workflow::brief($overtimeFlows->get($o->id), $request->user()),
            ]),
            'overtime_form' => OvertimeRequest::form($k),
        ]);
    }

    /** Today's punches (machine or entered by HR) and the planned shift, for the top of Beranda. */
    private function attendanceToday(Employee $k, CarbonImmutable $today): array
    {
        $punches = AttendanceLog::where('employee_id', $k->id)->whereBetween('punched_at', [$today->startOfDay(), $today->endOfDay()])
            ->orderBy('punched_at')->pluck('punched_at');
        $shift = AttendanceRecord::with('shiftCode')->where('employee_id', $k->id)->whereDate('date', $today)->first()?->shiftCode;

        return [
            'in' => $punches->first()?->format('H:i'),
            'out' => $punches->count() > 1 ? $punches->last()->format('H:i') : null,
            'shift' => $shift ? trim($shift->code.($shift->start_time && $shift->end_time ? ' · '.substr($shift->start_time, 0, 5).'–'.substr($shift->end_time, 0, 5) : '')) : null,
        ];
    }

    /** The newest slip of an approved payroll run (as Slip Gaji shows them). */
    private function latestPayslip(Employee $k): ?array
    {
        $slip = Payslip::with('run')->where('employee_id', $k->id)->whereNull('error')
            ->whereHas('run', fn ($q) => $q->whereIn('status', PayrollRun::FINAL))->get()
            ->sortByDesc(fn (Payslip $s) => $s->run->period.'|'.$s->id)->first();

        return $slip ? [
            'id' => $slip->id,
            'label' => $slip->run->type === 'regular' || ! $slip->run->type ? $slip->run->periodOf()->label() : ($slip->run->type === 'thr' ? 'THR' : 'Final pay'),
            'take_home_pay' => $slip->take_home_pay,
            'pay_date' => $slip->run->pay_date?->toDateString(),
        ] : null;
    }

    /** Own leave balance, for the cuti form. */
    public function leaveInfo(Request $request): JsonResponse
    {
        return response()->json(LeaveLedger::info($this->me($request), $request->integer('except') ?: null));
    }

    /** The employee chooses for a Cuti Besar reached: take the days or cash it in; HR confirms. */
    public function chooseLongService(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sequence_no' => ['required', 'integer', 'min:1', 'max:20'],
            'decision' => ['required', Rule::in(array_keys(LongServiceLeave::KEPUTUSAN))],
        ]);
        $k = $this->me($request);
        $period = collect(LeaveLedger::summary($k)['long_service']['period'])->firstWhere('sequence_no', $data['sequence_no']);
        if (! $period || $period['status'] !== 'not_chosen') {
            throw ValidationException::withMessages(['decision' => 'Cuti Besar ini tidak bisa dipilih lagi (sudah dipilih, menunggu HR, atau lewat batas).']);
        }
        $long_service = LongServiceLeave::create([...$data, 'employee_id' => $k->id, 'status' => 'pending', 'source' => 'self_service']);
        RequestNotifier::longServiceLeave($long_service);
        Inertia::flash('success', 'Pilihan Cuti Besar ('.LongServiceLeave::KEPUTUSAN[$data['decision']].') terkirim ke HR.');

        return back();
    }

    /** Own plafon on a date (the deklarasi form asks as the date changes). */
    public function benefitLimit(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $k = $this->me($request);
        $month = AccountingMonth::of(CarbonImmutable::parse($data['date'] ?? CarbonImmutable::today()->toDateString()));

        return response()->json([
            'employee' => BenefitClaimController::identity($k),
            'accounting_month' => ['value' => $month, 'label' => AccountingMonth::label($month), 'span' => AccountingMonth::span($month)],
            ...Usage::summary($k, AccountingMonth::year($month)),
        ]);
    }

    public function storeClaim(Request $request): RedirectResponse
    {
        // An employee declares on the day they send it; only HR enters a deklarasi of another date.
        $request->merge(['employee_id' => $request->user()->employee_id, 'date' => CarbonImmutable::today()->toDateString()]);
        [, $data, $items] = BenefitClaimController::validateClaim($request);
        $claim = DB::transaction(function () use ($data, $items, $request) {
            $claim = BenefitClaim::create([
                ...$data,
                'claim_number' => BenefitClaimController::nextClaimNumber($data['accounting_month']),
                'created_by' => $request->user()->id,
                'status' => 'pending',
                'source' => 'self_service',
            ]);
            $claim->items()->createMany($items);
            Attachments::sync($request, $claim, 'claim');
            // Its approval flow (by default HR of the location, as before); the first approvers are notified.
            $notice = $claim->workflowNotice();
            Workflow::start($claim, 'claim', $claim->employee, $request->user(), $claim->workflowFacts(), "{$notice['title']} {$notice['body']}", $notice['url']);

            return $claim;
        });
        Inertia::flash('success', "Deklarasi {$claim->claim_number} terkirim dan menunggu persetujuan. Nominalnya sudah ditahan dari sisa plafon.");

        return back();
    }

    public function storeTrip(Request $request): RedirectResponse
    {
        $request->merge(['employee_id' => $request->user()->employee_id]);
        $data = LeaveTravelController::validateTrip($request);
        LeaveTravelWriter::assertFree($data['employee_id'], $data['start_date'], $data['end_date']);
        LeaveTravelWriter::codeFor($data['type']);
        $leave_travel = DB::transaction(function () use ($data, $request) {
            $leave_travel = LeaveTravelRequest::create([
                ...$data,
                'cash_advance_status' => LeaveTravelController::initialCashAdvanceStatus($data['needs_cash_advance']),
                'created_by' => $request->user()->id,
                'status' => 'pending',
                'source' => 'self_service',
            ]);
            Attachments::sync($request, $leave_travel, 'leave_travel');
            // Its approval flow (by default HR of the location, as before); the first approvers are notified.
            Workflow::start($leave_travel, $leave_travel->type, $leave_travel->employee, $request->user(), $leave_travel->workflowFacts(),
                $leave_travel->workflowNotice()['body'], $leave_travel->workflowNotice()['url']);

            return $leave_travel;
        });
        Inertia::flash('success', 'Pengajuan '.LeaveTravelRequest::JENIS[$leave_travel->type]." ke {$leave_travel->destination_city} terkirim dan menunggu persetujuan.");

        return back();
    }

    /** Pengajuan Lembur: overtime the machines did not count, with its hours and reason. */
    public function storeOvertime(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['required', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:500'],
        ], ['reason.required' => 'Tulis pekerjaan yang dilemburkan.'], ['date' => 'Tanggal', 'start' => 'Jam mulai', 'end' => 'Jam selesai']);
        $record = OvertimeRequest::submit($this->me($request), $request->user(), $data);
        Inertia::flash('success', "Pengajuan lembur {$record->summary()} terkirim dan menunggu persetujuan.");

        return back();
    }

    /** Withdraws an own pengajuan that HR has not decided on yet. */
    public function cancel(Request $request, string $type, int $id): RedirectResponse
    {
        $model = match ($type) {
            'claim' => BenefitClaim::class,
            'overtime' => OvertimeRecord::class,
            default => LeaveTravelRequest::class,
        };
        $item = $model::where('employee_id', $request->user()->employee_id)->where('status', 'pending')
            // Overtime counted from the machines is not the employee's to withdraw.
            ->when($type === 'overtime', fn ($q) => $q->where('source', 'portal'))->findOrFail($id);
        // In a Workflow: withdrawn there (which removes it); from before Workflow: removed here.
        $flow = Workflow::of($item);
        $flow?->isPending() ? Workflow::cancel($flow, $request->user()) : $item->delete();
        Inertia::flash('success', 'Pengajuan dibatalkan.');

        return back();
    }
}
