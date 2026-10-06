<?php

namespace App\Http\Controllers\Manpower;

use App\Http\Controllers\Controller;
use App\Models\Jabatan;
use App\Models\Karyawan;
use App\Models\SubLocation;
use App\Support\Access\AccessScope;
use App\Support\Karyawan\ProfileRules;
use App\Support\Org\JabatanComposer;
use App\Support\Org\Penempatan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Where an employee works and in which position: "Ubah Penempatan", and the chart's "Kelola". */
class KaryawanPenempatanController extends Controller
{
    /** A new placement: work location + SubGroup + unit / Nama Jabatan. Fills the plan's matching position, if any. */
    public function update(Request $request, Karyawan $karyawan): RedirectResponse
    {
        $this->assertNotExited($karyawan, 'penempatan');

        ['attributes' => $placement, 'note' => $note] = Penempatan::resolve($request, $karyawan);
        DB::transaction(fn () => $karyawan->update([...$placement, 'status' => Karyawan::STATUS_AKTIF]));
        $jabatan = $karyawan->jabatan()->with('workLocation')->first();

        Inertia::flash('success', $jabatan
            ? "{$karyawan->name} sekarang menjabat {$jabatan->name} ({$jabatan->workLocation?->name})."
            : "Penempatan {$karyawan->name} disimpan. {$note}");

        return back();
    }

    /** Moves an active or inactive employee into an existing position (and its work location) and makes them active there. */
    public function assign(Request $request, Karyawan $karyawan): RedirectResponse
    {
        $data = $request->validate([
            'jabatan_id' => ['required', 'integer', 'exists:jabatans,id'],
        ], [], ['jabatan_id' => 'Jabatan']);

        $this->assertNotExited($karyawan, 'jabatan_id');
        AccessScope::assertWorkLocation(Jabatan::find($data['jabatan_id'])?->work_location_id, 'jabatan_id');
        if ($error = ProfileRules::capacityError($data['jabatan_id'], $karyawan->id)) {
            throw ValidationException::withMessages(['jabatan_id' => $error]);
        }

        $jabatan = Jabatan::find($data['jabatan_id']);
        // Whoever fills a position takes its placement: its work location, SubGroup, unit, Sub Lokasi.
        $karyawan->update([...JabatanComposer::employeePlacementOf($jabatan), 'jabatan_id' => $jabatan->id, 'status' => Karyawan::STATUS_AKTIF]);

        Inertia::flash('success', "{$karyawan->name} sekarang menjabat {$jabatan->name}.");

        return back();
    }

    /**
     * Assigns an employee to a Sub Lokasi Kerja (dropped on its box, or "Mutasi"):
     * they fill the position matching their placement there, created if it is new.
     */
    public function subLokasi(Request $request, Karyawan $karyawan): RedirectResponse
    {
        $data = $request->validate(['sub_location_id' => ['required', 'integer', 'exists:sub_locations,id']], [], ['sub_location_id' => 'Sub Lokasi']);
        $this->assertNotExited($karyawan, 'penempatan');
        if (! $karyawan->sub_group_id) {
            throw ValidationException::withMessages(['penempatan' => "{$karyawan->name} belum punya SubGroup. Lengkapi penempatannya dulu (Ubah Penempatan)."]);
        }

        $subLocation = SubLocation::findOrFail($data['sub_location_id']);
        AccessScope::assertWorkLocation($subLocation->work_location_id, 'penempatan');
        $placement = [...$karyawan->placement(), 'work_location_id' => $subLocation->work_location_id, 'sub_location_id' => $subLocation->id];

        $jabatan = DB::transaction(function () use ($placement, $karyawan) {
            [$jabatan, $error] = JabatanComposer::findForEmployee($placement);
            if (! $jabatan) {
                throw ValidationException::withMessages(['penempatan' => $error ?? 'Jabatan tidak ditemukan.']);
            }
            if ($jabatan->id !== $karyawan->jabatan_id && ($full = ProfileRules::capacityError($jabatan->id, $karyawan->id))) {
                throw ValidationException::withMessages(['penempatan' => $full]);
            }
            $karyawan->update([...JabatanComposer::employeePlacementOf($jabatan), 'jabatan_id' => $jabatan->id, 'status' => Karyawan::STATUS_AKTIF]);

            return $jabatan;
        });

        Inertia::flash('success', "{$karyawan->name} sekarang menjabat {$jabatan->name} di Sub Lokasi {$subLocation->name}.");

        return back();
    }

    /** Takes an employee off their position; they keep their placement and wait in the Struktur Organisasi list. */
    public function copot(Karyawan $karyawan): RedirectResponse
    {
        $jabatan = $karyawan->jabatan;
        if ($karyawan->status === Karyawan::STATUS_KELUAR || ! $jabatan) {
            throw ValidationException::withMessages(['karyawan' => "{$karyawan->name} tidak sedang menjabat."]);
        }

        $karyawan->update(['jabatan_id' => null]);

        Inertia::flash('success', "{$karyawan->name} dicopot dari {$jabatan->name} dan menunggu ditempatkan lagi.");

        return back();
    }

    private function assertNotExited(Karyawan $karyawan, string $key): void
    {
        if ($karyawan->status === Karyawan::STATUS_KELUAR) {
            throw ValidationException::withMessages([$key => "{$karyawan->name} sudah keluar. Aktifkan kembali lewat Man Power → Karyawan Keluar."]);
        }
    }
}
