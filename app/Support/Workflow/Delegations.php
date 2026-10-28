<?php

namespace App\Support\Workflow;

use App\Models\ApprovalDelegation;
use App\Models\LeaveTravelRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Which approver substitutes apply today (Workflow decides and lists with them): a delegation
 * for a range of dates applies inside it, one "on leave" on the days the approver is on an
 * approved leave. One level only: a delegate's own delegate does not step in for the approver.
 */
class Delegations
{
    /** @return list<int> the approvers $delegate stands in for today */
    public static function principalsOf(User $delegate, ?CarbonImmutable $day = null): array
    {
        $rows = ApprovalDelegation::where('delegate_id', $delegate->id)->where('is_active', true)->get();

        return self::applying($rows, $day)->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @return array<int, list<int>> approver id => the delegates standing in for them today */
    public static function delegatesOf(array $userIds, ?CarbonImmutable $day = null): array
    {
        if (! $userIds) {
            return [];
        }
        $rows = ApprovalDelegation::whereIn('user_id', $userIds)->where('is_active', true)->get();

        return self::applying($rows, $day)->groupBy('user_id')
            ->map(fn (Collection $g) => $g->pluck('delegate_id')->map(fn ($id) => (int) $id)->unique()->values()->all())->all();
    }

    /** The delegations among $rows that apply on $day. */
    private static function applying(Collection $rows, ?CarbonImmutable $day): Collection
    {
        $day ??= CarbonImmutable::today();
        $onLeave = $rows->contains('mode', ApprovalDelegation::ON_LEAVE)
            ? self::onLeave($rows->where('mode', ApprovalDelegation::ON_LEAVE)->pluck('user_id')->all(), $day)
            : [];

        return $rows->filter(fn (ApprovalDelegation $d) => $d->mode === ApprovalDelegation::ON_LEAVE
            ? in_array((int) $d->user_id, $onLeave, true)
            : $d->starts_on && $d->ends_on && $d->starts_on->lte($day) && $d->ends_on->gte($day));
    }

    /** @return list<int> of these accounts, those whose employee is on approved leave on $day */
    public static function onLeave(array $userIds, CarbonImmutable $day): array
    {
        $employees = User::whereIn('id', $userIds)->whereNotNull('employee_id')->pluck('id', 'employee_id');
        if ($employees->isEmpty()) {
            return [];
        }
        $away = LeaveTravelRequest::withoutGlobalScopes()->where('type', 'leave')->where('status', 'approved')
            ->whereIn('employee_id', $employees->keys())
            ->whereDate('start_date', '<=', $day->toDateString())->whereDate('end_date', '>=', $day->toDateString())
            ->pluck('employee_id');

        return $away->map(fn ($e) => (int) $employees[$e])->unique()->values()->all();
    }
}
