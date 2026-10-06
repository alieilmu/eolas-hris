<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/** A photo or PDF attached to a deklarasi (its nota) or a cuti / dinas (the signed form), kept on the private disk. */
#[Fillable(['path', 'nama', 'mime', 'ukuran', 'halaman', 'created_by'])]
class Lampiran extends Model
{
    public const DISK = 'local';

    protected static function booted(): void
    {
        static::deleted(fn (Lampiran $l) => Storage::disk(self::DISK)->delete($l->path));
    }

    public function lampirable(): MorphTo
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
            'nama' => $this->nama,
            'mime' => $this->mime,
            'ukuran' => $this->ukuran,
            'halaman' => $this->halaman,
            'url' => route('lampiran.show', $this),
        ];
    }
}
