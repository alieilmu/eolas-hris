<?php

namespace App\Support\Attachments;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Photos / PDFs sent with a form: `lampiran[]` new files (with `halaman[]`, the page
 * count of each PDF as read in the browser) and `hapus_lampiran[]` ids to remove.
 */
class Attachments
{
    public const MIMES = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    public const MAX_FILES = 20;

    /** Per file, in bytes: 10 MB, or less when PHP accepts less (upload_max_filesize). */
    public static function maxBytes(): int
    {
        return min(10 * 1024 * 1024, self::iniBytes('upload_max_filesize'));
    }

    /** All files of one form together (post_max_size). */
    public static function maxTotalBytes(): int
    {
        return self::iniBytes('post_max_size');
    }

    /** For the upload field in the browser. */
    public static function limits(): array
    {
        return ['max_bytes' => self::maxBytes(), 'max_total_bytes' => self::maxTotalBytes(), 'max_files' => self::MAX_FILES];
    }

    public static function rules(): array
    {
        return [
            'attachment' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'attachment.*' => ['file', 'mimes:'.implode(',', self::MIMES), 'max:'.intdiv(self::maxBytes(), 1024)],
            'page_count' => ['nullable', 'array'],
            'page_count.*' => ['nullable', 'integer', 'min:1', 'max:500'],
            'delete_attachments' => ['nullable', 'array'],
            'delete_attachments.*' => ['integer'],
        ];
    }

    public static function messages(): array
    {
        return [
            'attachment.*.mimes' => 'Lampiran harus foto (JPG / PNG / WEBP) atau PDF.',
            'attachment.*.max' => 'Satu lampiran paling besar '.self::megabytes(self::maxBytes()).'.',
            'attachment.*.uploaded' => 'Lampiran gagal diunggah; paling besar '.self::megabytes(self::maxBytes()).' per file.',
            'attachment.max' => 'Paling banyak '.self::MAX_FILES.' lampiran.',
        ];
    }

    /**
     * Checks that at least one attachment remains after this change, and returns how many
     * pages (= nota) there will be.
     */
    public static function require(Request $request, ?Model $current, string $message): int
    {
        $remove = array_map('intval', $request->input('delete_attachments', []));
        $kept = $current ? $current->attachments()->whereNotIn('id', $remove)->sum('page_count') : 0;
        $new = collect($request->file('attachment', []))->keys()->sum(fn ($i) => self::pagesOf($request, $i));
        if ($kept + $new < 1) {
            throw ValidationException::withMessages(['attachment' => $message]);
        }

        return (int) ($kept + $new);
    }

    /** Stores the new files under the record and removes the ones taken out. */
    public static function sync(Request $request, Model $owner, string $folder): void
    {
        $remove = array_map('intval', $request->input('delete_attachments', []));
        if ($remove) {
            $owner->attachments()->whereIn('id', $remove)->get()->each->delete();
        }
        foreach ($request->file('attachment', []) as $i => $file) {
            /** @var UploadedFile $file */
            $mime = $file->getMimeType() ?: 'application/octet-stream';
            $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
            $path = $file->storeAs("attachment/{$folder}/{$owner->getKey()}", Str::uuid().'.'.$ext, Attachment::DISK);
            $owner->attachments()->create([
                'path' => $path,
                'name' => Str::limit($file->getClientOriginalName(), 200, ''),
                'mime' => $mime,
                'size' => $file->getSize(),
                'page_count' => $mime === 'application/pdf' ? self::pagesOf($request, $i) : 1,
                'created_by' => $request->user()?->id,
            ]);
        }
    }

    /** A PDF's page count from the browser, else counted in the file; a photo is one page. */
    private static function pagesOf(Request $request, int|string $i): int
    {
        $file = $request->file("attachment.{$i}");
        if (! $file instanceof UploadedFile || $file->getMimeType() !== 'application/pdf') {
            return 1;
        }
        $given = (int) $request->input("page_count.{$i}");
        if ($given >= 1) {
            return min($given, 500);
        }
        $raw = (string) @file_get_contents($file->getRealPath());

        return max(1, preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $raw));
    }

    private static function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        if ($value === '' || $value === '0' || $value === '-1') {
            return PHP_INT_MAX;
        }
        $n = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1, ',', ''), '0'), ',').' MB';
    }
}
