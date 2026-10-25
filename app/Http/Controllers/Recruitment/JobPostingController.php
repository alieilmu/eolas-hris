<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\JobPosting;
use App\Models\JobPostingField;
use App\Support\Recruitment\Application;
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
class JobPostingController extends Controller
{
    /** Which question types a "kunci" may have. */
    private const KUNCI_TYPES = [
        'name' => ['text'],
        'email' => ['email'],
        'phone' => ['tel', 'text'],
        'position_title' => ['select', 'text'],
        'portfolio' => ['url', 'text'],
        'cv' => ['file'],
    ];

    public function index(Request $request): Response
    {
        return Inertia::render('Recruitment/JobPostings', [
            'forms' => JobPosting::withCount(['fields', 'applicants', 'applicants as new_count' => fn ($q) => $q->where('status', 'new')])
                ->orderByDesc('is_open')->orderByDesc('created_at')->get()
                ->map(fn (JobPosting $f) => [
                    'id' => $f->id,
                    'title' => $f->title,
                    'description' => $f->description,
                    'position_title' => $f->position_title,
                    'location' => $f->location,
                    'closing_date' => $f->closing_date?->toDateString(),
                    'is_open' => $f->is_open,
                    'accepting' => $f->accepting(),
                    'fields_count' => $f->fields_count,
                    'applicants_count' => $f->applicants_count,
                    'new_count' => $f->new_count,
                    'created_at' => $f->created_at?->toDateString(),
                    'url' => route('careers.show', $f),
                ]),
            'careers_url' => route('careers'),
            'can_edit' => $request->user()->canEdit('recruitment'),
        ]);
    }

    /** A new form with the questions every application needs, opened in the editor. */
    public function store(): RedirectResponse
    {
        $form = DB::transaction(function () {
            $form = JobPosting::create(['title' => 'Lowongan Baru', 'description' => null, 'is_open' => false]);
            $form->fields()->createMany(self::defaultFields());

            return $form;
        });

        return redirect()->route('recruitment.form.edit', $form);
    }

    public function edit(JobPosting $form): Response
    {
        $form->load('fields');

        return Inertia::render('Recruitment/JobPostingEditor', [
            'form' => [
                'id' => $form->id,
                'title' => $form->title,
                'description' => $form->description ?? '',
                'position_title' => $form->position_title ?? '',
                'location' => $form->location ?? '',
                'closing_date' => $form->closing_date?->toDateString() ?? '',
                'is_open' => $form->is_open,
                'url' => route('careers.show', $form),
                'applicants_count' => $form->applicants()->count(),
                'fields' => $form->fields->map(fn (JobPostingField $f) => [
                    'id' => $f->id,
                    'label' => $f->label,
                    'type' => $f->type,
                    'field_key' => $f->field_key ?? '',
                    'required' => $f->required,
                    'options' => $f->options ?? [],
                    'placeholder' => $f->placeholder ?? '',
                ]),
            ],
            'types' => Application::TYPES,
            'field_key' => Application::KUNCI,
            'field_key_types' => self::KUNCI_TYPES,
            'max_file' => Application::maxLabel(),
        ]);
    }

    /** The form and all its questions (kept ones by id, so earlier answers still point to them). */
    public function update(Request $request, JobPosting $form): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:3000'],
            'position_title' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:120'],
            'closing_date' => ['nullable', 'date_format:Y-m-d'],
            'is_open' => ['boolean'],
            'fields' => ['required', 'array', 'min:1', 'max:60'],
            'fields.*.id' => ['nullable', 'integer'],
            'fields.*.label' => ['required', 'string', 'max:200'],
            'fields.*.type' => ['required', Rule::in(array_keys(Application::TYPES))],
            'fields.*.field_key' => ['nullable', Rule::in(array_keys(Application::KUNCI))],
            'fields.*.required' => ['boolean'],
            'fields.*.options' => ['nullable', 'array', 'max:50'],
            'fields.*.options.*' => ['nullable', 'string', 'max:150'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:150'],
        ], ['fields.required' => 'Tambahkan minimal satu pertanyaan.'], ['title' => 'Judul', 'fields.*.label' => 'Pertanyaan']);

        $fields = array_values($data['fields']);
        $errors = [];
        $field_key = array_filter(array_column($fields, 'field_key'));
        foreach (array_count_values($field_key) as $k => $n) {
            if ($n > 1) {
                $errors[] = 'Hanya satu pertanyaan yang boleh dipakai sebagai '.Application::KUNCI[$k].'.';
            }
        }
        if (! in_array('name', $field_key, true)) {
            $errors[] = 'Tandai satu pertanyaan sebagai "Nama pelamar".';
        }
        foreach ($fields as $i => $f) {
            if (($f['field_key'] ?? null) && ! in_array($f['type'], self::KUNCI_TYPES[$f['field_key']], true)) {
                $errors[] = "Pertanyaan \"{$f['label']}\": ".Application::KUNCI[$f['field_key']].' harus bertipe '.implode(' / ', array_map(fn ($t) => Application::TYPES[$t], self::KUNCI_TYPES[$f['field_key']])).'.';
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
                'position_title' => filled($data['position_title'] ?? null) ? trim($data['position_title']) : null,
                'location' => filled($data['location'] ?? null) ? trim($data['location']) : null,
                'closing_date' => $data['closing_date'] ?? null,
                'is_open' => (bool) ($data['is_open'] ?? false),
            ]);
            $keep = [];
            foreach ($fields as $order => $f) {
                $attributes = [
                    'label' => trim($f['label']),
                    'type' => $f['type'],
                    'field_key' => $f['field_key'] ?? null,
                    'required' => (bool) ($f['required'] ?? false),
                    'options' => $f['type'] === 'select' ? array_values(array_unique(array_filter(array_map(fn ($o) => trim((string) $o), $f['options'] ?? [])))) : null,
                    'placeholder' => filled($f['placeholder'] ?? null) ? trim($f['placeholder']) : null,
                    'sort_order' => $order,
                ];
                $existing = isset($f['id']) ? $form->fields()->whereKey($f['id'])->first() : null;
                $keep[] = $existing ? tap($existing)->update($attributes)->id : $form->fields()->create($attributes)->id;
            }
            $form->fields()->whereNotIn('id', $keep)->delete();
        });
        Inertia::flash('success', "Form {$form->title} disimpan.");

        return back();
    }

    public function toggle(JobPosting $form): RedirectResponse
    {
        $form->update(['is_open' => ! $form->is_open]);
        Inertia::flash('success', "Form {$form->title} ".($form->is_open ? 'dibuka; lamaran bisa dikirim lewat tautannya.' : 'ditutup.'));

        return back();
    }

    /** A copy (closed) to start the next vacancy from. */
    public function duplicate(JobPosting $form): RedirectResponse
    {
        $copy = DB::transaction(function () use ($form) {
            $copy = $form->replicate(['is_open']);
            $copy->fill(['title' => "{$form->title} (salinan)", 'is_open' => false])->save();
            $copy->fields()->createMany($form->fields->map(fn (JobPostingField $f) => $f->only(['label', 'type', 'field_key', 'required', 'options', 'placeholder', 'sort_order']))->all());

            return $copy;
        });

        return redirect()->route('recruitment.form.edit', $copy);
    }

    public function destroy(JobPosting $form): RedirectResponse
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
            ['label' => 'Nama Lengkap', 'type' => 'text', 'field_key' => 'name', 'required' => true, 'sort_order' => 0],
            ['label' => 'Email', 'type' => 'email', 'field_key' => 'email', 'required' => true, 'sort_order' => 1],
            ['label' => 'Nomor HP / WhatsApp', 'type' => 'tel', 'field_key' => 'phone', 'required' => true, 'sort_order' => 2],
            ['label' => 'Tanggal Lahir', 'type' => 'date', 'field_key' => null, 'required' => true, 'sort_order' => 3],
            ['label' => 'Pendidikan Terakhir', 'type' => 'select', 'field_key' => null, 'required' => true, 'sort_order' => 4, 'options' => ['SMA/SMK', 'D3', 'S1', 'S2']],
            ['label' => 'Domisili', 'type' => 'text', 'field_key' => null, 'required' => false, 'sort_order' => 5],
            ['label' => 'CV', 'type' => 'file', 'field_key' => 'cv', 'required' => true, 'sort_order' => 6],
        ];
    }
}
