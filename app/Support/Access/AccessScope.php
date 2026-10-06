<?php

namespace App\Support\Access;

use App\Models\SubLocation;
use App\Models\User;
use App\Models\WorkLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Which employees the signed-in account may see. On the pages of a scoped menu
 * (Menu::SCOPED) and in the employee portal, every employee query is narrowed:
 *  - an Admin with locations chosen: employees of those work locations, plus
 *    those placed in the chosen sub locations;
 *  - a Karyawan account: only themselves.
 * Master data pages are not narrowed, so counts and deletion checks stay whole.
 */
class AccessScope
{
    private const ATTRIBUTE = 'access.scoped';

    /** Turns the narrowing on for the current request (see MenuAccess). */
    public static function enable(): void
    {
        request()->attributes->set(self::ATTRIBUTE, true);
    }

    public static function active(): bool
    {
        return (bool) request()->attributes->get(self::ATTRIBUTE);
    }

    /** The account whose view is narrowed now, or null when nothing is narrowed. */
    public static function restrictedUser(): ?User
    {
        if (! self::active()) {
            return null;
        }
        $user = Auth::user();

        return $user && ($user->isKaryawan() || $user->hasLocationScope()) ? $user : null;
    }

    /** Global scope of Karyawan. */
    public static function restrict(Builder $query): void
    {
        $user = self::restrictedUser();
        if (! $user) {
            return;
        }
        if ($user->isKaryawan()) {
            $query->where($query->qualifyColumn('id'), $user->karyawan_id ?? 0);

            return;
        }
        $query->where(fn (Builder $q) => $q
            ->whereIn($query->qualifyColumn('work_location_id'), $user->work_location_ids ?: [0])
            ->orWhereIn($query->qualifyColumn('sub_location_id'), $user->sub_location_ids ?: [0]));
    }

    /**
     * Work locations the account may open (tabs, filters): those chosen, and the
     * parents of the chosen sub locations. Null: all of them.
     *
     * @return list<int>|null
     */
    public static function workLocationIds(?User $user = null): ?array
    {
        $user ??= Auth::user();
        if (! $user || ! $user->hasLocationScope()) {
            return null;
        }
        $parents = SubLocation::whereIn('id', $user->sub_location_ids ?: [])->pluck('work_location_id')->all();

        return array_values(array_unique(array_map('intval', [...($user->work_location_ids ?: []), ...$parents])));
    }

    /** The work locations for a filter or tabs, narrowed to the account's. */
    public static function locations()
    {
        $ids = self::workLocationIds();

        return WorkLocation::orderBy('order')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->get(['id', 'name']);
    }

    /** Sub locations for a filter: of the account's work locations, or the ones chosen. */
    public static function subLocations(): array
    {
        $user = Auth::user();
        $query = SubLocation::orderBy('work_location_id')->orderBy('order')->orderBy('name');
        if ($user?->hasLocationScope()) {
            $query->where(fn ($q) => $q->whereIn('work_location_id', $user->work_location_ids ?: [0])->orWhereIn('id', $user->sub_location_ids ?: [0]));
        }

        return $query->get(['id', 'name', 'work_location_id'])->all();
    }

    /** Refuses placing an employee in a work location outside the account's. */
    public static function assertWorkLocation(?int $id, string $field = 'work_location_id'): void
    {
        if ($id !== null && ! self::allowsWorkLocation($id)) {
            throw ValidationException::withMessages([$field => 'Lokasi kerja ini di luar akses akun Anda.']);
        }
    }

    public static function allowsWorkLocation(int $id): bool
    {
        $ids = self::workLocationIds();

        return $ids === null || in_array($id, $ids, true);
    }
}
