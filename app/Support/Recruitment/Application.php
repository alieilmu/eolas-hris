<?php

namespace App\Support\Recruitment;

use App\Models\Applicant;
use App\Models\JobPosting;
use App\Models\JobPostingField;
use App\Support\Attachments\Attachments;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The public application form: its question types, the rules an answer must follow,
 * and how the answers (and uploaded files) are kept on the applicant.
 */
class Application
{
    public const DISK = 'local';

    /** type => label in the form builder. */
    public const TYPES = [
        'text' => 'Jawaban singkat',
        'textarea' => 'Paragraf',
        'email' => 'Email',
        'tel' => 'Nomor HP / WhatsApp',
        'number' => 'Angka',
        'date' => 'Tanggal',
        'select' => 'Pilihan (dropdown)',
        'url' => 'Tautan (URL)',
        'file' => 'Unggah file',
    ];

    /** What an answer is used for when the applicant moves to Tracking. */
    public const KUNCI = [
        'name' => 'Nama pelamar',
        'email' => 'Email pelamar',
        'phone' => 'Nomor HP pelamar',
        'position_title' => 'Posisi yang dilamar',
        'portfolio' => 'Portofolio',
        'cv' => 'CV',
    ];

    public const FILE_MIMES = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

    /** Largest file a pelamar may send (the PHP upload limit, at most 5 MB). */
    public static function maxBytes(): int
    {
        return min(5 * 1024 * 1024, Attachments::maxBytes());
    }

    public static function maxLabel(): string
    {
        return rtrim(rtrim(number_format(self::maxBytes() / 1024 / 1024, 1, ',', ''), '0'), ',').' MB';
    }

    /** Validation for the answers, as jawaban.{field id}. */
    public static function rules(JobPosting $form): array
    {
        $rules = [];
        foreach ($form->fields as $f) {
            $base = [$f->required ? 'required' : 'nullable'];
            $rules["answers.{$f->id}"] = match ($f->type) {
                'email' => [...$base, 'email', 'max:150'],
                'tel' => [...$base, 'string', 'regex:/^[0-9 +()\-]{8,20}$/'],
                'number' => [...$base, 'numeric'],
                'date' => [...$base, 'date_format:Y-m-d'],
                'url' => [...$base, 'url', 'max:255'],
                'select' => [...$base, Rule::in($f->options ?? [])],
                'textarea' => [...$base, 'string', 'max:3000'],
                'file' => [...$base, 'file', 'mimes:'.implode(',', self::FILE_MIMES), 'max:'.intdiv(self::maxBytes(), 1024)],
                default => [...$base, 'string', 'max:255'],
            };
        }

        return $rules;
    }

    public static function attributes(JobPosting $form): array
    {
        return $form->fields->mapWithKeys(fn (JobPostingField $f) => ["answers.{$f->id}" => $f->label])->all();
    }

    public static function messages(): array
    {
        return [
            'answers.*.required' => ':attribute wajib diisi.',
            'answers.*.mimes' => ':attribute harus PDF, Word, JPG atau PNG.',
            'answers.*.max' => ':attribute terlalu besar / panjang (file paling besar '.self::maxLabel().').',
            'answers.*.uploaded' => ':attribute gagal diunggah; paling besar '.self::maxLabel().'.',
            'answers.*.regex' => ':attribute hanya berisi angka, mis. 0812 3456 7890.',
            'answers.*.in' => 'Pilih :attribute dari daftar.',
        ];
    }

    /** Saves the application: the answers with their question, files on the private disk. */
    public static function store(Request $request, JobPosting $form): Applicant
    {
        $applicant = Applicant::create(['job_posting_id' => $form->id, 'data' => [], 'status' => 'new', 'submitted_at' => now()]);
        $data = [];
        foreach ($form->fields as $f) {
            $value = $request->input("answers.{$f->id}");
            if ($f->type === 'file') {
                $file = $request->file("answers.{$f->id}");
                $value = $file ? [
                    'path' => $file->storeAs("recruitment/applicants/{$applicant->id}", Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()), self::DISK),
                    'name' => Str::limit($file->getClientOriginalName(), 120, ''),
                    'mime' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ] : null;
            } elseif (is_string($value)) {
                $value = trim($value);
            }
            $data[(string) $f->id] = ['label' => $f->label, 'type' => $f->type, 'field_key' => $f->field_key, 'value' => $value === '' ? null : $value];
        }
        $applicant->update(['data' => $data]);

        return $applicant;
    }

    /** The answers for a page: files become a link to /recruitment/pelamar/{id}/file/{field}. */
    public static function view(Applicant $a): array
    {
        $out = [];
        foreach ($a->data ?? [] as $fieldId => $answer) {
            $value = $answer['value'] ?? null;
            $out[] = [
                'field_id' => (string) $fieldId,
                'label' => $answer['label'] ?? '',
                'type' => $answer['type'] ?? 'text',
                'field_key' => $answer['field_key'] ?? null,
                'value' => is_array($value) ? null : $value,
                'file' => is_array($value) ? ['name' => $value['name'] ?? 'file', 'size' => $value['size'] ?? null, 'mime' => $value['mime'] ?? null,
                    'url' => route('recruitment.applicants.file', [$a, $fieldId])] : null,
            ];
        }

        return $out;
    }

    /** The file answer of question $fieldId, or of the question marked "cv". */
    public static function file(Applicant $a, ?string $fieldId = null): ?array
    {
        foreach ($a->data ?? [] as $id => $answer) {
            $value = $answer['value'] ?? null;
            if (is_array($value) && ($fieldId === null ? ($answer['field_key'] ?? null) === 'cv' : (string) $id === $fieldId)) {
                return $value;
            }
        }

        return null;
    }

    /** The birth date asked on the form (a question labelled "Tanggal Lahir"), as Y-m-d, when it reads as a date. */
    public static function birthDate(?Applicant $a): ?string
    {
        foreach ($a?->data ?? [] as $answer) {
            $value = $answer['value'] ?? null;
            if (is_string($value) && $value !== '' && str_contains(mb_strtolower($answer['label'] ?? ''), 'tanggal lahir')) {
                try {
                    $date = \Carbon\CarbonImmutable::parse($value);
                } catch (\Throwable) {
                    return null;
                }

                return $date->isPast() ? $date->toDateString() : null;
            }
        }

        return null;
    }
}
