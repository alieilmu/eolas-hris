<?php

namespace App\Http\Controllers;

use App\Models\JobPosting;
use App\Models\JobPostingField;
use App\Support\Notifications\RequestNotifier;
use App\Support\Recruitment\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public side of Recruitment (no sign-in): the open vacancies and their application
 * forms. An application lands in Pelamar and notifies the Recruitment admins.
 */
class CareersController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Careers/Index', [
            'forms' => JobPosting::where('is_open', true)->orderByDesc('created_at')->get()
                ->filter(fn (JobPosting $f) => $f->accepting())
                ->map(fn (JobPosting $f) => [
                    'id' => $f->id,
                    'title' => $f->title,
                    'description' => $f->description,
                    'position_title' => $f->position_title,
                    'location' => $f->location,
                    'closing_date' => $f->closing_date?->toDateString(),
                    'opened' => $f->created_at?->toDateString(),
                ])->values(),
        ]);
    }

    public function show(JobPosting $form): Response
    {
        $form->load('fields');

        return Inertia::render('Careers/Form', [
            'form' => [
                'id' => $form->id,
                'title' => $form->title,
                'description' => $form->description,
                'position_title' => $form->position_title,
                'location' => $form->location,
                'closing_date' => $form->closing_date?->toDateString(),
                'accepting' => $form->accepting(),
                'fields' => $form->accepting() ? $form->fields->map(fn (JobPostingField $f) => [
                    'id' => $f->id,
                    'label' => $f->label,
                    'type' => $f->type,
                    'required' => $f->required,
                    'options' => $f->options ?? [],
                    'placeholder' => $f->placeholder,
                ]) : [],
            ],
            'max_file' => Application::maxLabel(),
            'max_bytes' => Application::maxBytes(),
        ]);
    }

    public function submit(Request $request, JobPosting $form): RedirectResponse
    {
        $form->load('fields');
        if (! $form->accepting()) {
            throw ValidationException::withMessages(['form' => 'Lowongan ini sudah tidak menerima lamaran.']);
        }
        // A field people never see: filled in only by bots.
        if (filled($request->input('website'))) {
            Inertia::flash('sent', 'Pelamar');

            return back();
        }
        $request->validate(['agree' => ['accepted']], ['agree.accepted' => 'Centang persetujuan pengolahan data untuk mengirim lamaran.']);
        $request->validate(Application::rules($form), Application::messages(), Application::attributes($form));

        $applicant = Application::store($request, $form);
        RequestNotifier::applicants($applicant);

        // The page shows the thank-you message instead of the form.
        Inertia::flash('sent', $applicant->name());

        return back();
    }
}
