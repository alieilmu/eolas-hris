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
    private Collection $jabatans;

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
        $this->jabatans = Jabatan::with(['subGroup', ...Jabatan::UNIT_RELATIONS])
            ->where('work_location_id', $location->id)
            ->orderBy('order')->orderBy('id')
            ->get()->keyBy('id');
        $this->childrenOf = $this->jabatans->filter(fn (Jabatan $j) => $this->jabatans->has($j->parent_jabatan_id))->groupBy('parent_jabatan_id');
        $this->subLocations = SubLocation::where('work_location_id', $location->id)->orderBy('order')->orderBy('name')->get()->keyBy('id');
        $this->holders = Karyawan::aktif()->whereIn('jabatan_id', $this->jabatans->keys())->orderBy('name')
            ->get(['id', 'nrp', 'name', 'jabatan_id', 'sub_group_id'])->groupBy('jabatan_id');
        $this->sitesUnder = collect();
    }

    // --- the Project Manager ------------------------------------------------------

    /** The SubGroup of a site's head: named after the work location, highest layer, not a deputy. */
    public static function pmSubGroup(): ?SubGroup
    {
        return SubGroup::where('sumber', 'work_location')->orderByDesc('layer')->orderBy('nama')->get()
            ->sortBy(fn (SubGroup $g) => str_contains(mb_strtolower($g->nama), 'deputy') ? 1 : 0)
            ->first();
    }

    public static function dpmSubGroup(): ?SubGroup
    {
        return SubGroup::where('sumber', 'work_location')->get()->first(fn (SubGroup $g) => str_contains(mb_strtolower($g->nama), 'deputy'));
    }

    public static function pmOf(WorkLocation $location): ?Jabatan
    {
        $pm = self::pmSubGroup();

        return $pm ? Jabatan::where('work_location_id', $location->id)->where('sub_group_id', $pm->id)->whereNull('sub_location_id')->orderBy('id')->first() : null;
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

        $roots = $this->jabatans
            ->filter(fn (Jabatan $j) => ! $j->sub_location_id && ! $this->jabatans->has($j->parent_jabatan_id))
            ->sortBy(fn (Jabatan $j) => [$this->isPm($j) ? 0 : 1, -($j->subGroup?->layer ?? 0), $j->name])
            ->map(fn (Jabatan $j) => $this->jabatanNode($j, 0))
            ->values()->all();

        $looseBoxes = $this->subLocations
            ->filter(fn (SubLocation $s) => ! $this->jabatans->has($s->parent_jabatan_id) && $this->boxShown($s))
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
    private function jabatanNode(Jabatan $j, int $depth): array
    {
        $children = [];
        if ($depth < self::MAX_DEPTH) {
            $children = $this->childrenOf->get($j->id, collect())
                ->filter(fn (Jabatan $c) => $c->sub_location_id === $j->sub_location_id || $c->sub_location_id === null)
                ->sortBy(fn (Jabatan $c) => [-($c->subGroup?->layer ?? 0), $c->name])
                ->map(fn (Jabatan $c) => $this->jabatanNode($c, $depth + 1))
                ->values()->all();
            $boxes = $this->subLocations->filter(fn (SubLocation $s) => $s->parent_jabatan_id === $j->id)
                ->map(fn (SubLocation $s) => $this->boxNode($s, $depth + 1))->values()->all();
            $children = [...$children, ...$boxes, ...$this->sitesUnder->get($j->id, [])];
        }

        return [...self::jabatanData($j, $this->holders->get($j->id, collect())), 'children' => $children];
    }

    /** @return array<string, mixed> */
    private function boxNode(SubLocation $s, int $depth): array
    {
        $inside = $this->jabatans->filter(fn (Jabatan $j) => $j->sub_location_id === $s->id
            && (! $this->jabatans->has($j->parent_jabatan_id) || $this->jabatans->get($j->parent_jabatan_id)->sub_location_id !== $s->id));

        return [
            'kind' => 'sub_location',
            'key' => "s{$s->id}",
            'id' => $s->id,
            'name' => $s->name,
            'parent_jabatan_id' => $s->parent_jabatan_id,
            'children' => $depth < self::MAX_DEPTH
                ? $inside->sortBy(fn (Jabatan $j) => [-($j->subGroup?->layer ?? 0), $j->name])->map(fn (Jabatan $j) => $this->jabatanNode($j, $depth + 1))->values()->all()
                : [],
        ];
    }

    private function boxShown(SubLocation $s): bool
    {
        return $s->parent_jabatan_id !== null || $this->jabatans->contains('sub_location_id', $s->id);
    }

    private function isPm(Jabatan $j): bool
    {
        return $j->subGroup?->sumber === 'work_location' && ! str_contains(mb_strtolower($j->subGroup->nama), 'deputy');
    }

    /** HO: each site's Project Manager card, under the HO position it reports to (key 0 = not linked). */
    private function loadSites(): void
    {
        if ($this->sitesUnder->isNotEmpty()) {
            return;
        }
        $sites = WorkLocation::where('id', '!=', $this->location->id)->where('type', '!=', WorkLocation::TYPE_HEAD_OFFICE)->orderBy('order')->get();
        $counts = Jabatan::selectRaw('work_location_id, COUNT(*) as n')->groupBy('work_location_id')->pluck('n', 'work_location_id');

        foreach ($sites as $site) {
            $pm = self::pmOf($site)?->load('subGroup', ...Jabatan::UNIT_RELATIONS);
            $parent = $pm && $this->jabatans->has($pm->parent_jabatan_id) ? $pm->parent_jabatan_id : 0;
            $holders = $pm ? Karyawan::aktif()->where('jabatan_id', $pm->id)->orderBy('name')->get(['id', 'nrp', 'name', 'jabatan_id', 'sub_group_id']) : collect();
            $card = [
                'kind' => 'site',
                'key' => "w{$site->id}",
                'work_location' => ['id' => $site->id, 'name' => $site->name, 'kode' => $site->kode],
                'jabatan' => $pm ? self::jabatanData($pm, $holders) : null,
                'jabatan_count' => (int) ($counts[$site->id] ?? 0),
                'children' => [],
            ];
            $this->sitesUnder->put($parent, [...$this->sitesUnder->get($parent, []), $card]);
        }
    }

    /** @return array<string, mixed> what a card shows of a position */
    public static function jabatanData(Jabatan $j, Collection $holders): array
    {
        return [
            'kind' => 'jabatan',
            'key' => "j{$j->id}",
            'id' => $j->id,
            'name' => $j->name,
            'sub_group_id' => $j->sub_group_id,
            'sub_group' => $j->subGroup?->nama,
            'layer' => $j->subGroup?->layer,
            'sumber' => $j->subGroup?->sumber,
            'work_location_id' => $j->work_location_id,
            'sub_location_id' => $j->sub_location_id,
            'parent_jabatan_id' => $j->parent_jabatan_id,
            // "section:5" / "none" and its label, e.g. "Section WORKSHOP · MAINTENANCE".
            'unit' => $j->unitKey(),
            'unit_label' => $j->unitLevel() === Jabatan::NO_UNIT ? null : $j->unitLabel(),
            'departemen_id' => $j->departemenOf()?->id,
            'section_id' => $j->section_id,
            'single_holder' => (bool) $j->subGroup?->isSingleHolder(),
            'holders' => $holders->map(fn (Karyawan $k) => ['id' => $k->id, 'nrp' => $k->nrp, 'name' => $k->name])->values()->all(),
        ];
    }

    // --- the list on the right of the plan --------------------------------------------

    /** What can be put in this location's plan, each marked when it is in already. */
    public function palette(): array
    {
        $ho = $this->location->isHeadOffice();
        $heads = $this->jabatans->filter(fn (Jabatan $j) => $j->subGroup && in_array($j->subGroup->sumber, ['bod', 'divisi', 'departemen', 'section'], true));
        // How many times the unit's head is in the plan (at the site and in Sub Lokasi).
        $inPlan = fn (string $level, int $id) => $heads->filter(fn (Jabatan $j) => $j->subGroup->sumber === $level && $j->{Jabatan::UNIT_COLUMNS[$level]} === $id)->count();

        return [
            'sub_locations' => $this->subLocations->map(fn (SubLocation $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'in_plan' => $this->boxShown($s),
            ])->values(),
            'bods' => $ho ? Bod::orderBy('order')->get()->map(fn (Bod $b) => ['id' => $b->id, 'name' => $b->name, 'in_plan' => $inPlan('bod', $b->id)]) : [],
            'divisis' => $ho ? Divisi::with('bod')->orderBy('order')->get()->map(fn (Divisi $d) => [
                'id' => $d->id, 'name' => $d->name, 'group' => $d->bod?->name, 'in_plan' => $inPlan('divisi', $d->id),
            ]) : [],
            'departemens' => Departemen::with('divisi')->orderBy('divisi_id')->orderBy('order')->get()->map(fn (Departemen $d) => [
                'id' => $d->id, 'name' => $d->name, 'group' => $d->divisi?->name, 'in_plan' => $inPlan('departemen', $d->id),
            ]),
            'sections' => Section::with('departemen')->orderBy('departemen_id')->orderBy('order')->get()->map(fn (Section $s) => [
                'id' => $s->id, 'name' => $s->name, 'departemen_id' => $s->departemen_id, 'group' => $s->departemen?->name, 'in_plan' => $inPlan('section', $s->id),
            ]),
            // Layer 2 and 1: titled by a Nama Jabatan, several can share a position.
            'layers' => SubGroup::where('sumber', 'bidang')->where('layer', '<', SubGroup::SINGLE_HOLDER_MIN_LAYER)
                ->orderByDesc('layer')->orderBy('nama')->get(['id', 'nama', 'layer', 'gelar', 'gelar_ho'])
                ->groupBy('layer')->map(fn ($groups, $layer) => ['layer' => (int) $layer, 'sub_groups' => $groups->values()])->values(),
            'unit_sub_groups' => self::unitSubGroups($this->location),
            'bidang' => MasterOptions::values(JabatanComposer::BIDANG),
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
        foreach (['bod', 'divisi', 'departemen', 'section'] as $level) {
            $groups = SubGroup::where('sumber', $level)->get();
            $named = $groups->filter(fn (SubGroup $g) => preg_match($ho ? '/\bho\b/i' : '/\bsite\b/i', $g->nama));
            $out[$level] = ($named->isNotEmpty() ? $named : $groups)
                ->sortBy(fn (SubGroup $g) => $ho ? -$g->layer : $g->layer)->first()?->id;
        }

        return $out;
    }

    // --- people (Struktur Organisasi) ------------------------------------------------------

    /** Everyone working here who is not exited: placed or waiting, with what they are grouped by. */
    public function people(): Collection
    {
        return Karyawan::where('work_location_id', $this->location->id)
            ->where('status', '!=', Karyawan::STATUS_KELUAR)
            ->with(['subGroup', 'departemen', 'section.departemen', 'subLocation', 'jabatan'])
            ->orderBy('name')->get()
            ->map(fn (Karyawan $k) => [
                'id' => $k->id,
                'nrp' => $k->nrp,
                'name' => $k->name,
                'status' => $k->status,
                'sub_group_id' => $k->sub_group_id,
                'sub_group' => $k->subGroup?->nama,
                'layer' => $k->subGroup?->layer,
                'departemen' => $k->placementDepartemen()?->name,
                'section' => $k->section?->name,
                'bidang' => $k->bidang,
                'sub_location_id' => $k->sub_location_id,
                'sub_location' => $k->subLocation?->name,
                'jabatan_id' => $k->jabatan_id,
                'jabatan_name' => $k->jabatan?->name,
            ])->values();
    }

    /** @return list<array<string, mixed>> for the Project Manager / Deputy choice */
    public static function candidates(?SubGroup $sub): array
    {
        if (! $sub) {
            return [];
        }

        return Karyawan::where('sub_group_id', $sub->id)->where('status', '!=', Karyawan::STATUS_KELUAR)
            ->with(['workLocation', 'jabatan'])->orderBy('name')->get()
            ->map(fn (Karyawan $k) => [
                'id' => $k->id,
                'nrp' => $k->nrp,
                'name' => $k->name,
                'work_location' => $k->workLocation?->name,
                'jabatan' => $k->jabatan?->name,
            ])->values()->all();
    }
}
