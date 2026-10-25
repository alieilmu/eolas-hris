<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A position at one work location. It belongs to exactly one organisation unit
 * — a BOD, Divisi, Departemen or Section (one of the four unit columns is set)
 * — and its name is composed from its SubGroup (see JabatanComposer).
 */
#[Fillable([
    'job_level_id', 'directorate_id', 'division_id', 'department_id', 'section_id', 'work_location_id', 'sub_location_id', 'discipline',
    'parent_position_id', 'name', 'sort_order',
])]
class Position extends Model
{
    protected $table = 'positions';

    /** Unit level => column, top of the organisation first. */
    public const UNIT_COLUMNS = ['directorate' => 'directorate_id', 'division' => 'division_id', 'department' => 'department_id', 'section' => 'section_id'];

    public const UNIT_LABELS = ['directorate' => 'BOD', 'division' => 'Divisi', 'department' => 'Departemen', 'section' => 'Section'];

    /** A position not yet placed in the organisation chart (no unit column set). */
    public const NO_UNIT = 'none';

    /** SubGroups whose position names do not come from the unit: those positions can be placed anywhere in the chart. */
    public const FREE_SUMBER = ['discipline', 'work_location'];

    /** Eager-load these to read a position's unit and the units above it without extra queries. */
    public const UNIT_RELATIONS = ['directorate', 'division.directorate', 'department.division.directorate', 'section.department.division.directorate'];

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function jobLevel(): BelongsTo
    {
        return $this->belongsTo(JobLevel::class);
    }

    public function directorate(): BelongsTo
    {
        return $this->belongsTo(Directorate::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /** Set only when the position is attached to the departemen itself; see departemenOf(). */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** Set when the position is at a Sub Lokasi Kerja of its work location. */
    public function subLocation(): BelongsTo
    {
        return $this->belongsTo(SubLocation::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'parent_position_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Position::class, 'parent_position_id')->orderBy('sort_order');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    // --- the unit ------------------------------------------------------------------

    /** "bod" | "divisi" | "departemen" | "section", or "none" while not placed in the chart. */
    public function unitLevel(): string
    {
        foreach (array_reverse(self::UNIT_COLUMNS) as $level => $column) {
            if ($this->{$column}) {
                return $level;
            }
        }

        return self::NO_UNIT;
    }

    /** "section:5" (or "none") — how forms and the chart refer to a unit. */
    public function unitKey(): string
    {
        $level = $this->unitLevel();

        return $level === self::NO_UNIT ? self::NO_UNIT : $level.':'.$this->{self::UNIT_COLUMNS[$level]};
    }

    /**
     * Its name does not come from its unit (Group Leader X, staff, Project Manager
     * RMTU), so it can be placed under any departemen or section in the chart.
     */
    public function isFree(): bool
    {
        return in_array($this->jobLevel?->source, self::FREE_SUMBER, true);
    }

    /**
     * Why `$atasan` cannot be its superior: a superior may not be of a lower
     * layer (the same layer is fine, e.g. a Project Manager under a Manager).
     */
    public function superiorLayerError(Position $superior): ?string
    {
        $own = $this->jobLevel?->layer;
        $theirs = $superior->jobLevel?->layer;
        if ($own === null || $theirs === null || $theirs >= $own) {
            return null;
        }

        return "\"{$superior->name}\" (layer {$theirs}) tidak bisa menjadi atasan \"{$this->name}\" (layer {$own}): layer atasan tidak boleh di bawah bawahannya.";
    }

    /** @return array<string, int|null> the four unit columns as stored */
    public function unitColumnValues(): array
    {
        $values = [];
        foreach (self::UNIT_COLUMNS as $column) {
            $values[$column] = $this->{$column} === null ? null : (int) $this->{$column};
        }

        return $values;
    }

    public function unit(): Directorate|Division|Department|Section|null
    {
        $level = $this->unitLevel();

        return $level === self::NO_UNIT ? null : $this->{$level};
    }

    /** The departemen it belongs to, directly or through its section. */
    public function departmentOf(): ?Department
    {
        return $this->section?->department ?? $this->department;
    }

    public function divisionOf(): ?Division
    {
        return $this->departmentOf()?->division ?? $this->division;
    }

    public function directorateOf(): ?Directorate
    {
        return $this->divisionOf()?->directorate ?? $this->directorate;
    }

    /** e.g. "Section Workshop · Maintenance", "Divisi Operasional". */
    public function unitLabel(): string
    {
        if ($this->unitLevel() === self::NO_UNIT) {
            return 'Belum ditempatkan di SO';
        }
        $unit = $this->unit();
        $label = self::UNIT_LABELS[$this->unitLevel()].' '.($unit?->name ?? '-');

        return $unit instanceof Section ? "{$label} · {$unit->department->name}" : $label;
    }

    /**
     * Unit columns for a "level:id" key, or null when it names no existing unit.
     *
     * @return array<string, int|null>|null
     */
    public static function unitColumns(?string $key): ?array
    {
        if ($key === self::NO_UNIT) {
            return array_fill_keys(array_values(self::UNIT_COLUMNS), null);
        }
        [$level, $id] = array_pad(explode(':', (string) $key, 2), 2, null);
        $models = ['directorate' => Directorate::class, 'division' => Division::class, 'department' => Department::class, 'section' => Section::class];
        if (! isset($models[$level]) || ! ctype_digit((string) $id) || ! $models[$level]::whereKey($id)->exists()) {
            return null;
        }

        $columns = [];
        foreach (self::UNIT_COLUMNS as $unitLevel => $column) {
            $columns[$column] = $unitLevel === $level ? (int) $id : null;
        }

        return $columns;
    }

    /** @param  array<string, int|null>  $columns  as returned by unitColumns() */
    public function scopeInUnit(Builder $query, array $columns): void
    {
        foreach ($columns as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }
    }

    /**
     * Moves the position with everyone reporting to it into another unit
     * (reporting lines inside a unit, so the team goes along).
     *
     * @param  array<string, int|null>  $columns  as returned by unitColumns()
     */
    public function moveWithTeamTo(array $columns, ?int $parentId): void
    {
        $this->update([...$columns, 'parent_position_id' => $parentId]);

        $ids = [$this->id];
        for ($guard = 0; $ids && $guard < 50; $guard++) {
            $ids = self::whereIn('parent_position_id', $ids)->pluck('id')->all();
            self::whereIn('id', $ids)->update($columns);
        }
    }

    /** @return list<Jabatan> itself and everyone below it */
    public function withTeam(): array
    {
        $all = [$this];
        $level = [$this];
        for ($guard = 0; $level && $guard < 50; $guard++) {
            $level = self::with('jobLevel')->whereIn('parent_position_id', array_map(fn ($j) => $j->id, $level))->get()->all();
            array_push($all, ...$level);
        }

        return $all;
    }

    /**
     * The superior position: the one it reports to directly (Project Manager →
     * a Manager at HO), else the most senior position above it in its own unit
     * (staff → their Section Head), else the head of the nearest unit above
     * (Section Head → Dept Head / Manager → General Manager). Candidates at the
     * same work location come first, so a site reports to its site head.
     */
    public function superior(): ?Position
    {
        if ($this->parent) {
            return $this->parent;
        }
        // Not placed in the chart yet: nobody above it until it is.
        if ($this->unitLevel() === self::NO_UNIT) {
            return null;
        }

        $layer = $this->jobLevel?->layer ?? 0;
        $inUnit = self::inUnit($this->unitColumnValues())->whereKeyNot($this->id)->with('jobLevel')->get()
            ->filter(fn (Position $j) => ($j->jobLevel?->layer ?? 0) > $layer);
        if ($head = $this->mostSenior($inUnit)) {
            return $head;
        }

        $division = $this->divisionOf();
        $chain = match ($this->unitLevel()) {
            'section' => [['department_id', $this->departmentOf()?->id], ['division_id', $division?->id], ['directorate_id', $division?->directorate_id]],
            'department' => [['division_id', $division?->id], ['directorate_id', $division?->directorate_id]],
            'division' => [['directorate_id', $division?->directorate_id]],
            default => [],
        };
        foreach ($chain as [$column, $id]) {
            if ($id && ($head = $this->mostSenior(self::where($column, $id)->whereNull('parent_position_id')->with('jobLevel')->get()))) {
                return $head;
            }
        }

        return null;
    }

    /** @param  Collection<int, Jabatan>  $candidates */
    private function mostSenior(Collection $candidates): ?Position
    {
        $here = $candidates->where('work_location_id', $this->work_location_id);

        return ($here->isNotEmpty() ? $here : $candidates)
            ->sortBy([fn ($a, $b) => ($b->jobLevel?->layer ?? 0) <=> ($a->jobLevel?->layer ?? 0), ['sort_order', 'asc']])
            ->first();
    }
}
