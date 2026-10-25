<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** How payroll periods and deductions work (one row, Pengaturan Kompensasi → Payroll). */
#[Fillable(['cutoff_day', 'default_tax_method', 'absence_divisor_mode', 'absence_divisor_days'])]
class PayrollSetting extends Model
{
    public const TAX_METHODS = [
        'gross' => 'Gross (dipotong dari gaji)',
        'gross_up' => 'Gross-up (diberi tunjangan PPh)',
        'net' => 'Net (ditanggung perusahaan)',
    ];

    public const DIVISOR_MODES = [
        'schedule' => 'Hari kerja di roster periode itu',
        'fixed' => 'Angka tetap',
    ];

    public static function current(): self
    {
        return self::query()->first() ?? self::query()->create([]);
    }
}
