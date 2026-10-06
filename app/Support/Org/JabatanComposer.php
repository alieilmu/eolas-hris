<?php

namespace App\Support\Org;

use App\Models\Bod;
use App\Models\Departemen;
use App\Models\Divisi;
use App\Models\Jabatan;
use App\Models\Karyawan;
use App\Models\Section;
use App\Models\SubGroup;
use App\Models\SubLocation;
use App\Models\WorkLocation;
use App\Support\Karyawan\MasterOptions;
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
class JabatanComposer
{
    /** Everything a placement may carry. */
    public const KEYS = ['work_location_id', 'sub_location_id', 'sub_group_id', 'bod_id', 'divisi_id', 'departemen_id', 'section_id', 'bidang'];

    /** The master list the "bidang" part of a name comes from. */
    public const BIDANG = 'bidang_jabatan';

    /**
     * The position a placement describes, or why it cannot be one.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null} attributes (unit, name, parent, ...), error
     */
    public static function compose(array $in): array
    {
        $location = WorkLocation::find($in['work_location_id'] ?? 0);
        $sub = SubGroup::find($in['sub_group_id'] ?? 0);
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

        $gelar = $sub->gelarAt($location);
        $base = [
            'sub_group_id' => $sub->id,
            'work_location_id' => $location->id,
            'sub_location_id' => $subLocation?->id,
            'bidang' => null,
            'parent_jabatan_id' => null,
        ];
        $unit = fn (string $level, int $id) => Jabatan::unitColumns("{$level}:{$id}");

        switch ($sub->sumber) {
            case 'bod':
            case 'divisi':
            case 'departemen':
            case 'section':
                $model = ['bod' => Bod::class, 'divisi' => Divisi::class, 'departemen' => Departemen::class, 'section' => Section::class][$sub->sumber];
                $owner = $model::find($in["{$sub->sumber}_id"] ?? 0);
                if (! $owner) {
                    return [null, 'Pilih '.Jabatan::UNIT_LABELS[$sub->sumber].'.'];
                }

                return [[...$base, ...$unit($sub->sumber, $owner->id), 'name' => self::join($gelar, $owner->name)], null];

            case 'work_location':
                // Named after the place; placed in the chart (SO) later.
                return [[...$base, ...Jabatan::unitColumns(Jabatan::NO_UNIT), 'name' => self::join($gelar, $location->titleName())], null];

            default: // bidang — in a Section, else a Departemen, else placed in the plan later
                $section = filled($in['section_id'] ?? null) ? Section::find($in['section_id']) : null;
                $departemen = ! $section && filled($in['departemen_id'] ?? null) ? Departemen::find($in['departemen_id']) : null;
                $bidang = MasterOptions::canonical(self::BIDANG, (string) ($in['bidang'] ?? ''));
                if (filled($in['section_id'] ?? null) && ! $section) {
                    return [null, 'Section tidak ditemukan.'];
                }
                if (! $section && filled($in['departemen_id'] ?? null) && ! $departemen) {
                    return [null, 'Departemen tidak ditemukan.'];
                }
                if (! $bidang) {
                    return [null, trim((string) ($in['bidang'] ?? '')) === ''
                        ? 'Pilih Nama Jabatan.'
                        : "Nama Jabatan \"{$in['bidang']}\" tidak ada di master Nama Jabatan."];
                }

                $columns = match (true) {
                    (bool) $section => $unit('section', $section->id),
                    (bool) $departemen => $unit('departemen', $departemen->id),
                    default => Jabatan::unitColumns(Jabatan::NO_UNIT),
                };

                return [[...$base, ...$columns, 'bidang' => $bidang, 'name' => self::join($gelar, $bidang)], null];
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
    public static function find(array $in, ?Jabatan $current = null): array
    {
        [$attributes, $error] = self::compose($in);
        if (! $attributes) {
            return [null, $error, null];
        }

        $sub = SubGroup::find($attributes['sub_group_id']);
        if ($current && $current->sub_group_id === $sub->id && self::samePlace($attributes)->whereKey($current->id)->exists()) {
            $unit = self::units($attributes);
            $placedLater = $sub->sumber === 'bidang' || $sub->sumber === 'work_location';
            if ($placedLater || self::units($current->unitColumnValues()) === $unit) {
                return [$current, null, $attributes['name']];
            }
        }
        $same = self::samePlace($attributes)->where('sub_group_id', $sub->id)->orderBy('order')->orderBy('id')->get();
        $unit = self::units($attributes);
        $inUnit = $same->first(fn (Jabatan $j) => self::units($j->unitColumnValues()) === $unit);

        $match = match ($sub->sumber) {
            'work_location' => $same->first(),
            'bidang' => $inUnit && $unit !== self::units(Jabatan::unitColumns(Jabatan::NO_UNIT))
                ? $inUnit
                // Not in the given unit: the same title anywhere at this place, if there is just one —
                // or just one in the employee's departemen.
                : ($same->count() === 1 ? $same->first() : self::onlyInDepartemen($same, $in)),
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
    public static function findForEmployee(array $in, ?Jabatan $current = null): array
    {
        [$jabatan, $error, $name] = self::find($in, $current);
        if ($jabatan || $error || blank($in['sub_location_id'] ?? null)) {
            return [$jabatan, $error, $name, false];
        }

        [$jabatan, $error] = self::findOrCreate($in);
        if (! $jabatan) {
            return [null, $error, $name, false];
        }
        if ($jabatan->wasRecentlyCreated) {
            $parent = SubLocation::find($in['sub_location_id'])?->parent?->load('subGroup');
            if ($parent && $jabatan->load('subGroup')->atasanLayerError($parent) === null) {
                $jabatan->update(['parent_jabatan_id' => $parent->id]);
            }
        }

        return [$jabatan, null, $name, $jabatan->wasRecentlyCreated];
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
        $sub = SubGroup::find($attributes['sub_group_id']);
        if ($sub->sumber === 'work_location' || ($sub->sumber === 'bidang' && blank($in['section_id'] ?? null))) {
            $same = self::samePlace($attributes)->with('section')->where('sub_group_id', $sub->id)->get();
            if ($same->count() > 1) {
                return [null, "Jabatan {$attributes['name']} ada di beberapa section (".$same->map(fn (Jabatan $j) => $j->section?->name ?? 'belum ditempatkan')->implode(', ').') — pilih Section-nya.'];
            }
            if ($same->count() === 1) {
                return [$same->first(), null];
            }
        }

        $unitColumns = array_intersect_key($attributes, array_flip(Jabatan::UNIT_COLUMNS));
        $existing = self::samePlace($attributes)->inUnit($unitColumns)->first();
        if ($existing) {
            return [$existing, null];
        }

        // A Section given for a title still waiting to be placed: that position goes there.
        if ($sub->sumber === 'bidang') {
            $unplaced = self::samePlace($attributes)->inUnit(Jabatan::unitColumns(Jabatan::NO_UNIT))->where('sub_group_id', $sub->id)->first();
            if ($unplaced) {
                $unplaced->moveWithTeamTo($unitColumns, null);

                return [$unplaced->fresh(), null];
            }
        }

        return [Jabatan::create([...$attributes, 'order' => (Jabatan::max('order') ?? 0) + 1]), null];
    }

    /** The placement a position stands for — to prefill a form, or to fill the gaps of a partial import row. */
    public static function placementOf(Jabatan $jabatan): array
    {
        return [
            'work_location_id' => $jabatan->work_location_id,
            'sub_location_id' => $jabatan->sub_location_id,
            'sub_group_id' => $jabatan->sub_group_id,
            ...array_combine(
                array_values(Jabatan::UNIT_COLUMNS),
                array_map(fn (string $column) => $jabatan->{$column}, array_values(Jabatan::UNIT_COLUMNS)),
            ),
            'bidang' => $jabatan->bidang,
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
        $out = array_fill_keys(Karyawan::PLACEMENT_COLUMNS, null);
        $sub = SubGroup::find($in['sub_group_id'] ?? 0);
        $out['work_location_id'] = filled($in['work_location_id'] ?? null) ? (int) $in['work_location_id'] : null;
        $out['sub_location_id'] = filled($in['sub_location_id'] ?? null) ? (int) $in['sub_location_id'] : null;
        $out['sub_group_id'] = $sub?->id;
        $id = fn (string $key) => filled($in[$key] ?? null) ? (int) $in[$key] : null;

        switch ($sub?->sumber) {
            case 'bod':
            case 'divisi':
            case 'departemen':
                $out["{$sub->sumber}_id"] = $id("{$sub->sumber}_id");
                break;
            case 'section':
            case 'bidang':
                $out['section_id'] = $id('section_id');
                $out['departemen_id'] = $out['section_id'] ? Section::find($out['section_id'])?->departemen_id : $id('departemen_id');
                if ($sub->sumber === 'bidang') {
                    $out['bidang'] = MasterOptions::canonical(self::BIDANG, (string) ($in['bidang'] ?? '')) ?? (filled($in['bidang'] ?? null) ? $in['bidang'] : null);
                }
                break;
        }

        return $out;
    }

    /** The placement of whoever holds a position. */
    public static function employeePlacementOf(Jabatan $jabatan): array
    {
        return self::employeePlacement([...self::placementOf($jabatan), 'departemen_id' => $jabatan->departemenOf()?->id]);
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
            'work_location' => [WorkLocation::class, 'name', 'Work Location', fn ($q, $v) => $q->whereRaw('LOWER(name) = ? OR LOWER(kode) = ?', [$v, $v])],
            'sub_group' => [SubGroup::class, 'nama', 'SubGroup', null],
            'bod' => [Bod::class, 'name', 'BOD', null],
            'divisi' => [Divisi::class, 'name', 'Divisi', null],
            'departemen' => [Departemen::class, 'name', 'Departemen', null],
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
            $sections = Section::with('departemen')->whereRaw('LOWER(name) = ?', [$norm($names['section'])])->get();
            if ($given('departemen')) {
                $sections = $sections->filter(fn (Section $s) => $norm($s->departemen->name) === $norm($names['departemen']));
            }
            if ($sections->count() !== 1) {
                return [null, $sections->isEmpty()
                    ? "Section \"{$names['section']}\" tidak ada".($given('departemen') ? " di Departemen {$names['departemen']}" : '').'.'
                    : "Section \"{$names['section']}\" ada di beberapa departemen — isi kolom Departemen."];
            }
            $in['section_id'] = $sections->first()->id;
        }
        if ($given('bidang')) {
            $in['bidang'] = $names['bidang'];
        }

        return [$in, null];
    }

    /**
     * The name a position should carry under the current master data, or null
     * when it was not composed (e.g. created before names were composed).
     */
    public static function nameFor(Jabatan $jabatan): ?string
    {
        $sub = $jabatan->subGroup;
        if (! $sub) {
            return null;
        }
        $gelar = $sub->gelarAt($jabatan->workLocation);

        return match ($sub->sumber) {
            'bod', 'divisi', 'departemen', 'section' => $jabatan->unitLevel() === $sub->sumber ? self::join($gelar, $jabatan->unit()?->name) : null,
            'work_location' => $jabatan->workLocation ? self::join($gelar, $jabatan->workLocation->titleName()) : null,
            default => $jabatan->bidang ? self::join($gelar, $jabatan->bidang) : null,
        };
    }

    /** Re-applies nameFor() everywhere — after a SubGroup, unit, work location or bidang changes. */
    public static function refreshNames(): int
    {
        $changed = 0;
        Jabatan::with(['subGroup', 'workLocation', ...Jabatan::UNIT_RELATIONS])->get()->each(function (Jabatan $j) use (&$changed) {
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
        return Jabatan::query()
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
        foreach (Jabatan::UNIT_COLUMNS as $column) {
            $out[$column] = isset($values[$column]) ? (int) $values[$column] : null;
        }

        return $out;
    }

    /** @param  Collection<int, Jabatan>  $same */
    private static function onlyInDepartemen(Collection $same, array $in): ?Jabatan
    {
        $departemenId = filled($in['section_id'] ?? null) ? Section::find($in['section_id'])?->departemen_id : ($in['departemen_id'] ?? null);
        if (! $departemenId) {
            return null;
        }
        $there = $same->filter(fn (Jabatan $j) => $j->departemenOf()?->id === (int) $departemenId);

        return $there->count() === 1 ? $there->first() : null;
    }

    private static function join(?string $gelar, ?string $rest): string
    {
        return trim(implode(' ', array_filter([trim((string) $gelar), trim((string) $rest)])));
    }
}
