<?php

namespace App\Http\Controllers;

use App\Models\ApplicationForm;
use App\Models\FormField;
use App\Support\Notifikasi\Pengajuan;
use App\Support\Recruitment\Lamaran;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public side of Recruitment (no sign-in): the open vacancies and their application
 * forms. An application lands in Pelamar and notifies the Recruitment admins.
 */
class LowonganController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Lowongan/Index', [
            'forms' => ApplicationForm::where('is_open', true)->orderByDesc('created_at')->get()
                ->filter(fn (ApplicationForm $f) => $f->accepting())
                ->map(fn (ApplicationForm $f) => [
                    'id' => $f->id,
                    'title' => $f->title,
                    'description' => $f->description,
                    'posisi' => $f->posisi,
                    'lokasi' => $f->lokasi,
                    'batas_lamaran' => $f->batas_lamaran?->toDateString(),
                    'dibuka' => $f->created_at?->toDateString(),
                ])->values(),
        ]);
    }

    public function show(ApplicationForm $form): Response
    {
        $form->load('fields');

        return Inertia::render('Lowongan/Form', [
            'form' => [
                'id' => $form->id,
                'title' => $form->title,
                'description' => $form->description,
                'posisi' => $form->posisi,
                'lokasi' => $form->lokasi,
                'batas_lamaran' => $form->batas_lamaran?->toDateString(),
                'accepting' => $form->accepting(),
                'fields' => $form->accepting() ? $form->fields->map(fn (FormField $f) => [
                    'id' => $f->id,
                    'label' => $f->label,
                    'type' => $f->type,
                    'required' => $f->required,
                    'options' => $f->options ?? [],
                    'placeholder' => $f->placeholder,
                ]) : [],
            ],
            'max_file' => Lamaran::maxLabel(),
            'max_bytes' => Lamaran::maxBytes(),
        ]);
    }

    public function submit(Request $request, ApplicationForm $form): RedirectResponse
    {
        $form->load('fields');
        if (! $form->accepting()) {
            throw ValidationException::withMessages(['form' => 'Lowongan ini sudah tidak menerima lamaran.']);
        }
        // A field people never see: filled in only by bots.
        if (filled($request->input('website'))) {
            Inertia::flash('terkirim', 'Pelamar');

            return back();
        }
        $request->validate(['setuju' => ['accepted']], ['setuju.accepted' => 'Centang persetujuan pengolahan data untuk mengirim lamaran.']);
        $request->validate(Lamaran::rules($form), Lamaran::messages(), Lamaran::attributes($form));

        $applicant = Lamaran::store($request, $form);
        Pengajuan::pelamar($applicant);

        // The page shows the thank-you message instead of the form.
        Inertia::flash('terkirim', $applicant->nama());

        return back();
    }
}
