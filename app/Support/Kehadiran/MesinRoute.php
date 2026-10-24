<?php

namespace App\Support\Kehadiran;

use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which database a machine's pushes go to: machines all post to the same /iclock address,
 * so the main database keeps serial number → workspace (null = the main application).
 */
class MesinRoute
{
    public static function database(string $sn): ?array
    {
        $row = DB::connection('central')->table('mesin_absen_routes')->where('serial_number', $sn)->first();

        return $row ? ['database' => $row->database] : null;
    }

    /** Registers a machine for the current database; refuses a number registered elsewhere. */
    public static function claim(string $sn, ?string $previous = null): void
    {
        $here = Tenant::current();
        $row = DB::connection('central')->table('mesin_absen_routes')->where('serial_number', $sn)->first();
        if ($row && $row->database !== $here) {
            throw ValidationException::withMessages(['serial_number' => "Serial number {$sn} sudah terdaftar di tempat lain."]);
        }
        if ($previous && $previous !== $sn) {
            self::release($previous);
        }
        DB::connection('central')->table('mesin_absen_routes')->updateOrInsert(['serial_number' => $sn], ['database' => $here, 'updated_at' => now(), 'created_at' => $row->created_at ?? now()]);
    }

    public static function release(string $sn): void
    {
        DB::connection('central')->table('mesin_absen_routes')->where('serial_number', $sn)->where('database', Tenant::current())->delete();
    }
}
