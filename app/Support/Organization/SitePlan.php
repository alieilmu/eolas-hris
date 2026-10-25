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
use Illuminate\Support\Collection;

/**
 * The organisation of one work location, as the Plan Struktur Organisasi and
 * Struktur Organisasi draw it: its positions by reporting line, the boxes of its
 * Sub Lokasi Kerja, and — at Head Office — every site's Project Manager under
 * the HO position it reports to.
 *
 * A site's chart starts at its Project Manager (set up first, possibly vacant),
 * with an optional Deputy under it.
 */
class SitePlan
{
    private const MAX_DEPTH = 60;

    /** @var Collection<int, Jabatan> keyed by id */
    private Collection $positions;

    /** @var Collection<int|string, Collection<int, Jabatan>> by parent id */
    private Collection $childrenOf;

    /** @var Collection<int, SubLocation> keyed by id */
    private Collection $subLocations;

    /** @var Collection<int, Collection<int, Karyawan>> active holders by jabatan id */
    private Collection $holders;

    /** @var Collection<int, list<array<string, mixed>>> HO only: site cards by the HO position they hang under */
    private Collection $sitesUnder;

    public function __construct(public readonly WorkLocation $location)
    {
        $this->positions = Position::with(['jobLevel', ...Position::UNIT_RELATIONS])
            ->where('work_location_id', $location->id)
            ->orderBy('sort_order')->orderBy('id')
            ->get()->keyBy('id');
        $this->childrenOf = $this->positions->filter(fn (Position $j) => $this->positions->has($j->parent_position_id))->groupBy('parent_position_id');
        $this->subLocations = SubLocation::where('work_location_id', $location->id)->orderBy('sort_order')->orderBy('name')->get()->keyBy('id');
        $this->holders = Employee::active()->whereIn('position_id', $this->positions->keys())->orderBy('name')
            ->get(['id', 'employee_number', 'name', 'position_id', 'job_level_id'])->groupBy('position_id');
        $this->sitesUnder = collect();
    }

    // --- the Project Manager ------------------------------------------------------

    /** The SubGroup of a site's head: named after the work location, highest layer, not a deputy. */
    public static function pmSubGroup(): ?JobLevel
    {
        return JobLevel::where('source', 'work_location')->orderByDesc('layer')->orderBy('name')->get()
            ->sortBy(fn (JobLevel $g) => str_contains(mb_strtolower($g->name), 'deputy') ? 1 : 0)
            ->first();
    }

    public static function dpmSubGroup(): ?JobLevel
    {
        return JobLevel::where('source', 'work_location')->get()->first(fn (JobLevel $g) => str_contains(mb_strtolower($g->name), 'deputy'));
    }

    public static function pmOf(WorkLocation $location): ?Position
    {
        $pm = self::pmSubGroup();

        return $pm ? Position::where('work_location_id', $location->id)->where('job_level_id', $pm->id)->whereNull('sub_location_id')->orderBy('id')->first() : null;
    }

    /** A site starts with choosing its Project Manager; Head Office starts at the BOD. */
    public function needsSetup(): bool
    {
        return ! $this->location->isHeadOffice() && ! self::pmOf($this->location);
    }

    // --- the chart -----------------------------------------------------------------

    /**
     * Root nodes: positions without a superior here, Sub Lokasi boxes not hung
     * under a position, and the Project Manager first.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(): array
    {
        if ($this->location->isHeadOffice()) {
            $this->loadSites();
        }

        $roots = $this->positions
            ->filter(fn (Position $j) => ! $j->sub_location_id && ! $this->positions->has($j->parent_position_id))
            ->sortBy(fn (Position $j) => [$this->isPm($j) ? 0 : 1, -($j->jobLevel?->layer ?? 0), $j->name])
            ->map(fn (Position $j) => $this->positionNode($j, 0))
            ->values()->all();

        $looseBoxes = $this->subLocations
            ->filter(fn (SubLocation $s) => ! $this->positions->has($s->parent_position_id) && $this->boxShown($s))
            ->map(fn (SubLocation $s) => $this->boxNode($s, 0))
            ->values()->all();

        return [...$roots, ...$looseBoxes];
    }

    /**
     * HO only: sites whose Project Manager does not report to an HO position yet,
     * or that have no Project Manager.
     *
     * @return list<array<string, mixed>>
     */
    public function unlinkedSites(): array
    {
        if (! $this->location->isHeadOffice()) {
            return [];
        }
        $this->loadSites();

        return $this->sitesUnder->get(0, []);
    }

    /** @return array<string, mixed> */
    private function positionNode(Position $j, int $depth): array
    {
        $children = [];
        if ($depth < self::MAX_DEPTH) {
            $children = $this->childrenOf->get($j->id, collect())
                ->filter(fn (Position $c) => $c->sub_location_id === $j->sub_location_id || $c->sub_location_id === null)
                ->sortBy(fn (Position $c) => [-($c->jobLevel?->layer ?? 0), $c->name])
                ->map(fn (Position $c) => $this->positionNode($c, $depth + 1))
                ->values()->all();
            $boxes = $this->subLocations->filter(fn (SubLocation $s) => $s->parent_position_id === $j->id)
                ->map(fn (SubLocation $s) => $this->boxNode($s, $depth + 1))->values()->all();
            $children = [...$children, ...$boxes, ...$this->sitesUnder->get($j->id, [])];
        }

        return [...self::positionData($j, $this->holders->get($j->id, collect())), 'children' => $children];
    }

    /** @return array<string, mixed> */
    private function boxNode(SubLocation $s, int $depth): array
    {
        $inside = $this->positions->filter(fn (Position $j) => $j->sub_location_id === $s->id
            && (! $this->positions->has($j->parent_position_id) || $this->positions->get($j->parent_position_id)->sub_location_id !== $s->id));

        return [
            'kind' => 'sub_location',
            'key' => "s{$s->id}",
            'id' => $s->id,
            'name' => $s->name,
            'parent_position_id' => $s->parent_position_id,
            'children' => $depth < self::MAX_DEPTH
                ? $inside->sortBy(fn (Position $j) => [-($j->jobLevel?->layer ?? 0), $j->name])->map(fn (Position $j) => $this->positionNode($j, $depth + 1))->values()->all()
                : [],
        ];
    }

    private function boxShown(SubLocation $s): bool
    {
        return $s->parent_position_id !== null || $this->positions->contains('sub_location_id', $s->id);
    }

    private function isPm(Position $j): bool
    {
        return $j->jobLevel?->source === 'work_location' && ! str_contains(mb_strtolower($j->jobLevel->name), 'deputy');
    }

    /** HO: each site's Project Manager card, under the HO position it reports to (key 0 = not linked). */
    private function loadSites(): void
    {
        if ($this->sitesUnder->isNotEmpty()) {
            return;
        }
        $sites = WorkLocation::where('id', '!=', $this->location->id)->where('type', '!=', WorkLocation::TYPE_HEAD_OFFICE)->orderBy('sort_order')->get();
        $counts = Position::selectRaw('work_location_id, COUNT(*) as n')->groupBy('work_location_id')->pluck('n', 'work_location_id');

        foreach ($sites as $site) {
            $pm = self::pmOf($site)?->load('jobLevel', ...Position::UNIT_RELATIONS);
            $parent = $pm && $this->positions->has($pm->parent_position_id) ? $pm->parent_position_id : 0;
            $holders = $pm ? Employee::active()->where('position_id', $pm->id)->orderBy('name')->get(['id', 'employee_number', 'name', 'position_id', 'job_level_id']) : collect();
            $card = [
                'kind' => 'site',
                'key' => "w{$site->id}",
                'work_location' => ['id' => $site->id, 'name' => $site->name, 'code' => $site->code],
                'position' => $pm ? self::positionData($pm, $holders) : null,
                'position_count' => (int) ($counts[$site->id] ?? 0),
                'children' => [],
            ];
            $this->sitesUnder->put($parent, [...$this->sitesUnder->get($parent, []), $card]);
        }
    }

    /** @return array<string, mixed> what a card shows of a position */
    public static function positionData(Position $j, Collection $holders): array
    {
        return [
            'kind' => 'position',
            'key' => "j{$j->id}",
            'id' => $j->id,
            'name' => $j->name,
            'job_level_id' => $j->job_level_id,
            'job_level' => $j->jobLevel?->name,
            'layer' => $j->jobLevel?->layer,
            'source' => $j->jobLevel?->source,
            'work_location_id' => $j->work_location_id,
            'sub_location_id' => $j->sub_location_id,
            'parent_position_id' => $j->parent_position_id,
            // "section:5" / "none" and its label, e.g. "Section WORKSHOP · MAINTENANCE".
            'unit' => $j->unitKey(),
            'unit_label' => $j->unitLevel() === Position::NO_UNIT ? null : $j->unitLabel(),
            'department_id' => $j->departmentOf()?->id,
            'section_id' => $j->section_id,
            'single_holder' => (bool) $j->jobLevel?->isSingleHolder(),
            'holders' => $holders->map(fn (Employee $k) => ['id' => $k->id, 'employee_number' => $k->employee_number, 'name' => $k->name])->values()->all(),
        ];
    }

    // --- the list on the right of the plan --------------------------------------------

    /** What can be put in this location's plan, each marked when it is in already. */
    public function palette(): array
    {
        $ho = $this->location->isHeadOffice();
        $heads = $this->positions->filter(fn (Position $j) => $j->jobLevel && in_array($j->jobLevel->source, ['directorate', 'division', 'department', 'section'], true));
        // How many times the unit's head is in the plan (at the site and in Sub Lokasi).
        $inPlan = fn (string $level, int $id) => $heads->filter(fn (Position $j) => $j->jobLevel->source === $level && $j->{Position::UNIT_COLUMNS[$level]} === $id)->count();

        return [
            'sub_locations' => $this->subLocations->map(fn (SubLocation $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'in_plan' => $this->boxShown($s),
            ])->values(),
            'directorates' => $ho ? Directorate::orderBy('sort_order')->get()->map(fn (Directorate $b) => ['id' => $b->id, 'name' => $b->name, 'in_plan' => $inPlan('directorate', $b->id)]) : [],
            'divisions' => $ho ? Division::with('directorate')->orderBy('sort_order')->get()->map(fn (Division $d) => [
                'id' => $d->id, 'name' => $d->name, 'group' => $d->directorate?->name, 'in_plan' => $inPlan('division', $d->id),
            ]) : [],
            'departments' => Department::with('division')->orderBy('division_id')->orderBy('sort_order')->get()->map(fn (Department $d) => [
                'id' => $d->id, 'name' => $d->name, 'group' => $d->division?->name, 'in_plan' => $inPlan('department', $d->id),
            ]),
            'sections' => Section::with('department')->orderBy('department_id')->orderBy('sort_order')->get()->map(fn (Section $s) => [
                'id' => $s->id, 'name' => $s->name, 'department_id' => $s->department_id, 'group' => $s->department?->name, 'in_plan' => $inPlan('section', $s->id),
            ]),
            // Layer 2 and 1: titled by a Nama Jabatan, several can share a position.
            'layers' => JobLevel::where('source', 'discipline')->where('layer', '<', JobLevel::SINGLE_HOLDER_MIN_LAYER)
                ->orderByDesc('layer')->orderBy('name')->get(['id', 'name', 'layer', 'title_prefix', 'title_prefix_head_office'])
                ->groupBy('layer')->map(fn ($groups, $layer) => ['layer' => (int) $layer, 'job_levels' => $groups->values()])->values(),
            'unit_job_levels' => self::unitSubGroups($this->location),
            'discipline' => LookupValues::values(PositionComposer::BIDANG),
        ];
    }

    /**
     * Which SubGroup heads a unit dropped into the plan: at Head Office the more
     * senior / "HO" one (Manager, Section Head HO), at a site the other (Dept
     * Head, Section Head Site).
     *
     * @return array<string, int|null> level => SubGroup id
     */
    public static function unitSubGroups(WorkLocation $location): array
    {
        $ho = $location->isHeadOffice();
        $out = [];
        foreach (['directorate', 'division', 'department', 'section'] as $level) {
            $groups = JobLevel::where('source', $level)->get();
            $named = $groups->filter(fn (JobLevel $g) => preg_match($ho ? '/\bho\b/i' : '/\bsite\b/i', $g->name));
            $out[$level] = ($named->isNotEmpty() ? $named : $groups)
                ->sortBy(fn (JobLevel $g) => $ho ? -$g->layer : $g->layer)->first()?->id;
        }

        return $out;
    }

    // --- people (Struktur Organisasi) ------------------------------------------------------

    /** Everyone working here who is not exited: placed or waiting, with what they are grouped by. */
    public function people(): Collection
    {
        return Employee::where('work_location_id', $this->location->id)
            ->where('status', '!=', Employee::STATUS_TERMINATED)
            ->with(['jobLevel', 'department', 'section.department', 'subLocation', 'position'])
            ->orderBy('name')->get()
            ->map(fn (Employee $k) => [
                'id' => $k->id,
                'employee_number' => $k->employee_number,
                'name' => $k->name,
                'status' => $k->status,
                'job_level_id' => $k->job_level_id,
                'job_level' => $k->jobLevel?->name,
                'layer' => $k->jobLevel?->layer,
                'department' => $k->placementDepartment()?->name,
                'section' => $k->section?->name,
                'discipline' => $k->discipline,
                'sub_location_id' => $k->sub_location_id,
                'sub_location' => $k->subLocation?->name,
                'position_id' => $k->position_id,
                'position_name' => $k->position?->name,
            ])->values();
    }

    /** @return list<array<string, mixed>> for the Project Manager / Deputy choice */
    public static function candidates(?JobLevel $sub): array
    {
        if (! $sub) {
            return [];
        }

        return Employee::where('job_level_id', $sub->id)->where('status', '!=', Employee::STATUS_TERMINATED)
            ->with(['workLocation', 'position'])->orderBy('name')->get()
            ->map(fn (Employee $k) => [
                'id' => $k->id,
                'employee_number' => $k->employee_number,
                'name' => $k->name,
                'work_location' => $k->workLocation?->name,
                'position' => $k->position?->name,
            ])->values()->all();
    }
}
