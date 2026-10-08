<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\ApplicationForm;
use App\Models\FormField;
use App\Support\Recruitment\Lamaran;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengaturan Form: the vacancies' application forms — opened and closed, published at
 * /lowongan/{id}, and built question by question.
 */
class FormController extends Controller
{
    /** Which question types a "kunci" may have. */
    private const KUNCI_TYPES = [
        'nama' => ['text'],
        'email' => ['email'],
        'no_hp' => ['tel', 'text'],
        'posisi' => ['select', 'text'],
        'portofolio' => ['url', 'text'],
        'cv' => ['file'],
    ];

    public function index(Request $request): Response
    {
        return Inertia::render('Recruitment/Forms', [
            'forms' => ApplicationForm::withCount(['fields', 'applicants', 'applicants as baru_count' => fn ($q) => $q->where('status', 'baru')])
                ->orderByDesc('is_open')->orderByDesc('created_at')->get()
                ->map(fn (ApplicationForm $f) => [
                    'id' => $f->id,
                    'title' => $f->title,
                    'description' => $f->description,
                    'posisi' => $f->posisi,
                    'lokasi' => $f->lokasi,
                    'batas_lamaran' => $f->batas_lamaran?->toDateString(),
                    'is_open' => $f->is_open,
                    'accepting' => $f->accepting(),
                    'fields_count' => $f->fields_count,
                    'applicants_count' => $f->applicants_count,
                    'baru_count' => $f->baru_count,
                    'created_at' => $f->created_at?->toDateString(),
                    'url' => route('lowongan.show', $f),
                ]),
            'lowongan_url' => route('lowongan'),
            'can_edit' => $request->user()->canEdit('recruitment'),
        ]);
    }

    /** A new form with the questions every application needs, opened in the editor. */
    public function store(): RedirectResponse
    {
        $form = DB::transaction(function () {
            $form = ApplicationForm::create(['title' => 'Lowongan Baru', 'description' => null, 'is_open' => false]);
            $form->fields()->createMany(self::defaultFields());

            return $form;
        });

        return redirect()->route('recruitment.form.edit', $form);
    }

    public function edit(ApplicationForm $form): Response
    {
        $form->load('fields');

        return Inertia::render('Recruitment/FormEditor', [
            'form' => [
                'id' => $form->id,
                'title' => $form->title,
                'description' => $form->description ?? '',
                'posisi' => $form->posisi ?? '',
                'lokasi' => $form->lokasi ?? '',
                'batas_lamaran' => $form->batas_lamaran?->toDateString() ?? '',
                'is_open' => $form->is_open,
                'url' => route('lowongan.show', $form),
                'applicants_count' => $form->applicants()->count(),
                'fields' => $form->fields->map(fn (FormField $f) => [
                    'id' => $f->id,
                    'label' => $f->label,
                    'type' => $f->type,
                    'kunci' => $f->kunci ?? '',
                    'required' => $f->required,
                    'options' => $f->options ?? [],
                    'placeholder' => $f->placeholder ?? '',
                ]),
            ],
            'types' => Lamaran::TYPES,
            'kunci' => Lamaran::KUNCI,
            'kunci_types' => self::KUNCI_TYPES,
            'max_file' => Lamaran::maxLabel(),
        ]);
    }

    /** The form and all its questions (kept ones by id, so earlier answers still point to them). */
    public function update(Request $request, ApplicationForm $form): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:3000'],
            'posisi' => ['nullable', 'string', 'max:120'],
            'lokasi' => ['nullable', 'string', 'max:120'],
            'batas_lamaran' => ['nullable', 'date_format:Y-m-d'],
            'is_open' => ['boolean'],
            'fields' => ['required', 'array', 'min:1', 'max:60'],
            'fields.*.id' => ['nullable', 'integer'],
            'fields.*.label' => ['required', 'string', 'max:200'],
            'fields.*.type' => ['required', Rule::in(array_keys(Lamaran::TYPES))],
            'fields.*.kunci' => ['nullable', Rule::in(array_keys(Lamaran::KUNCI))],
            'fields.*.required' => ['boolean'],
            'fields.*.options' => ['nullable', 'array', 'max:50'],
            'fields.*.options.*' => ['nullable', 'string', 'max:150'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:150'],
        ], ['fields.required' => 'Tambahkan minimal satu pertanyaan.'], ['title' => 'Judul', 'fields.*.label' => 'Pertanyaan']);

        $fields = array_values($data['fields']);
        $errors = [];
        $kunci = array_filter(array_column($fields, 'kunci'));
        foreach (array_count_values($kunci) as $k => $n) {
            if ($n > 1) {
                $errors[] = 'Hanya satu pertanyaan yang boleh dipakai sebagai '.Lamaran::KUNCI[$k].'.';
            }
        }
        if (! in_array('nama', $kunci, true)) {
            $errors[] = 'Tandai satu pertanyaan sebagai "Nama pelamar".';
        }
        foreach ($fields as $i => $f) {
            if (($f['kunci'] ?? null) && ! in_array($f['type'], self::KUNCI_TYPES[$f['kunci']], true)) {
                $errors[] = "Pertanyaan \"{$f['label']}\": ".Lamaran::KUNCI[$f['kunci']].' harus bertipe '.implode(' / ', array_map(fn ($t) => Lamaran::TYPES[$t], self::KUNCI_TYPES[$f['kunci']])).'.';
            }
            if ($f['type'] === 'select' && count(array_filter($f['options'] ?? [], fn ($o) => trim((string) $o) !== '')) < 1) {
                $errors[] = "Pertanyaan \"{$f['label']}\": isi minimal satu pilihan.";
            }
        }
        if ($errors) {
            // One message: the page shows only the first message of a key.
            throw ValidationException::withMessages(['fields' => implode("\n", $errors)]);
        }

        DB::transaction(function () use ($form, $data, $fields) {
            $form->update([
                'title' => trim($data['title']),
                'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
                'posisi' => filled($data['posisi'] ?? null) ? trim($data['posisi']) : null,
                'lokasi' => filled($data['lokasi'] ?? null) ? trim($data['lokasi']) : null,
                'batas_lamaran' => $data['batas_lamaran'] ?? null,
                'is_open' => (bool) ($data['is_open'] ?? false),
            ]);
            $keep = [];
            foreach ($fields as $order => $f) {
                $attributes = [
                    'label' => trim($f['label']),
                    'type' => $f['type'],
                    'kunci' => $f['kunci'] ?? null,
                    'required' => (bool) ($f['required'] ?? false),
                    'options' => $f['type'] === 'select' ? array_values(array_unique(array_filter(array_map(fn ($o) => trim((string) $o), $f['options'] ?? [])))) : null,
                    'placeholder' => filled($f['placeholder'] ?? null) ? trim($f['placeholder']) : null,
                    'order' => $order,
                ];
                $existing = isset($f['id']) ? $form->fields()->whereKey($f['id'])->first() : null;
                $keep[] = $existing ? tap($existing)->update($attributes)->id : $form->fields()->create($attributes)->id;
            }
            $form->fields()->whereNotIn('id', $keep)->delete();
        });
        Inertia::flash('success', "Form {$form->title} disimpan.");

        return back();
    }

    public function toggle(ApplicationForm $form): RedirectResponse
    {
        $form->update(['is_open' => ! $form->is_open]);
        Inertia::flash('success', "Form {$form->title} ".($form->is_open ? 'dibuka; lamaran bisa dikirim lewat tautannya.' : 'ditutup.'));

        return back();
    }

    /** A copy (closed) to start the next vacancy from. */
    public function duplicate(ApplicationForm $form): RedirectResponse
    {
        $copy = DB::transaction(function () use ($form) {
            $copy = $form->replicate(['is_open']);
            $copy->fill(['title' => "{$form->title} (salinan)", 'is_open' => false])->save();
            $copy->fields()->createMany($form->fields->map(fn (FormField $f) => $f->only(['label', 'type', 'kunci', 'required', 'options', 'placeholder', 'order']))->all());

            return $copy;
        });

        return redirect()->route('recruitment.form.edit', $copy);
    }

    public function destroy(ApplicationForm $form): RedirectResponse
    {
        if ($form->applicants()->exists()) {
            throw ValidationException::withMessages(['form' => "Form {$form->title} sudah menerima lamaran; tutup saja agar lamarannya tetap tersimpan."]);
        }
        $form->delete();
        Inertia::flash('success', "Form {$form->title} dihapus.");

        return back();
    }

    public static function defaultFields(): array
    {
        return [
            ['label' => 'Nama Lengkap', 'type' => 'text', 'kunci' => 'nama', 'required' => true, 'order' => 0],
            ['label' => 'Email', 'type' => 'email', 'kunci' => 'email', 'required' => true, 'order' => 1],
            ['label' => 'Nomor HP / WhatsApp', 'type' => 'tel', 'kunci' => 'no_hp', 'required' => true, 'order' => 2],
            ['label' => 'Tanggal Lahir', 'type' => 'date', 'kunci' => null, 'required' => true, 'order' => 3],
            ['label' => 'Pendidikan Terakhir', 'type' => 'select', 'kunci' => null, 'required' => true, 'order' => 4, 'options' => ['SMA/SMK', 'D3', 'S1', 'S2']],
            ['label' => 'Domisili', 'type' => 'text', 'kunci' => null, 'required' => false, 'order' => 5],
            ['label' => 'CV', 'type' => 'file', 'kunci' => 'cv', 'required' => true, 'order' => 6],
        ];
    }
}
