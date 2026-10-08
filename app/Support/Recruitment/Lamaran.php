<?php

namespace App\Support\Recruitment;

use App\Models\Applicant;
use App\Models\ApplicationForm;
use App\Models\FormField;
use App\Support\Lampiran\Attachments;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The public application form: its question types, the rules an answer must follow,
 * and how the answers (and uploaded files) are kept on the applicant.
 */
class Lamaran
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
        'nama' => 'Nama pelamar',
        'email' => 'Email pelamar',
        'no_hp' => 'Nomor HP pelamar',
        'posisi' => 'Posisi yang dilamar',
        'portofolio' => 'Portofolio',
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
    public static function rules(ApplicationForm $form): array
    {
        $rules = [];
        foreach ($form->fields as $f) {
            $base = [$f->required ? 'required' : 'nullable'];
            $rules["jawaban.{$f->id}"] = match ($f->type) {
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

    public static function attributes(ApplicationForm $form): array
    {
        return $form->fields->mapWithKeys(fn (FormField $f) => ["jawaban.{$f->id}" => $f->label])->all();
    }

    public static function messages(): array
    {
        return [
            'jawaban.*.required' => ':attribute wajib diisi.',
            'jawaban.*.mimes' => ':attribute harus PDF, Word, JPG atau PNG.',
            'jawaban.*.max' => ':attribute terlalu besar / panjang (file paling besar '.self::maxLabel().').',
            'jawaban.*.uploaded' => ':attribute gagal diunggah; paling besar '.self::maxLabel().'.',
            'jawaban.*.regex' => ':attribute hanya berisi angka, mis. 0812 3456 7890.',
            'jawaban.*.in' => 'Pilih :attribute dari daftar.',
        ];
    }

    /** Saves the application: the answers with their question, files on the private disk. */
    public static function store(Request $request, ApplicationForm $form): Applicant
    {
        $applicant = Applicant::create(['application_form_id' => $form->id, 'data' => [], 'status' => 'baru', 'submitted_at' => now()]);
        $data = [];
        foreach ($form->fields as $f) {
            $value = $request->input("jawaban.{$f->id}");
            if ($f->type === 'file') {
                $file = $request->file("jawaban.{$f->id}");
                $value = $file ? [
                    'path' => $file->storeAs("rekrutmen/pelamar/{$applicant->id}", Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()), self::DISK),
                    'nama' => Str::limit($file->getClientOriginalName(), 120, ''),
                    'mime' => $file->getMimeType(),
                    'ukuran' => $file->getSize(),
                ] : null;
            } elseif (is_string($value)) {
                $value = trim($value);
            }
            $data[(string) $f->id] = ['label' => $f->label, 'type' => $f->type, 'kunci' => $f->kunci, 'value' => $value === '' ? null : $value];
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
                'kunci' => $answer['kunci'] ?? null,
                'value' => is_array($value) ? null : $value,
                'file' => is_array($value) ? ['nama' => $value['nama'] ?? 'file', 'ukuran' => $value['ukuran'] ?? null, 'mime' => $value['mime'] ?? null,
                    'url' => route('recruitment.pelamar.file', [$a, $fieldId])] : null,
            ];
        }

        return $out;
    }

    /** The file answer of question $fieldId, or of the question marked "cv". */
    public static function file(Applicant $a, ?string $fieldId = null): ?array
    {
        foreach ($a->data ?? [] as $id => $answer) {
            $value = $answer['value'] ?? null;
            if (is_array($value) && ($fieldId === null ? ($answer['kunci'] ?? null) === 'cv' : (string) $id === $fieldId)) {
                return $value;
            }
        }

        return null;
    }
}
