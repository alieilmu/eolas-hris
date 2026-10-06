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
    'sub_group_id', 'bod_id', 'divisi_id', 'departemen_id', 'section_id', 'work_location_id', 'sub_location_id', 'bidang',
    'parent_jabatan_id', 'name', 'order',
])]
class Jabatan extends Model
{
    protected $table = 'jabatans';

    /** Unit level => column, top of the organisation first. */
    public const UNIT_COLUMNS = ['bod' => 'bod_id', 'divisi' => 'divisi_id', 'departemen' => 'departemen_id', 'section' => 'section_id'];

    public const UNIT_LABELS = ['bod' => 'BOD', 'divisi' => 'Divisi', 'departemen' => 'Departemen', 'section' => 'Section'];

    /** A position not yet placed in the organisation chart (no unit column set). */
    public const NO_UNIT = 'none';

    /** SubGroups whose position names do not come from the unit: those positions can be placed anywhere in the chart. */
    public const FREE_SUMBER = ['bidang', 'work_location'];

    /** Eager-load these to read a position's unit and the units above it without extra queries. */
    public const UNIT_RELATIONS = ['bod', 'divisi.bod', 'departemen.divisi.bod', 'section.departemen.divisi.bod'];

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function subGroup(): BelongsTo
    {
        return $this->belongsTo(SubGroup::class);
    }

    public function bod(): BelongsTo
    {
        return $this->belongsTo(Bod::class);
    }

    public function divisi(): BelongsTo
    {
        return $this->belongsTo(Divisi::class);
    }

    /** Set only when the position is attached to the departemen itself; see departemenOf(). */
    public function departemen(): BelongsTo
    {
        return $this->belongsTo(Departemen::class);
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
        return $this->belongsTo(Jabatan::class, 'parent_jabatan_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Jabatan::class, 'parent_jabatan_id')->orderBy('order');
    }

    public function karyawans(): HasMany
    {
        return $this->hasMany(Karyawan::class);
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
        return in_array($this->subGroup?->sumber, self::FREE_SUMBER, true);
    }

    /**
     * Why `$atasan` cannot be its superior: a superior may not be of a lower
     * layer (the same layer is fine, e.g. a Project Manager under a Manager).
     */
    public function atasanLayerError(Jabatan $atasan): ?string
    {
        $own = $this->subGroup?->layer;
        $theirs = $atasan->subGroup?->layer;
        if ($own === null || $theirs === null || $theirs >= $own) {
            return null;
        }

        return "\"{$atasan->name}\" (layer {$theirs}) tidak bisa menjadi atasan \"{$this->name}\" (layer {$own}): layer atasan tidak boleh di bawah bawahannya.";
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

    public function unit(): Bod|Divisi|Departemen|Section|null
    {
        $level = $this->unitLevel();

        return $level === self::NO_UNIT ? null : $this->{$level};
    }

    /** The departemen it belongs to, directly or through its section. */
    public function departemenOf(): ?Departemen
    {
        return $this->section?->departemen ?? $this->departemen;
    }

    public function divisiOf(): ?Divisi
    {
        return $this->departemenOf()?->divisi ?? $this->divisi;
    }

    public function bodOf(): ?Bod
    {
        return $this->divisiOf()?->bod ?? $this->bod;
    }

    /** e.g. "Section Workshop · Maintenance", "Divisi Operasional". */
    public function unitLabel(): string
    {
        if ($this->unitLevel() === self::NO_UNIT) {
            return 'Belum ditempatkan di SO';
        }
        $unit = $this->unit();
        $label = self::UNIT_LABELS[$this->unitLevel()].' '.($unit?->name ?? '-');

        return $unit instanceof Section ? "{$label} · {$unit->departemen->name}" : $label;
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
        $models = ['bod' => Bod::class, 'divisi' => Divisi::class, 'departemen' => Departemen::class, 'section' => Section::class];
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
        $this->update([...$columns, 'parent_jabatan_id' => $parentId]);

        $ids = [$this->id];
        for ($guard = 0; $ids && $guard < 50; $guard++) {
            $ids = self::whereIn('parent_jabatan_id', $ids)->pluck('id')->all();
            self::whereIn('id', $ids)->update($columns);
        }
    }

    /** @return list<Jabatan> itself and everyone below it */
    public function withTeam(): array
    {
        $all = [$this];
        $level = [$this];
        for ($guard = 0; $level && $guard < 50; $guard++) {
            $level = self::with('subGroup')->whereIn('parent_jabatan_id', array_map(fn ($j) => $j->id, $level))->get()->all();
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
    public function atasan(): ?Jabatan
    {
        if ($this->parent) {
            return $this->parent;
        }
        // Not placed in the chart yet: nobody above it until it is.
        if ($this->unitLevel() === self::NO_UNIT) {
            return null;
        }

        $layer = $this->subGroup?->layer ?? 0;
        $inUnit = self::inUnit($this->unitColumnValues())->whereKeyNot($this->id)->with('subGroup')->get()
            ->filter(fn (Jabatan $j) => ($j->subGroup?->layer ?? 0) > $layer);
        if ($head = $this->mostSenior($inUnit)) {
            return $head;
        }

        $divisi = $this->divisiOf();
        $chain = match ($this->unitLevel()) {
            'section' => [['departemen_id', $this->departemenOf()?->id], ['divisi_id', $divisi?->id], ['bod_id', $divisi?->bod_id]],
            'departemen' => [['divisi_id', $divisi?->id], ['bod_id', $divisi?->bod_id]],
            'divisi' => [['bod_id', $divisi?->bod_id]],
            default => [],
        };
        foreach ($chain as [$column, $id]) {
            if ($id && ($head = $this->mostSenior(self::where($column, $id)->whereNull('parent_jabatan_id')->with('subGroup')->get()))) {
                return $head;
            }
        }

        return null;
    }

    /** @param  Collection<int, Jabatan>  $candidates */
    private function mostSenior(Collection $candidates): ?Jabatan
    {
        $here = $candidates->where('work_location_id', $this->work_location_id);

        return ($here->isNotEmpty() ? $here : $candidates)
            ->sortBy([fn ($a, $b) => ($b->subGroup?->layer ?? 0) <=> ($a->subGroup?->layer ?? 0), ['order', 'asc']])
            ->first();
    }
}
