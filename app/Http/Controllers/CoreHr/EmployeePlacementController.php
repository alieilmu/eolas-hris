<?php

namespace App\Http\Controllers\CoreHr;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Models\Employee;
use App\Models\SubLocation;
use App\Support\Access\AccessScope;
use App\Support\Employees\ProfileRules;
use App\Support\Organization\PositionComposer;
use App\Support\Organization\Placement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Where an employee works and in which position: "Ubah Penempatan", and the chart's "Kelola". */
class EmployeePlacementController extends Controller
{
    /** A new placement: work location + SubGroup + unit / Nama Jabatan. Fills the plan's matching position, if any. */
    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $this->assertNotExited($employee, 'placement');

        ['attributes' => $placement, 'note' => $note] = Placement::resolve($request, $employee);
        DB::transaction(fn () => $employee->update([...$placement, 'status' => Employee::STATUS_ACTIVE]));
        $position = $employee->position()->with('workLocation')->first();

        Inertia::flash('success', $position
            ? "{$employee->name} sekarang menjabat {$position->name} ({$position->workLocation?->name})."
            : "Penempatan {$employee->name} disimpan. {$note}");

        return back();
    }

    /** Moves an active or inactive employee into an existing position (and its work location) and makes them active there. */
    public function assign(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate([
            'position_id' => ['required', 'integer', 'exists:positions,id'],
        ], [], ['position_id' => 'Jabatan']);

        $this->assertNotExited($employee, 'position_id');
        AccessScope::assertWorkLocation(Position::find($data['position_id'])?->work_location_id, 'position_id');
        if ($error = ProfileRules::capacityError($data['position_id'], $employee->id)) {
            throw ValidationException::withMessages(['position_id' => $error]);
        }

        $position = Position::find($data['position_id']);
        // Whoever fills a position takes its placement: its work location, SubGroup, unit, Sub Lokasi.
        $employee->update([...PositionComposer::employeePlacementOf($position), 'position_id' => $position->id, 'status' => Employee::STATUS_ACTIVE]);

        Inertia::flash('success', "{$employee->name} sekarang menjabat {$position->name}.");

        return back();
    }

    /**
     * Assigns an employee to a Sub Lokasi Kerja (dropped on its box, or "Mutasi"):
     * they fill the position matching their placement there, created if it is new.
     */
    public function subLocation(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate(['sub_location_id' => ['required', 'integer', 'exists:sub_locations,id']], [], ['sub_location_id' => 'Sub Lokasi']);
        $this->assertNotExited($employee, 'placement');
        if (! $employee->job_level_id) {
            throw ValidationException::withMessages(['placement' => "{$employee->name} belum punya SubGroup. Lengkapi penempatannya dulu (Ubah Penempatan)."]);
        }

        $subLocation = SubLocation::findOrFail($data['sub_location_id']);
        AccessScope::assertWorkLocation($subLocation->work_location_id, 'placement');
        $placement = [...$employee->placement(), 'work_location_id' => $subLocation->work_location_id, 'sub_location_id' => $subLocation->id];

        $position = DB::transaction(function () use ($placement, $employee) {
            [$position, $error] = PositionComposer::findForEmployee($placement);
            if (! $position) {
                throw ValidationException::withMessages(['placement' => $error ?? 'Jabatan tidak ditemukan.']);
            }
            if ($position->id !== $employee->position_id && ($full = ProfileRules::capacityError($position->id, $employee->id))) {
                throw ValidationException::withMessages(['placement' => $full]);
            }
            $employee->update([...PositionComposer::employeePlacementOf($position), 'position_id' => $position->id, 'status' => Employee::STATUS_ACTIVE]);

            return $position;
        });

        Inertia::flash('success', "{$employee->name} sekarang menjabat {$position->name} di Sub Lokasi {$subLocation->name}.");

        return back();
    }

    /** Takes an employee off their position; they keep their placement and wait in the Struktur Organisasi list. */
    public function remove(Employee $employee): RedirectResponse
    {
        $position = $employee->position;
        if ($employee->status === Employee::STATUS_TERMINATED || ! $position) {
            throw ValidationException::withMessages(['employee' => "{$employee->name} tidak sedang menjabat."]);
        }

        $employee->update(['position_id' => null]);

        Inertia::flash('success', "{$employee->name} dicopot dari {$position->name} dan menunggu ditempatkan lagi.");

        return back();
    }

    private function assertNotExited(Employee $employee, string $key): void
    {
        if ($employee->status === Employee::STATUS_TERMINATED) {
            throw ValidationException::withMessages([$key => "{$employee->name} sudah keluar. Aktifkan kembali lewat Man Power → Karyawan Keluar."]);
        }
    }
}
