<?php

namespace App\Models;

use App\Support\Karyawan\MasterOptions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One value of a choice list (see MasterOptions for the categories). */
#[Fillable(['kategori', 'nilai', 'urutan', 'sistem'])]
class MasterOption extends Model
{
    protected $table = 'master_options';

    protected function casts(): array
    {
        return [
            'sistem' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => MasterOptions::flush());
        static::deleted(fn () => MasterOptions::flush());
    }
}
