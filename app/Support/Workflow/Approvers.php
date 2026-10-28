<?php

namespace App\Support\Workflow;

use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Support\Notifications\RequestNotifier;
use Illuminate\Support\Collection;

/**
 * Who approves a step for an employee. A position nobody active holds (or held only by the
 * employee who asks) passes the step up to the next superior; at the top of the chart with
 * still nobody, the step falls to HR: the accounts with "ubah" on the pengajuan's menu and the
 * employee's location, as before Workflow. Never the employee who asks.
 */
class Approvers
{
    public const SUPERIOR = 'superior';

    public const POSITION = 'position';

    public const MENU = 'menu';

    /** approver_value: superior = levels up (1 = atasan langsung), position = position id, menu = menu key. */
    public const TYPES = [
        self::SUPERIOR => 'Atasan (1 = atasan langsung, 2 = atasan dari atasan, …)',
        self::POSITION => 'Jabatan tertentu',
        self::MENU => 'HR dengan akses menu di lokasi karyawan',
    ];

    /** @return Collection<int, User> */
    public static function forStep(WorkflowStep $step, Employee $employee, string $hrMenu): Collection
    {
        $users = match ($step->approver_type) {
            self::SUPERIOR => self::fromSuperior($employee, max(1, (int) ($step->approver_value ?: 1))),
            self::POSITION => self::fromPosition(Position::find((int) $step->approver_value), $employee),
            self::MENU => self::hr($step->approver_value ?: $hrMenu, $employee),
            default => collect(),
        };
        if ($users->isEmpty()) {
            $users = self::hr($hrMenu, $employee);
        }
        if ($users->isEmpty()) {
            $users = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->get();
        }

        return $users->reject(fn (User $u) => self::isRequester($u, $employee))->values();
    }

    /** The superior $levels up from the employee's position, then on up while nobody holds it. */
    private static function fromSuperior(Employee $employee, int $levels): Collection
    {
        $position = Position::find($employee->position_id)?->superior();
        for ($i = 1; $position && $i < $levels; $i++) {
            $position = $position->superior();
        }

        return self::fromPosition($position, $employee);
    }

    private static function fromPosition(?Position $position, Employee $employee): Collection
    {
        $seen = [];
        while ($position && ! isset($seen[$position->id])) {
            $seen[$position->id] = true;
            $users = self::holders($position)->reject(fn (User $u) => self::isRequester($u, $employee));
            if ($users->isNotEmpty()) {
                return $users->values();
            }
            $position = $position->superior();
        }

        return collect();
    }

    /** Active accounts of the active employees in a position (whatever the request's access scope). */
    public static function holders(Position $position): Collection
    {
        $ids = Employee::withoutGlobalScopes()->where('position_id', $position->id)->where('status', Employee::STATUS_ACTIVE)->pluck('id');

        return $ids->isEmpty() ? collect() : User::where('is_active', true)->whereIn('employee_id', $ids)->get();
    }

    private static function hr(string $menu, Employee $employee): Collection
    {
        return RequestNotifier::recipients($menu, $employee)->reject(fn (User $u) => self::isRequester($u, $employee))->values();
    }

    private static function isRequester(User $u, Employee $employee): bool
    {
        return $u->employee_id !== null && (int) $u->employee_id === (int) $employee->id;
    }
}
