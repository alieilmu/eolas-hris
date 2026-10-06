<?php

namespace App\Support\Org;

use App\Models\Jabatan;
use App\Models\Karyawan;
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

        $placement = array_intersect_key($op['penempatan'] ?? [], array_flip(JabatanComposer::KEYS));
        [$attributes, $error] = JabatanComposer::compose($placement);
        if (! $attributes) {
            $this->fail("Jabatan baru: {$error}");
        }

        $parent = $this->optionalJabatan($op['parent'] ?? null);
        $place = $attributes['sub_location_id'] ? ' di '.SubLocation::find($attributes['sub_location_id'])?->name : '';
        [$jabatan] = JabatanComposer::findOrCreate($placement);
        if (! $jabatan->wasRecentlyCreated) {
            $this->fail("Jabatan \"{$jabatan->name}\" sudah ada{$place} di lokasi ini.");
        }

        if ($parent) {
            if ($error = $jabatan->load('subGroup')->atasanLayerError($parent->load('subGroup'))) {
                $this->fail($error);
            }
            $jabatan->update(['parent_jabatan_id' => $parent->id]);
        }

        $this->created[$tempId] = $jabatan->id;
    }

    /**
     * Reports to another position, or (parent null) to nobody. With a
     * "sub_location" key the position also moves into that Sub Lokasi (null:
     * the work location itself) — alone: see moveToSubLocation().
     */
    private function reparent(array $op): void
    {
        $jabatan = $this->jabatan($op['jabatan'] ?? null)->load('subGroup');
        $parent = $this->optionalJabatan($op['parent'] ?? null)?->load('subGroup');

        if ($parent) {
            // Walk up from the new superior: meeting the moved position means a loop.
            for ($cursor = $parent, $guard = 0; $cursor && $guard < 1000; $cursor = $cursor->parent, $guard++) {
                if ($cursor->id === $jabatan->id) {
                    $this->fail("\"{$jabatan->name}\" tidak bisa dipindah ke bawah bawahannya sendiri (\"{$parent->name}\").");
                }
            }
            if ($error = $jabatan->atasanLayerError($parent)) {
                $this->fail($error);
            }
        }

        if (array_key_exists('sub_location', $op)) {
            $this->moveToSubLocation($jabatan, $op['sub_location'] ? (int) $op['sub_location'] : null);
        }
        $team = $jabatan->withTeam();

        // Titled by a Nama Jabatan: joins the unit of its superior, together with the
        // members of its team that were in its old unit.
        if ($parent && $jabatan->subGroup?->sumber === 'bidang' && $parent->unitLevel() !== Jabatan::NO_UNIT
            && $parent->unitColumnValues() !== $jabatan->unitColumnValues()) {
            $old = $jabatan->unitColumnValues();
            $followers = collect($team)->filter(fn (Jabatan $j) => $j->subGroup?->sumber === 'bidang' && $j->unitColumnValues() === $old);
            Jabatan::whereIn('id', $followers->pluck('id'))->update($parent->unitColumnValues());
            // Their holders' placement follows (it is what lists group them by).
            Jabatan::whereIn('id', $followers->pluck('id'))->get()->each(
                fn (Jabatan $j) => Karyawan::where('jabatan_id', $j->id)->update(JabatanComposer::employeePlacementOf($j)),
            );
        }

        $jabatan->update(['parent_jabatan_id' => $parent?->id]);
    }

    /**
     * Moves a position — with whoever holds it — to a Sub Lokasi (or back to the
     * site). Its team stays where it is, in its own departemen: under the same
     * position left behind there, vacant if it has to be made. So a departemen
     * can be at the site and at a Sub Lokasi at once (e.g. its Dept Head is
     * assigned to the Sub Lokasi; the HC staff stay with Departemen HC).
     */
    private function moveToSubLocation(Jabatan $jabatan, ?int $subLocationId): void
    {
        $destination = $subLocationId ? SubLocation::find($subLocationId) : null;
        if ($subLocationId && (! $destination || $destination->work_location_id !== $jabatan->work_location_id)) {
            $this->fail("Sub Lokasi itu bukan bagian dari lokasi \"{$jabatan->name}\".");
        }
        $from = $jabatan->sub_location_id;
        if ($from === $subLocationId) {
            return;
        }

        $samePlace = fn (?int $place) => Jabatan::where('work_location_id', $jabatan->work_location_id)
            ->when($place, fn ($q) => $q->where('sub_location_id', $place), fn ($q) => $q->whereNull('sub_location_id'))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($jabatan->name)])
            ->where('id', '!=', $jabatan->id);
        if ($samePlace($subLocationId)->exists()) {
            $where = $destination ? "Sub Lokasi {$destination->name}" : 'lokasi induknya';
            $this->fail("Jabatan \"{$jabatan->name}\" sudah ada di {$where}. Untuk memindahkan orangnya, pakai Mutasi di Mode MPP.");
        }

        $staying = $jabatan->children()->get()->filter(fn (Jabatan $c) => $c->sub_location_id === $from);
        if ($staying->isNotEmpty()) {
            $left = $samePlace($from)->first() ?? Jabatan::create([
                ...$jabatan->only(['sub_group_id', 'work_location_id', 'bidang', 'name', ...array_values(Jabatan::UNIT_COLUMNS)]),
                'sub_location_id' => $from,
                'parent_jabatan_id' => $jabatan->parent_jabatan_id,
                'order' => (Jabatan::max('order') ?? 0) + 1,
            ]);
            Jabatan::whereIn('id', $staying->pluck('id'))->update(['parent_jabatan_id' => $left->id]);
        }

        $jabatan->update(['sub_location_id' => $subLocationId]);
        // Whoever holds it now works there.
        Karyawan::where('jabatan_id', $jabatan->id)->update(['sub_location_id' => $subLocationId]);
    }

    private function delete(array $op): void
    {
        $jabatan = $this->jabatan($op['jabatan'] ?? null);

        $names = $jabatan->karyawans()->orderBy('name')->pluck('name');
        if ($names->isNotEmpty()) {
            $list = $names->take(3)->join(', ').($names->count() > 3 ? ' dan '.($names->count() - 3).' lainnya' : '');
            $this->fail("\"{$jabatan->name}\" tidak bisa dihapus: masih tercatat pada {$list} (termasuk yang keluar). Copot karyawannya dulu di Struktur Organisasi.");
        }
        if ($n = $jabatan->children()->count()) {
            $this->fail("\"{$jabatan->name}\" masih punya {$n} bawahan. Pindahkan atau hapus bawahannya dulu.");
        }
        if ($box = SubLocation::where('parent_jabatan_id', $jabatan->id)->value('name')) {
            $this->fail("Sub Lokasi {$box} masih berada di bawah \"{$jabatan->name}\". Pindahkan dulu.");
        }

        $jabatan->delete();
    }

    /** Hangs a Sub Lokasi box under a position of its work location; parent null takes it out of the plan. */
    private function placeSubLocation(array $op): void
    {
        $subLocation = SubLocation::find($op['sub_location'] ?? 0) ?? $this->fail('Sub Lokasi tidak ditemukan.');
        $parent = $this->optionalJabatan($op['parent'] ?? null);

        if ($parent && $parent->work_location_id !== $subLocation->work_location_id) {
            $this->fail("Sub Lokasi {$subLocation->name} hanya bisa berada di bawah jabatan di lokasinya sendiri.");
        }
        if ($parent && $parent->sub_location_id === $subLocation->id) {
            $this->fail("Sub Lokasi {$subLocation->name} tidak bisa berada di bawah jabatan di dalam dirinya sendiri.");
        }
        if (! $parent && ($n = $subLocation->jabatans()->count())) {
            $this->fail("Sub Lokasi {$subLocation->name} masih berisi {$n} jabatan. Hapus atau pindahkan jabatannya dulu.");
        }

        $subLocation->update(['parent_jabatan_id' => $parent?->id]);
    }

    private function jabatan(mixed $ref): Jabatan
    {
        return $this->optionalJabatan($ref) ?? $this->fail('Jabatan yang diubah tidak ditemukan.');
    }

    private function optionalJabatan(mixed $ref): ?Jabatan
    {
        if ($ref === null || $ref === '') {
            return null;
        }

        $id = is_string($ref) && str_starts_with($ref, 'temp-') ? ($this->created[$ref] ?? null) : (int) $ref;
        $jabatan = $id ? Jabatan::find($id) : null;

        return $jabatan ?? $this->fail('Jabatan yang dirujuk tidak ditemukan — mungkin sudah dihapus. Muat ulang halaman lalu ulangi.');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['ops' => $message]);
    }
}
