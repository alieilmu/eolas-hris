<?php

namespace App\Support\Organization;

use App\Models\Position;
use App\Models\Employee;
use App\Support\Access\AccessScope;
use App\Support\Employees\ProfileRules;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Where an employee is placed: either straight into an existing position (the
 * chart's "Kelola"), or by their placement (work location, SubGroup, unit /
 * Nama Jabatan, Sub Lokasi). A placement fills the matching position of the
 * plan; without one — or when that position takes one person and is held —
 * the employee waits in the Struktur Organisasi list. Errors are reported under
 * "penempatan".
 */
class Placement
{
    /**
     * @return array{attributes: array<string, mixed>, note: string|null} the employee's placement columns and
     *                                                                    jabatan_id, plus why they wait (if they do)
     */
    public static function resolve(Request $request, ?Employee $employee = null): array
    {
        if ($request->filled('position_id')) {
            $position = Position::find($request->integer('position_id'));
            if (! $position) {
                throw ValidationException::withMessages(['placement' => 'Jabatan tidak ditemukan.']);
            }
            AccessScope::assertWorkLocation($position->work_location_id, 'placement');
            if (self::becomesHolder($position, $employee) && ($error = ProfileRules::capacityError($position->id, $employee?->id))) {
                throw ValidationException::withMessages(['placement' => $error]);
            }

            return ['attributes' => [...PositionComposer::employeePlacementOf($position), 'position_id' => $position->id], 'note' => null];
        }

        $placement = $request->validate(PlacementRules::rules(), [], PlacementRules::attributes());
        // Only inside the account's locations (Administrasi → Pengguna & Akses).
        AccessScope::assertWorkLocation(isset($placement['work_location_id']) ? (int) $placement['work_location_id'] : null, 'placement');
        [$position, $error, $name, $created] = PositionComposer::findForEmployee($placement, $employee?->position);
        if ($error) {
            throw ValidationException::withMessages(['placement' => $error]);
        }

        $attributes = PositionComposer::employeePlacement($placement);
        $note = $created ? "Jabatan {$position->name} dibuat di Sub Lokasi {$position->subLocation?->name}." : null;
        if (! $position) {
            $note = "Jabatan {$name} belum ada di Plan Struktur Organisasi — karyawan menunggu ditempatkan di Struktur Organisasi.";
        } elseif (self::becomesHolder($position, $employee) && ($full = ProfileRules::capacityError($position->id, $employee?->id))) {
            $note = "{$full} Karyawan menunggu ditempatkan di Struktur Organisasi.";
            $position = null;
        }

        return ['attributes' => [...$attributes, 'position_id' => $position?->id], 'note' => $note];
    }

    private static function becomesHolder(Position $position, ?Employee $employee): bool
    {
        return ! $employee || $employee->position_id !== $position->id || $employee->status !== Employee::STATUS_ACTIVE;
    }
}
