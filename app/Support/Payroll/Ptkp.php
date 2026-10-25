<?php

namespace App\Support\Payroll;

use App\Models\Employee;
use App\Support\Compensation\BenefitEntitlement;

/**
 * Status PTKP for PPh21, read from the employee's data (no manual override):
 *  - a man: K when married, TK otherwise, with his registered children as dependants,
 *    at most three (married with 4 children = K/3);
 *  - a woman: always TK/0, married or not (her family's benefits, e.g. Plafon Pengobatan,
 *    still follow her marital status in App\Support\Comben\Entitlement).
 */
class Ptkp
{
    public const MAKS_TANGGUNGAN = 3;

    /** TER category (PP 58/2023) per PTKP status. */
    private const KATEGORI_TER = [
        'TK/0' => 'A', 'TK/1' => 'A', 'K/0' => 'A',
        'TK/2' => 'B', 'TK/3' => 'B', 'K/1' => 'B', 'K/2' => 'B',
        'K/3' => 'C',
    ];

    public static function status(Employee $k): string
    {
        if (self::perempuan($k)) {
            return 'TK/0';
        }
        $tanggungan = min(self::MAKS_TANGGUNGAN, BenefitEntitlement::childrenCount($k));

        return (BenefitEntitlement::kawin($k) ? 'K' : 'TK').'/'.$tanggungan;
    }

    public static function terCategory(string $status): string
    {
        return self::KATEGORI_TER[$status] ?? 'A';
    }

    public static function perempuan(Employee $k): bool
    {
        return $k->profile?->gender === 'Perempuan';
    }
}
