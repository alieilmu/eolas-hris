<?php

namespace App\Support\Org;

use App\Models\Jabatan;
use App\Models\Karyawan;
use App\Support\Access\AccessScope;
use App\Support\Karyawan\ProfileRules;
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
class Penempatan
{
    /**
     * @return array{attributes: array<string, mixed>, note: string|null} the employee's placement columns and
     *                                                                    jabatan_id, plus why they wait (if they do)
     */
    public static function resolve(Request $request, ?Karyawan $karyawan = null): array
    {
        if ($request->filled('jabatan_id')) {
            $jabatan = Jabatan::find($request->integer('jabatan_id'));
            if (! $jabatan) {
                throw ValidationException::withMessages(['penempatan' => 'Jabatan tidak ditemukan.']);
            }
            AccessScope::assertWorkLocation($jabatan->work_location_id, 'penempatan');
            if (self::becomesHolder($jabatan, $karyawan) && ($error = ProfileRules::capacityError($jabatan->id, $karyawan?->id))) {
                throw ValidationException::withMessages(['penempatan' => $error]);
            }

            return ['attributes' => [...JabatanComposer::employeePlacementOf($jabatan), 'jabatan_id' => $jabatan->id], 'note' => null];
        }

        $placement = $request->validate(PenempatanRules::rules(), [], PenempatanRules::attributes());
        // Only inside the account's locations (Settings → Akun & Akses).
        AccessScope::assertWorkLocation(isset($placement['work_location_id']) ? (int) $placement['work_location_id'] : null, 'penempatan');
        [$jabatan, $error, $name, $created] = JabatanComposer::findForEmployee($placement, $karyawan?->jabatan);
        if ($error) {
            throw ValidationException::withMessages(['penempatan' => $error]);
        }

        $attributes = JabatanComposer::employeePlacement($placement);
        $note = $created ? "Jabatan {$jabatan->name} dibuat di Sub Lokasi {$jabatan->subLocation?->name}." : null;
        if (! $jabatan) {
            $note = "Jabatan {$name} belum ada di Plan Struktur Organisasi — karyawan menunggu ditempatkan di Struktur Organisasi.";
        } elseif (self::becomesHolder($jabatan, $karyawan) && ($full = ProfileRules::capacityError($jabatan->id, $karyawan?->id))) {
            $note = "{$full} Karyawan menunggu ditempatkan di Struktur Organisasi.";
            $jabatan = null;
        }

        return ['attributes' => [...$attributes, 'jabatan_id' => $jabatan?->id], 'note' => $note];
    }

    private static function becomesHolder(Jabatan $jabatan, ?Karyawan $karyawan): bool
    {
        return ! $karyawan || $karyawan->jabatan_id !== $jabatan->id || $karyawan->status !== Karyawan::STATUS_AKTIF;
    }
}
