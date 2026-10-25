<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/** A photo or PDF attached to a deklarasi (its nota) or a cuti / dinas (the signed form), kept on the private disk. */
#[Fillable(['path', 'name', 'mime', 'size', 'page_count', 'created_by'])]
class Attachment extends Model
{
    public const DISK = 'local';

    protected static function booted(): void
    {
        static::deleted(fn (Attachment $l) => Storage::disk(self::DISK)->delete($l->path));
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    public function toView(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'mime' => $this->mime,
            'size' => $this->size,
            'page_count' => $this->page_count,
            'url' => route('attachment.show', $this),
        ];
    }
}
