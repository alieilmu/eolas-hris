<?php

namespace App\Support\Organization;

use App\Models\Directorate;
use App\Models\Department;
use App\Models\Division;
use App\Models\Position;
use App\Models\Employee;
use App\Models\Section;
use App\Models\JobLevel;
use App\Models\SubLocation;
use App\Models\WorkLocation;
use App\Support\Employees\LookupValues;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Turns a placement — work location, SubGroup and what that SubGroup names its
 * positions after — into a position, e.g.
 *   Section Head Site + Section PRODUKSI          → SECTION HEAD PRODUKSI
 *   Officer HO / Group Leader + HO + bidang HSE    → OFFICER HSE (GROUP LEADER HSE at a site)
 *   Project Manager + RMTU                         → PROJECT MANAGER RMTU
 * Positions are created in the Plan Struktur Organisasi (findOrCreate); an
 * employee's placement only looks for the matching one (find) — without one,
 * the employee waits to be placed. Superiors are not part of a placement:
 * they are arranged in the plan.
 */
class PositionComposer
{
    /** Everything a placement may carry. */
    public const KEYS = ['work_location_id', 'sub_location_id', 'job_level_id', 'directorate_id', 'division_id', 'department_id', 'section_id', 'discipline'];

    /** The master list the "bidang" part of a name comes from. */
    public const BIDANG = 'position_discipline';

    /**
     * The position a placement describes, or why it cannot be one.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null} attributes (unit, name, parent, ...), error
     */
    public static function compose(array $in): array
    {
        $location = WorkLocation::find($in['work_location_id'] ?? 0);
        $sub = JobLevel::find($in['job_level_id'] ?? 0);
        if (! $location) {
            return [null, 'Pilih Work Location.'];
        }
        if (! $sub) {
            return [null, 'Pilih SubGroup.'];
        }

        $subLocation = null;
        if (filled($in['sub_location_id'] ?? null)) {
            $subLocation = SubLocation::find($in['sub_location_id']);
            if (! $subLocation || $subLocation->work_location_id !== $location->id) {
                return [null, "Sub Lokasi tidak ada di {$location->name}."];
            }
        }

        $title_prefix = $sub->titlePrefixAt($location);
        $base = [
            'job_level_id' => $sub->id,
            'work_location_id' => $location->id,
            'sub_location_id' => $subLocation?->id,
            'discipline' => null,
            'parent_position_id' => null,
        ];
        $unit = fn (string $level, int $id) => Position::unitColumns("{$level}:{$id}");

        switch ($sub->source) {
            case 'directorate':
            case 'division':
            case 'department':
            case 'section':
                $model = ['directorate' => Directorate::class, 'division' => Division::class, 'department' => Department::class, 'section' => Section::class][$sub->source];
                $owner = $model::find($in["{$sub->source}_id"] ?? 0);
                if (! $owner) {
                    return [null, 'Pilih '.Position::UNIT_LABELS[$sub->source].'.'];
                }

                return [[...$base, ...$unit($sub->source, $owner->id), 'name' => self::join($title_prefix, $owner->name)], null];

            case 'work_location':
                // Named after the place; placed in the chart (SO) later.
                return [[...$base, ...Position::unitColumns(Position::NO_UNIT), 'name' => self::join($title_prefix, $location->titleName())], null];

            default: // bidang — in a Section, else a Departemen, else placed in the plan later
                $section = filled($in['section_id'] ?? null) ? Section::find($in['section_id']) : null;
                $department = ! $section && filled($in['department_id'] ?? null) ? Department::find($in['department_id']) : null;
                $discipline = LookupValues::canonical(self::BIDANG, (string) ($in['discipline'] ?? ''));
                if (filled($in['section_id'] ?? null) && ! $section) {
                    return [null, 'Section tidak ditemukan.'];
                }
                if (! $section && filled($in['department_id'] ?? null) && ! $department) {
                    return [null, 'Departemen tidak ditemukan.'];
                }
                if (! $discipline) {
                    return [null, trim((string) ($in['discipline'] ?? '')) === ''
                        ? 'Pilih Nama Jabatan.'
                        : "Nama Jabatan \"{$in['discipline']}\" tidak ada di master Nama Jabatan."];
                }

                $columns = match (true) {
                    (bool) $section => $unit('section', $section->id),
                    (bool) $department => $unit('department', $department->id),
                    default => Position::unitColumns(Position::NO_UNIT),
                };

                return [[...$base, ...$columns, 'discipline' => $discipline, 'name' => self::join($title_prefix, $discipline)], null];
        }
    }

    /**
     * The position an employee's placement points to, if the plan has it.
     * Nothing is created: without a match the employee waits to be placed.
     * The employee's `$current` position wins when it fits the placement (so
     * saving an unchanged placement never moves anyone).
     *
     * @return array{0: Jabatan|null, 1: string|null, 2: string|null} the position, an error, the composed name
     */
    public static function find(array $in, ?Position $current = null): array
    {
        [$attributes, $error] = self::compose($in);
        if (! $attributes) {
            return [null, $error, null];
        }

        $sub = JobLevel::find($attributes['job_level_id']);
        if ($current && $current->job_level_id === $sub->id && self::samePlace($attributes)->whereKey($current->id)->exists()) {
            $unit = self::units($attributes);
            $placedLater = $sub->source === 'discipline' || $sub->source === 'work_location';
            if ($placedLater || self::units($current->unitColumnValues()) === $unit) {
                return [$current, null, $attributes['name']];
            }
        }
        $same = self::samePlace($attributes)->where('job_level_id', $sub->id)->orderBy('sort_order')->orderBy('id')->get();
        $unit = self::units($attributes);
        $inUnit = $same->first(fn (Position $j) => self::units($j->unitColumnValues()) === $unit);

        $match = match ($sub->source) {
            'work_location' => $same->first(),
            'discipline' => $inUnit && $unit !== self::units(Position::unitColumns(Position::NO_UNIT))
                ? $inUnit
                // Not in the given unit: the same title anywhere at this place, if there is just one —
                // or just one in the employee's departemen.
                : ($same->count() === 1 ? $same->first() : self::onlyInDepartment($same, $in)),
            default => $inUnit,
        };

        return [$match, null, $attributes['name']];
    }

    /**
     * find() for placing an employee. A Sub Lokasi has no plan of its own to
     * wait for: someone assigned there without a matching position gets one,
     * under the position its box hangs from (layer permitting).
     *
     * @return array{0: Jabatan|null, 1: string|null, 2: string|null, 3: bool} position, error, composed name, created
     */
    public static function findForEmployee(array $in, ?Position $current = null): array
    {
        [$position, $error, $name] = self::find($in, $current);
        if ($position || $error || blank($in['sub_location_id'] ?? null)) {
            return [$position, $error, $name, false];
        }

        [$position, $error] = self::findOrCreate($in);
        if (! $position) {
            return [null, $error, $name, false];
        }
        if ($position->wasRecentlyCreated) {
            $parent = SubLocation::find($in['sub_location_id'])?->parent?->load('jobLevel');
            if ($parent && $position->load('jobLevel')->superiorLayerError($parent) === null) {
                $position->update(['parent_position_id' => $parent->id]);
            }
        }

        return [$position, null, $name, $position->wasRecentlyCreated];
    }

    /**
     * The existing position for a placement, or a new one (the plan, a jabatan import).
     *
     * @return array{0: Jabatan|null, 1: string|null}
     */
    public static function findOrCreate(array $in): array
    {
        [$attributes, $error] = self::compose($in);
        if (! $attributes) {
            return [null, $error];
        }

        // Named after the place, or no Section given: the same title at this place, wherever it was placed in the chart.
        $sub = JobLevel::find($attributes['job_level_id']);
        if ($sub->source === 'work_location' || ($sub->source === 'discipline' && blank($in['section_id'] ?? null))) {
            $same = self::samePlace($attributes)->with('section')->where('job_level_id', $sub->id)->get();
            if ($same->count() > 1) {
                return [null, "Jabatan {$attributes['name']} ada di beberapa section (".$same->map(fn (Position $j) => $j->section?->name ?? 'belum ditempatkan')->implode(', ').') — pilih Section-nya.'];
            }
            if ($same->count() === 1) {
                return [$same->first(), null];
            }
        }

        $unitColumns = array_intersect_key($attributes, array_flip(Position::UNIT_COLUMNS));
        $existing = self::samePlace($attributes)->inUnit($unitColumns)->first();
        if ($existing) {
            return [$existing, null];
        }

        // A Section given for a title still waiting to be placed: that position goes there.
        if ($sub->source === 'discipline') {
            $unplaced = self::samePlace($attributes)->inUnit(Position::unitColumns(Position::NO_UNIT))->where('job_level_id', $sub->id)->first();
            if ($unplaced) {
                $unplaced->moveWithTeamTo($unitColumns, null);

                return [$unplaced->fresh(), null];
            }
        }

        return [Position::create([...$attributes, 'sort_order' => (Position::max('sort_order') ?? 0) + 1]), null];
    }

    /** The placement a position stands for — to prefill a form, or to fill the gaps of a partial import row. */
    public static function placementOf(Position $position): array
    {
        return [
            'work_location_id' => $position->work_location_id,
            'sub_location_id' => $position->sub_location_id,
            'job_level_id' => $position->job_level_id,
            ...array_combine(
                array_values(Position::UNIT_COLUMNS),
                array_map(fn (string $column) => $position->{$column}, array_values(Position::UNIT_COLUMNS)),
            ),
            'discipline' => $position->discipline,
        ];
    }

    /**
     * What an employee keeps of a placement: the fields their SubGroup uses, with
     * the departemen of a section filled in (so lists can group by departemen).
     *
     * @return array<string, mixed> Karyawan::PLACEMENT_COLUMNS
     */
    public static function employeePlacement(array $in): array
    {
        $out = array_fill_keys(Employee::PLACEMENT_COLUMNS, null);
        $sub = JobLevel::find($in['job_level_id'] ?? 0);
        $out['work_location_id'] = filled($in['work_location_id'] ?? null) ? (int) $in['work_location_id'] : null;
        $out['sub_location_id'] = filled($in['sub_location_id'] ?? null) ? (int) $in['sub_location_id'] : null;
        $out['job_level_id'] = $sub?->id;
        $id = fn (string $key) => filled($in[$key] ?? null) ? (int) $in[$key] : null;

        switch ($sub?->source) {
            case 'directorate':
            case 'division':
            case 'department':
                $out["{$sub->source}_id"] = $id("{$sub->source}_id");
                break;
            case 'section':
            case 'discipline':
                $out['section_id'] = $id('section_id');
                $out['department_id'] = $out['section_id'] ? Section::find($out['section_id'])?->department_id : $id('department_id');
                if ($sub->source === 'discipline') {
                    $out['discipline'] = LookupValues::canonical(self::BIDANG, (string) ($in['discipline'] ?? '')) ?? (filled($in['discipline'] ?? null) ? $in['discipline'] : null);
                }
                break;
        }

        return $out;
    }

    /** The placement of whoever holds a position. */
    public static function employeePlacementOf(Position $position): array
    {
        return self::employeePlacement([...self::placementOf($position), 'department_id' => $position->departmentOf()?->id]);
    }

    /**
     * Placement ids from the names typed in a spreadsheet, on top of `$base`
     * (the employee's current placement). A Section name is settled by the
     * Departemen column when several departemen have one.
     *
     * @param  array<string, string|null>  $names  work_location, sub_location, sub_group, bod, divisi, departemen, section, bidang
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    public static function fromNames(array $names, array $base = []): array
    {
        $norm = fn ($v) => mb_strtolower(trim((string) $v));
        $given = fn (string $key) => isset($names[$key]) && trim((string) $names[$key]) !== '';
        $in = $base;

        $lookups = [
            'work_location' => [WorkLocation::class, 'name', 'Work Location', fn ($q, $v) => $q->whereRaw('LOWER(name) = ? OR LOWER(code) = ?', [$v, $v])],
            'job_level' => [JobLevel::class, 'name', 'SubGroup', null],
            'directorate' => [Directorate::class, 'name', 'BOD', null],
            'division' => [Division::class, 'name', 'Divisi', null],
            'department' => [Department::class, 'name', 'Departemen', null],
        ];
        foreach ($lookups as $key => [$model, $column, $label, $query]) {
            if (! $given($key)) {
                continue;
            }
            $q = $model::query();
            $query ? $query($q, $norm($names[$key])) : $q->whereRaw("LOWER({$column}) = ?", [$norm($names[$key])]);
            $found = $q->first();
            if (! $found) {
                return [null, "{$label} \"{$names[$key]}\" tidak ada di master data."];
            }
            $in[$key.'_id'] = $found->id;
        }

        // A Sub Lokasi belongs to one work location; a new work location drops the old one.
        if ($given('work_location') && ($base['work_location_id'] ?? null) !== ($in['work_location_id'] ?? null)) {
            $in['sub_location_id'] = null;
        }
        if ($given('sub_location')) {
            $subLocation = SubLocation::where('work_location_id', $in['work_location_id'] ?? 0)->whereRaw('LOWER(name) = ?', [$norm($names['sub_location'])])->first();
            if (! $subLocation) {
                return [null, "Sub Lokasi \"{$names['sub_location']}\" tidak ada di Work Location itu."];
            }
            $in['sub_location_id'] = $subLocation->id;
        }

        if ($given('section')) {
            $sections = Section::with('department')->whereRaw('LOWER(name) = ?', [$norm($names['section'])])->get();
            if ($given('department')) {
                $sections = $sections->filter(fn (Section $s) => $norm($s->department->name) === $norm($names['department']));
            }
            if ($sections->count() !== 1) {
                return [null, $sections->isEmpty()
                    ? "Section \"{$names['section']}\" tidak ada".($given('department') ? " di Departemen {$names['department']}" : '').'.'
                    : "Section \"{$names['section']}\" ada di beberapa departemen — isi kolom Departemen."];
            }
            $in['section_id'] = $sections->first()->id;
        }
        if ($given('discipline')) {
            $in['discipline'] = $names['discipline'];
        }

        return [$in, null];
    }

    /**
     * The name a position should carry under the current master data, or null
     * when it was not composed (e.g. created before names were composed).
     */
    public static function nameFor(Position $position): ?string
    {
        $sub = $position->jobLevel;
        if (! $sub) {
            return null;
        }
        $title_prefix = $sub->titlePrefixAt($position->workLocation);

        return match ($sub->source) {
            'directorate', 'division', 'department', 'section' => $position->unitLevel() === $sub->source ? self::join($title_prefix, $position->unit()?->name) : null,
            'work_location' => $position->workLocation ? self::join($title_prefix, $position->workLocation->titleName()) : null,
            default => $position->discipline ? self::join($title_prefix, $position->discipline) : null,
        };
    }

    /** Re-applies nameFor() everywhere — after a SubGroup, unit, work location or bidang changes. */
    public static function refreshNames(): int
    {
        $changed = 0;
        Position::with(['jobLevel', 'workLocation', ...Position::UNIT_RELATIONS])->get()->each(function (Position $j) use (&$changed) {
            $name = self::nameFor($j);
            if ($name && $name !== $j->name) {
                $j->update(['name' => $name]);
                $changed++;
            }
        });

        return $changed;
    }

    /** Positions with the composed name at the same work location and Sub Lokasi. */
    private static function samePlace(array $attributes): Builder
    {
        return Position::query()
            ->where('work_location_id', $attributes['work_location_id'])
            ->when(
                $attributes['sub_location_id'] ?? null,
                fn ($q, $id) => $q->where('sub_location_id', $id),
                fn ($q) => $q->whereNull('sub_location_id'),
            )
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($attributes['name'])]);
    }

    /** @return array<string, int|null> the unit columns, comparable */
    private static function units(array $values): array
    {
        $out = [];
        foreach (Position::UNIT_COLUMNS as $column) {
            $out[$column] = isset($values[$column]) ? (int) $values[$column] : null;
        }

        return $out;
    }

    /** @param  Collection<int, Jabatan>  $same */
    private static function onlyInDepartment(Collection $same, array $in): ?Position
    {
        $departemenId = filled($in['section_id'] ?? null) ? Section::find($in['section_id'])?->department_id : ($in['department_id'] ?? null);
        if (! $departemenId) {
            return null;
        }
        $there = $same->filter(fn (Position $j) => $j->departmentOf()?->id === (int) $departemenId);

        return $there->count() === 1 ? $there->first() : null;
    }

    private static function join(?string $title_prefix, ?string $rest): string
    {
        return trim(implode(' ', array_filter([trim((string) $title_prefix), trim((string) $rest)])));
    }
}
