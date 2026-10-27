<?php

namespace App\Support\Payroll;

use App\Models\BenefitClaim;
use App\Models\Employee;
use App\Models\LeavePolicy;
use App\Models\LeaveTravelRequest;
use App\Models\LongServiceLeave;
use App\Models\PayrollComponent;
use App\Models\PayrollInput;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What other modules owe through payroll, pulled into Input Variabel for one period (each once,
 * by source_ref), waiting for approval like any input:
 *  - Deklarasi Benefit approved in that accounting month: reimbursement (taxable, as decided);
 *  - Cuti Besar verified in the period: cashed out (× gaji pokok) or the allowance for taking it;
 *  - Cuti Lapangan approved and starting in the period: its lumpsum.
 */
class PayrollSources
{
    /** @return array{claims: int, long_service: int, field_leave: int, skipped: int} */
    public static function pull(PayrollPeriod $p, int $userId): array
    {
        $code = $p->code();
        $components = PayrollComponent::whereIn('code', ['reimbursement', 'long_service_pay', 'field_leave_allowance'])->pluck('id', 'code');
        $policy = LeavePolicy::query()->first();
        $rows = [];

        foreach (BenefitClaim::withoutGlobalScopes()->with('items')->where('status', 'approved')->where('accounting_month', $code)
            ->whereIn('employee_id', Employee::query()->select('id'))->get() as $c) {
            $rows[] = ['ref' => "claim:{$c->id}", 'employee_id' => $c->employee_id, 'component' => 'reimbursement',
                'amount' => (int) $c->items->sum('amount'), 'notes' => trim("Deklarasi {$c->claim_number} {$c->provider_name}")];
        }
        foreach (LongServiceLeave::withoutGlobalScopes()->with('employee')->where('status', 'approved')
            ->whereBetween('verified_at', [$p->start->startOfDay(), $p->end->endOfDay()])
            ->whereIn('employee_id', Employee::query()->select('id'))->get() as $l) {
            $multiplier = (float) ($l->decision === 'cashed_out' ? ($policy?->long_service_cashout_salary_multiplier ?? 2) : ($policy?->long_service_taken_salary_multiplier ?? 1));
            $base = $l->employee ? Salary::base($l->employee, CarbonImmutable::parse($l->verified_at)) : null;
            if (! $base || $multiplier <= 0) {
                continue;
            }
            $rows[] = ['ref' => "long_service:{$l->id}", 'employee_id' => $l->employee_id, 'component' => 'long_service_pay',
                'amount' => (int) round($base * $multiplier), 'notes' => 'Cuti Besar ke-'.$l->sequence_no.' '.($l->decision === 'cashed_out' ? 'diuangkan' : 'diambil').' · '.rtrim(rtrim(number_format($multiplier, 2, ',', '.'), '0'), ',').' × gaji pokok'];
        }
        foreach (LeaveTravelRequest::withoutGlobalScopes()->where('status', 'approved')->where('type', 'leave')->where('field_leave_allowance', '>', 0)
            ->whereBetween('start_date', [$p->start->toDateString(), $p->end->toDateString()])
            ->whereIn('employee_id', Employee::query()->select('id'))->get() as $t) {
            $rows[] = ['ref' => "field_leave:{$t->id}", 'employee_id' => $t->employee_id, 'component' => 'field_leave_allowance',
                'amount' => (int) $t->field_leave_allowance, 'notes' => 'Cuti lapangan mulai '.CarbonImmutable::parse($t->start_date)->format('d.m.Y')];
        }

        $done = PayrollInput::whereIn('source_ref', array_column($rows, 'ref'))->pluck('source_ref')->flip();
        $count = ['claims' => 0, 'long_service' => 0, 'field_leave' => 0, 'skipped' => 0];
        DB::transaction(function () use ($rows, $done, $components, $code, $userId, &$count) {
            foreach ($rows as $r) {
                $employee = Employee::find($r['employee_id']);
                if (isset($done[$r['ref']]) || $r['amount'] <= 0 || ! $employee || ! isset($components[$r['component']]) || PayrollPeriods::closed($employee, $code)) {
                    $count['skipped']++;

                    continue;
                }
                PayrollInput::create([
                    'employee_id' => $r['employee_id'], 'period' => $code, 'payroll_component_id' => $components[$r['component']],
                    'amount' => $r['amount'], 'notes' => mb_substr($r['notes'], 0, 255), 'status' => 'pending', 'source' => 'auto',
                    'source_ref' => $r['ref'], 'created_by' => $userId,
                ]);
                $count[explode(':', $r['ref'])[0] === 'claim' ? 'claims' : (str_starts_with($r['ref'], 'long_service') ? 'long_service' : 'field_leave')]++;
            }
        });

        return $count;
    }
}
