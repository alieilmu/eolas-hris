<?php

namespace App\Support\Organization;

use App\Models\Position;
use App\Models\Employee;
use App\Models\SubLocation;
use Illuminate\Validation\ValidationException;

/**
 * Applies changes made in the Plan Struktur Organisasi, in order: new
 * positions, moves, removals, and where a Sub Lokasi box hangs. Positions
 * created in the same batch are referred to by their "temp-…" id. Run inside a
 * transaction: the first refused step throws and nothing is kept.
 *
 * Reporting lines are free, except that a superior may not be of a lower
 * layer. A position named after its unit (Dept Head X, Section Head Y) keeps
 * that unit wherever it reports; one titled by a Nama Jabatan (Group Leader,
 * staff, operator) joins the unit of its new superior, with its team.
 */
class OrgChartChanges
{
    /** @var array<string, int> temp id => real id */
    private array $created = [];

    /** @param  list<array<string, mixed>>  $ops */
    public function apply(array $ops): int
    {
        foreach ($ops as $op) {
            match ($op['type'] ?? null) {
                'create' => $this->create($op),
                'reparent' => $this->reparent($op),
                'delete' => $this->delete($op),
                'sub_location' => $this->placeSubLocation($op),
                default => $this->fail('Jenis perubahan tidak dikenal.'),
            };
        }

        return count($ops);
    }

    /** @return array<string, int> temp id => id of the positions created */
    public function created(): array
    {
        return $this->created;
    }

    /** A new (vacant) position from a placement, optionally under a superior. */
    private function create(array $op): void
    {
        $tempId = (string) ($op['temp_id'] ?? '');
        if (! str_starts_with($tempId, 'temp-')) {
            $this->fail('Jabatan baru: id sementara tidak valid.');
        }

        $placement = array_intersect_key($op['placement'] ?? [], array_flip(PositionComposer::KEYS));
        [$attributes, $error] = PositionComposer::compose($placement);
        if (! $attributes) {
            $this->fail("Jabatan baru: {$error}");
        }

        $parent = $this->optionalPosition($op['parent'] ?? null);
        $place = $attributes['sub_location_id'] ? ' di '.SubLocation::find($attributes['sub_location_id'])?->name : '';
        [$position] = PositionComposer::findOrCreate($placement);
        if (! $position->wasRecentlyCreated) {
            $this->fail("Jabatan \"{$position->name}\" sudah ada{$place} di lokasi ini.");
        }

        if ($parent) {
            if ($error = $position->load('jobLevel')->superiorLayerError($parent->load('jobLevel'))) {
                $this->fail($error);
            }
            $position->update(['parent_position_id' => $parent->id]);
        }

        $this->created[$tempId] = $position->id;
    }

    /**
     * Reports to another position, or (parent null) to nobody. With a
     * "sub_location" key the position also moves into that Sub Lokasi (null:
     * the work location itself) — alone: see moveToSubLocation().
     */
    private function reparent(array $op): void
    {
        $position = $this->position($op['position'] ?? null)->load('jobLevel');
        $parent = $this->optionalPosition($op['parent'] ?? null)?->load('jobLevel');

        if ($parent) {
            // Walk up from the new superior: meeting the moved position means a loop.
            for ($cursor = $parent, $guard = 0; $cursor && $guard < 1000; $cursor = $cursor->parent, $guard++) {
                if ($cursor->id === $position->id) {
                    $this->fail("\"{$position->name}\" tidak bisa dipindah ke bawah bawahannya sendiri (\"{$parent->name}\").");
                }
            }
            if ($error = $position->superiorLayerError($parent)) {
                $this->fail($error);
            }
        }

        if (array_key_exists('sub_location', $op)) {
            $this->moveToSubLocation($position, $op['sub_location'] ? (int) $op['sub_location'] : null);
        }
        $team = $position->withTeam();

        // Titled by a Nama Jabatan: joins the unit of its superior, together with the
        // members of its team that were in its old unit.
        if ($parent && $position->jobLevel?->source === 'discipline' && $parent->unitLevel() !== Position::NO_UNIT
            && $parent->unitColumnValues() !== $position->unitColumnValues()) {
            $old = $position->unitColumnValues();
            $followers = collect($team)->filter(fn (Position $j) => $j->jobLevel?->source === 'discipline' && $j->unitColumnValues() === $old);
            Position::whereIn('id', $followers->pluck('id'))->update($parent->unitColumnValues());
            // Their holders' placement follows (it is what lists group them by).
            Position::whereIn('id', $followers->pluck('id'))->get()->each(
                fn (Position $j) => Employee::where('position_id', $j->id)->update(PositionComposer::employeePlacementOf($j)),
            );
        }

        $position->update(['parent_position_id' => $parent?->id]);
    }

    /**
     * Moves a position — with whoever holds it — to a Sub Lokasi (or back to the
     * site). Its team stays where it is, in its own departemen: under the same
     * position left behind there, vacant if it has to be made. So a departemen
     * can be at the site and at a Sub Lokasi at once (e.g. its Dept Head is
     * assigned to the Sub Lokasi; the HC staff stay with Departemen HC).
     */
    private function moveToSubLocation(Position $position, ?int $subLocationId): void
    {
        $destination = $subLocationId ? SubLocation::find($subLocationId) : null;
        if ($subLocationId && (! $destination || $destination->work_location_id !== $position->work_location_id)) {
            $this->fail("Sub Lokasi itu bukan bagian dari lokasi \"{$position->name}\".");
        }
        $from = $position->sub_location_id;
        if ($from === $subLocationId) {
            return;
        }

        $samePlace = fn (?int $place) => Position::where('work_location_id', $position->work_location_id)
            ->when($place, fn ($q) => $q->where('sub_location_id', $place), fn ($q) => $q->whereNull('sub_location_id'))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($position->name)])
            ->where('id', '!=', $position->id);
        if ($samePlace($subLocationId)->exists()) {
            $where = $destination ? "Sub Lokasi {$destination->name}" : 'lokasi induknya';
            $this->fail("Jabatan \"{$position->name}\" sudah ada di {$where}. Untuk memindahkan orangnya, pakai Mutasi di Mode MPP.");
        }

        $staying = $position->children()->get()->filter(fn (Position $c) => $c->sub_location_id === $from);
        if ($staying->isNotEmpty()) {
            $left = $samePlace($from)->first() ?? Position::create([
                ...$position->only(['job_level_id', 'work_location_id', 'discipline', 'name', ...array_values(Position::UNIT_COLUMNS)]),
                'sub_location_id' => $from,
                'parent_position_id' => $position->parent_position_id,
                'sort_order' => (Position::max('sort_order') ?? 0) + 1,
            ]);
            Position::whereIn('id', $staying->pluck('id'))->update(['parent_position_id' => $left->id]);
        }

        $position->update(['sub_location_id' => $subLocationId]);
        // Whoever holds it now works there.
        Employee::where('position_id', $position->id)->update(['sub_location_id' => $subLocationId]);
    }

    private function delete(array $op): void
    {
        $position = $this->position($op['position'] ?? null);

        $names = $position->employees()->orderBy('name')->pluck('name');
        if ($names->isNotEmpty()) {
            $list = $names->take(3)->join(', ').($names->count() > 3 ? ' dan '.($names->count() - 3).' lainnya' : '');
            $this->fail("\"{$position->name}\" tidak bisa dihapus: masih tercatat pada {$list} (termasuk yang keluar). Copot karyawannya dulu di Struktur Organisasi.");
        }
        if ($n = $position->children()->count()) {
            $this->fail("\"{$position->name}\" masih punya {$n} bawahan. Pindahkan atau hapus bawahannya dulu.");
        }
        if ($box = SubLocation::where('parent_position_id', $position->id)->value('name')) {
            $this->fail("Sub Lokasi {$box} masih berada di bawah \"{$position->name}\". Pindahkan dulu.");
        }

        $position->delete();
    }

    /** Hangs a Sub Lokasi box under a position of its work location; parent null takes it out of the plan. */
    private function placeSubLocation(array $op): void
    {
        $subLocation = SubLocation::find($op['sub_location'] ?? 0) ?? $this->fail('Sub Lokasi tidak ditemukan.');
        $parent = $this->optionalPosition($op['parent'] ?? null);

        if ($parent && $parent->work_location_id !== $subLocation->work_location_id) {
            $this->fail("Sub Lokasi {$subLocation->name} hanya bisa berada di bawah jabatan di lokasinya sendiri.");
        }
        if ($parent && $parent->sub_location_id === $subLocation->id) {
            $this->fail("Sub Lokasi {$subLocation->name} tidak bisa berada di bawah jabatan di dalam dirinya sendiri.");
        }
        if (! $parent && ($n = $subLocation->positions()->count())) {
            $this->fail("Sub Lokasi {$subLocation->name} masih berisi {$n} jabatan. Hapus atau pindahkan jabatannya dulu.");
        }

        $subLocation->update(['parent_position_id' => $parent?->id]);
    }

    private function position(mixed $ref): Position
    {
        return $this->optionalPosition($ref) ?? $this->fail('Jabatan yang diubah tidak ditemukan.');
    }

    private function optionalPosition(mixed $ref): ?Position
    {
        if ($ref === null || $ref === '') {
            return null;
        }

        $id = is_string($ref) && str_starts_with($ref, 'temp-') ? ($this->created[$ref] ?? null) : (int) $ref;
        $position = $id ? Position::find($id) : null;

        return $position ?? $this->fail('Jabatan yang dirujuk tidak ditemukan — mungkin sudah dihapus. Muat ulang halaman lalu ulangi.');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['ops' => $message]);
    }
}
