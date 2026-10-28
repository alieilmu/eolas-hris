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
 * pengobatan / kacamata, cuti and dinas — which wait for HR to approve them.
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
        ]);
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

    /** Withdraws an own pengajuan that HR has not decided on yet. */
    public function cancel(Request $request, string $type, int $id): RedirectResponse
    {
        $model = $type === 'claim' ? BenefitClaim::class : LeaveTravelRequest::class;
        $item = $model::where('employee_id', $request->user()->employee_id)->where('status', 'pending')->findOrFail($id);
        // In a Workflow: withdrawn there (which removes it); from before Workflow: removed here.
        $flow = Workflow::of($item);
        $flow?->isPending() ? Workflow::cancel($flow, $request->user()) : $item->delete();
        Inertia::flash('success', 'Pengajuan dibatalkan.');

        return back();
    }
}
