<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A kind of licence or certificate employees hold (STR, SIP, SIMPER, SKK …). */
#[Fillable(['code', 'name', 'description', 'expires', 'sort_order', 'is_active'])]
class CertificationType extends Model
{
    protected function casts(): array
    {
        return ['expires' => 'boolean', 'is_active' => 'boolean'];
    }
}
