<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A component paid or deducted through Input Variabel (bonus, insentif, potongan koperasi …).
 * An earning that is taxable counts in the PPh21 gross; a deduction that is taxable is taken
 * before tax (lowers the gross), otherwise after it.
 */
#[Fillable(['code', 'name', 'kind', 'nature', 'taxable', 'sort_order', 'is_active', 'is_system'])]
class PayrollComponent extends Model
{
    public const KINDS = ['earning' => 'Pendapatan', 'deduction' => 'Potongan'];

    public const NATURES = ['variable' => 'Tidak tetap (tiap bulan bisa beda)', 'irregular' => 'Tidak teratur (sesekali)'];

    protected function casts(): array
    {
        return ['taxable' => 'boolean', 'is_active' => 'boolean', 'is_system' => 'boolean'];
    }
}
