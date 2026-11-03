<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A phone's fingerprint / face key (WebAuthn) that opens the installed app for its account. */
#[Fillable(['user_id', 'credential_id', 'public_key', 'algorithm', 'sign_count', 'name', 'last_used_at'])]
#[Hidden(['public_key'])]
class UserPasskey extends Model
{
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'algorithm' => 'integer', 'sign_count' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
