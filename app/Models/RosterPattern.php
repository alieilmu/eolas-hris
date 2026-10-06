<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A repeating cycle (e.g. 10 days D, 2 days OFF) to fill a roster with. */
#[Fillable(['nama', 'langkah'])]
class RosterPattern extends Model
{
    protected function casts(): array
    {
        return [
            // [{shift_code_id, hari}, ...]
            'langkah' => 'array',
        ];
    }

    public function usesCode(int $shiftCodeId): bool
    {
        return collect($this->langkah)->contains(fn (array $s) => (int) $s['shift_code_id'] === $shiftCodeId);
    }
}
