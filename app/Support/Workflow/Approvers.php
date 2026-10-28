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

    /** @var array<string, Collection>|null answers kept while many pengajuan start at once (Workflow::quietly) */
    private static ?array $memo = null;

    public static function remember(bool $on): void
    {
        self::$memo = $on ? [] : null;
    }

    private static function memo(string $key, callable $find): Collection
    {
        if (self::$memo === null) {
            return $find();
        }

        return self::$memo[$key] ??= $find();
    }

    /** @return Collection<int, User> */
    public static function forStep(WorkflowStep $step, Employee $employee, string $hrMenu): Collection
    {
        return self::explain($step, $employee, $hrMenu)['users'];
    }

    /**
     * forStep() with how it got there, for the flow editor's simulation and coverage check:
     * `fallback` is null when the step's own approver was found, "up" when a vacant position
     * passed it to a higher one, "hr" / "admin" when nobody in the chart could (the reason in
     * `note`).
     *
     * @return array{users: Collection<int, User>, fallback: ?string, note: ?string}
     */
    public static function explain(WorkflowStep $step, Employee $employee, string $hrMenu): array
    {
        $note = null;
        $from = null;
        $users = match ($step->approver_type) {
            self::SUPERIOR => self::fromSuperior($employee, max(1, (int) ($step->approver_value ?: 1)), $note, $from),
            self::POSITION => self::fromPosition(Position::find((int) $step->approver_value), $employee, $from),
            self::MENU => self::hr($step->approver_value ?: $hrMenu, $employee),
            default => collect(),
        };
        $fallback = null;
        if ($users->isNotEmpty() && $from) {
            $fallback = 'up';
            $note = $from;
        }
        if ($users->isEmpty()) {
            $note ??= match ($step->approver_type) {
                self::POSITION => ($p = Position::find((int) $step->approver_value)) ? "Jabatan {$p->name} dan atasannya tidak dipegang karyawan aktif yang punya akun." : 'Jabatan penyetuju sudah dihapus.',
                self::MENU => 'Tidak ada admin dengan akses "ubah" di menu ini untuk lokasi karyawan.',
                default => 'Tidak ada atasan yang punya akun di struktur organisasi.',
            };
            $users = self::hr($hrMenu, $employee);
            $fallback = 'hr';
        }
        if ($users->isEmpty()) {
            $users = self::memo('admins', fn () => User::where('role', User::ROLE_ADMIN)->where('is_active', true)->get());
            $fallback = 'admin';
        }

        return ['users' => $users->reject(fn (User $u) => self::isRequester($u, $employee))->values(), 'fallback' => $fallback, 'note' => $note];
    }

    /**
     * Above an approver (a late step escalates): the holders of the superior position of the
     * approver's own employee, on up while nobody holds it; none for an account without one.
     */
    public static function above(User $approver, Employee $employee): Collection
    {
        $own = $approver->employee_id ? Employee::withoutGlobalScopes()->find($approver->employee_id) : null;
        $position = $own && $own->position_id ? self::position($own->position_id)?->superior() : null;

        return self::fromPosition($position, $employee)->reject(fn (User $u) => $u->id === $approver->id)->values();
    }

    /** The superior $levels up from the employee's position, then on up while nobody holds it. */
    private static function fromSuperior(Employee $employee, int $levels, ?string &$note = null, ?string &$from = null): Collection
    {
        $own = $employee->position_id ? self::position($employee->position_id) : null;
        if (! $own) {
            $note = 'Karyawan belum ditempatkan di jabatan.';

            return collect();
        }
        $position = self::superiorOf($own);
        for ($i = 1; $position && $i < $levels; $i++) {
            $position = self::superiorOf($position);
        }
        if (! $position) {
            $note = "Jabatan {$own->name} belum punya atasan sejauh {$levels} tingkat di struktur organisasi.";

            return collect();
        }

        return self::fromPosition($position, $employee, $from);
    }

    /** The holders of $position, or of the first position above it someone holds ($from says which were skipped). */
    private static function fromPosition(?Position $position, Employee $employee, ?string &$from = null): Collection
    {
        $seen = [];
        $start = $position;
        while ($position && ! isset($seen[$position->id])) {
            $seen[$position->id] = true;
            $users = self::holders($position)->reject(fn (User $u) => self::isRequester($u, $employee));
            if ($users->isNotEmpty()) {
                if ($position->id !== $start->id) {
                    $from = "{$start->name} kosong (atau tanpa akun); diteruskan ke {$position->name}.";
                }

                return $users->values();
            }
            $position = self::superiorOf($position);
        }

        return collect();
    }

    private static function position(int $id): ?Position
    {
        return self::$memo === null ? Position::find($id) : (self::$memo["p:{$id}"] ??= [Position::find($id)])[0];
    }

    private static function superiorOf(Position $position): ?Position
    {
        return self::$memo === null ? $position->superior() : (self::$memo["s:{$position->id}"] ??= [$position->superior()])[0];
    }

    /** Active accounts of the active employees in a position (whatever the request's access scope). */
    public static function holders(Position $position): Collection
    {
        return self::memo("position:{$position->id}", function () use ($position) {
            $ids = Employee::withoutGlobalScopes()->where('position_id', $position->id)->where('status', Employee::STATUS_ACTIVE)->pluck('id');

            return $ids->isEmpty() ? collect() : User::where('is_active', true)->whereIn('employee_id', $ids)->get();
        });
    }

    private static function hr(string $menu, Employee $employee): Collection
    {
        return self::memo("hr:{$menu}:{$employee->work_location_id}:{$employee->sub_location_id}", fn () => RequestNotifier::recipients($menu, $employee))->reject(fn (User $u) => self::isRequester($u, $employee))->values();
    }

    private static function isRequester(User $u, Employee $employee): bool
    {
        return $u->employee_id !== null && (int) $u->employee_id === (int) $employee->id;
    }
}
