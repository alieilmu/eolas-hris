<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\JobPosting;
use App\Models\Candidate;
use App\Models\RecruitmentStage;
use App\Models\CandidateStageHistory;
use App\Support\Recruitment\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pelamar: applications sent through the public forms. HR reads them, moves the ones
 * worth pursuing into Pipeline Kandidat, or turns them down.
 */
class ApplicantController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in([...array_keys(Applicant::STATUS), 'all'])],
            'form' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $f['status'] ?? 'new';
        $q = trim($f['q'] ?? '');

        $applicants = Applicant::with(['form:id,title,position_title', 'candidate:id,applicant_id,recruitment_stage_id,rejected_at', 'candidate.stage:id,title,color'])
            ->when($status !== 'all', fn ($w) => $w->where('status', $status))
            ->when($f['form'] ?? null, fn ($w, $id) => $w->where('job_posting_id', $id))
            // The answers are JSON: the search looks through all of them (name, email, phone, ...).
            ->when($q !== '', fn ($w) => $w->where('data', 'like', '%'.addcslashes($q, '%_\\').'%'))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Applicant $a) => self::row($a));

        return Inertia::render('Recruitment/Applicants', [
            'applicants' => $applicants,
            'filters' => ['status' => $status, 'form' => $f['form'] ?? '', 'q' => $q],
            'counts' => Applicant::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'forms' => JobPosting::orderBy('title')->get(['id', 'title']),
            'stages' => RecruitmentStage::orderBy('sort_order')->get(['id', 'title', 'color']),
            'can_edit' => $request->user()->canEdit('recruitment'),
        ]);
    }

    /** All answers of one application. */
    public function show(Applicant $applicant): JsonResponse
    {
        $applicant->load(['form:id,title,position_title', 'candidate.stage:id,title,color']);

        return response()->json(self::row($applicant) + ['answers' => Application::view($applicant), 'notes' => $applicant->notes]);
    }

    /** Into Pipeline Kandidat as a candidate: the first stage unless another is chosen. */
    public function moveToTracking(Request $request, Applicant $applicant): RedirectResponse
    {
        $data = $request->validate([
            'stage_id' => ['nullable', 'integer', 'exists:recruitment_stages,id'],
            'position' => ['nullable', 'string', 'max:120'],
        ]);
        if ($applicant->candidate()->exists()) {
            throw ValidationException::withMessages(['applicant' => 'Pelamar ini sudah ada di Pipeline Kandidat.']);
        }
        $stage = isset($data['stage_id']) ? RecruitmentStage::findOrFail($data['stage_id']) : RecruitmentStage::orderBy('sort_order')->first();
        if (! $stage) {
            throw ValidationException::withMessages(['applicant' => 'Belum ada tahap di Pipeline Kandidat; tambahkan dulu.']);
        }
        $applicant->load('form');
        $cv = Application::file($applicant);
        $candidate = DB::transaction(function () use ($applicant, $stage, $data, $cv, $request) {
            $candidate = Candidate::create([
                'recruitment_stage_id' => $stage->id,
                'applicant_id' => $applicant->id,
                'name' => $applicant->name(),
                'email' => (string) ($applicant->answers('email') ?? ''),
                'phone' => $applicant->answers('phone'),
                'position' => trim($data['position'] ?? '') ?: ($applicant->answers('position_title') ?? $applicant->form?->position_title ?? $applicant->form?->title ?? 'Umum'),
                'portfolio_url' => is_string($p = $applicant->answers('portfolio')) ? $p : null,
                'cv_path' => $cv['path'] ?? null,
                'cv_filename' => $cv['name'] ?? null,
                'status' => $stage->title,
                'applied_date' => $applicant->submitted_at->toDateString(),
                'sort_order' => (int) Candidate::where('recruitment_stage_id', $stage->id)->max('sort_order') + 1,
            ]);
            CandidateStageHistory::create(['candidate_id' => $candidate->id, 'from' => 'Pelamar', 'to' => $stage->title, 'changed_by' => $request->user()->name]);
            $applicant->update(['status' => 'in_process', 'is_processed' => true]);

            return $candidate;
        });
        Inertia::flash('success', "{$candidate->name} masuk Pipeline Kandidat, tahap {$stage->title}.");

        return back();
    }

    /** Turned down (with a note), or back to "baru". */
    public function reject(Request $request, Applicant $applicant): RedirectResponse
    {
        $data = $request->validate(['reject' => ['required', 'boolean'], 'notes' => ['nullable', 'string', 'max:255']]);
        if ($applicant->candidate()->exists()) {
            throw ValidationException::withMessages(['applicant' => 'Pelamar ini sudah di Pipeline Kandidat; tolak dari kartunya di sana.']);
        }
        $applicant->update(['status' => $data['reject'] ? 'rejected' : 'new', 'notes' => $data['reject'] ? ($data['notes'] ?? null) : null]);
        Inertia::flash('success', $data['reject'] ? "Lamaran {$applicant->name()} ditolak." : "Lamaran {$applicant->name()} kembali ke daftar baru.");

        return back();
    }

    public function destroy(Applicant $applicant): RedirectResponse
    {
        if ($applicant->candidate()->exists()) {
            throw ValidationException::withMessages(['applicant' => 'Pelamar ini sudah di Pipeline Kandidat; hapus kartunya di sana dulu.']);
        }
        $applicant->delete();
        Inertia::flash('success', "Lamaran {$applicant->name()} dihapus beserta file-nya.");

        return back();
    }

    /** A file the applicant sent (CV, certificate, photo). */
    public function file(Applicant $applicant, string $field): StreamedResponse
    {
        $file = Application::file($applicant, $field);
        abort_unless($file && Storage::disk(Application::DISK)->exists($file['path']), 404);

        return Storage::disk(Application::DISK)->response($file['path'], $file['name'] ?? 'file', ['X-Content-Type-Options' => 'nosniff']);
    }

    public static function row(Applicant $a): array
    {
        $cv = Application::file($a);

        return [
            'id' => $a->id,
            'name' => $a->name(),
            'email' => $a->answers('email'),
            'phone' => $a->answers('phone'),
            'position_title' => $a->answers('position_title') ?? $a->form?->position_title,
            'form' => $a->form ? ['id' => $a->form->id, 'title' => $a->form->title] : null,
            'submitted_at' => $a->submitted_at?->toIso8601String(),
            'status' => $a->status,
            'cv' => $cv ? ['name' => $cv['name'] ?? 'CV', 'url' => route('recruitment.applicants.file', [$a, self::cvField($a)])] : null,
            'stage' => $a->candidate ? ['title' => $a->candidate->stage?->title, 'color' => $a->candidate->stage?->color, 'rejected' => $a->candidate->rejected_at !== null] : null,
        ];
    }

    private static function cvField(Applicant $a): string
    {
        foreach ($a->data ?? [] as $id => $answer) {
            if (($answer['field_key'] ?? null) === 'cv' && is_array($answer['value'] ?? null)) {
                return (string) $id;
            }
        }

        return '0';
    }
}
