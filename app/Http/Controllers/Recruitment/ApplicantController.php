<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\ApplicationForm;
use App\Models\Candidate;
use App\Models\RecruitmentStage;
use App\Models\StatusHistory;
use App\Support\Recruitment\Lamaran;
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
 * worth pursuing into Recruitment Tracking, or turns them down.
 */
class ApplicantController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in([...array_keys(Applicant::STATUS), 'semua'])],
            'form' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $f['status'] ?? 'baru';
        $q = trim($f['q'] ?? '');

        $applicants = Applicant::with(['form:id,title,posisi', 'candidate:id,applicant_id,recruitment_stage_id,ditolak_at', 'candidate.stage:id,title,color'])
            ->when($status !== 'semua', fn ($w) => $w->where('status', $status))
            ->when($f['form'] ?? null, fn ($w, $id) => $w->where('application_form_id', $id))
            // The answers are JSON: the search looks through all of them (name, email, phone, ...).
            ->when($q !== '', fn ($w) => $w->where('data', 'like', '%'.addcslashes($q, '%_\\').'%'))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Applicant $a) => self::row($a));

        return Inertia::render('Recruitment/Pelamar', [
            'applicants' => $applicants,
            'filters' => ['status' => $status, 'form' => $f['form'] ?? '', 'q' => $q],
            'counts' => Applicant::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'forms' => ApplicationForm::orderBy('title')->get(['id', 'title']),
            'stages' => RecruitmentStage::orderBy('order')->get(['id', 'title', 'color']),
            'can_edit' => $request->user()->canEdit('recruitment'),
        ]);
    }

    /** All answers of one application. */
    public function show(Applicant $applicant): JsonResponse
    {
        $applicant->load(['form:id,title,posisi', 'candidate.stage:id,title,color']);

        return response()->json(self::row($applicant) + ['jawaban' => Lamaran::view($applicant), 'catatan' => $applicant->catatan]);
    }

    /** Into Recruitment Tracking as a candidate: the first stage unless another is chosen. */
    public function moveToTracking(Request $request, Applicant $applicant): RedirectResponse
    {
        $data = $request->validate([
            'stage_id' => ['nullable', 'integer', 'exists:recruitment_stages,id'],
            'position' => ['nullable', 'string', 'max:120'],
        ]);
        if ($applicant->candidate()->exists()) {
            throw ValidationException::withMessages(['applicant' => 'Pelamar ini sudah ada di Recruitment Tracking.']);
        }
        $stage = isset($data['stage_id']) ? RecruitmentStage::findOrFail($data['stage_id']) : RecruitmentStage::orderBy('order')->first();
        if (! $stage) {
            throw ValidationException::withMessages(['applicant' => 'Belum ada tahap di Recruitment Tracking; tambahkan dulu.']);
        }
        $applicant->load('form');
        $cv = Lamaran::file($applicant);
        $candidate = DB::transaction(function () use ($applicant, $stage, $data, $cv, $request) {
            $candidate = Candidate::create([
                'recruitment_stage_id' => $stage->id,
                'applicant_id' => $applicant->id,
                'name' => $applicant->nama(),
                'email' => (string) ($applicant->jawaban('email') ?? ''),
                'no_hp' => $applicant->jawaban('no_hp'),
                'position' => trim($data['position'] ?? '') ?: ($applicant->jawaban('posisi') ?? $applicant->form?->posisi ?? $applicant->form?->title ?? 'Umum'),
                'portfolio_url' => is_string($p = $applicant->jawaban('portofolio')) ? $p : null,
                'cv_path' => $cv['path'] ?? null,
                'cv_nama' => $cv['nama'] ?? null,
                'status' => $stage->title,
                'applied_date' => $applicant->submitted_at->toDateString(),
                'order' => (int) Candidate::where('recruitment_stage_id', $stage->id)->max('order') + 1,
            ]);
            StatusHistory::create(['candidate_id' => $candidate->id, 'from' => 'Pelamar', 'to' => $stage->title, 'changed_by' => $request->user()->name]);
            $applicant->update(['status' => 'diproses', 'is_processed' => true]);

            return $candidate;
        });
        Inertia::flash('success', "{$candidate->name} masuk Recruitment Tracking, tahap {$stage->title}.");

        return back();
    }

    /** Turned down (with a note), or back to "baru". */
    public function reject(Request $request, Applicant $applicant): RedirectResponse
    {
        $data = $request->validate(['tolak' => ['required', 'boolean'], 'catatan' => ['nullable', 'string', 'max:255']]);
        if ($applicant->candidate()->exists()) {
            throw ValidationException::withMessages(['applicant' => 'Pelamar ini sudah di Recruitment Tracking; tolak dari kartunya di sana.']);
        }
        $applicant->update(['status' => $data['tolak'] ? 'ditolak' : 'baru', 'catatan' => $data['tolak'] ? ($data['catatan'] ?? null) : null]);
        Inertia::flash('success', $data['tolak'] ? "Lamaran {$applicant->nama()} ditolak." : "Lamaran {$applicant->nama()} kembali ke daftar baru.");

        return back();
    }

    public function destroy(Applicant $applicant): RedirectResponse
    {
        if ($applicant->candidate()->exists()) {
            throw ValidationException::withMessages(['applicant' => 'Pelamar ini sudah di Recruitment Tracking; hapus kartunya di sana dulu.']);
        }
        $applicant->delete();
        Inertia::flash('success', "Lamaran {$applicant->nama()} dihapus beserta file-nya.");

        return back();
    }

    /** A file the applicant sent (CV, certificate, photo). */
    public function file(Applicant $applicant, string $field): StreamedResponse
    {
        $file = Lamaran::file($applicant, $field);
        abort_unless($file && Storage::disk(Lamaran::DISK)->exists($file['path']), 404);

        return Storage::disk(Lamaran::DISK)->response($file['path'], $file['nama'] ?? 'file', ['X-Content-Type-Options' => 'nosniff']);
    }

    public static function row(Applicant $a): array
    {
        $cv = Lamaran::file($a);

        return [
            'id' => $a->id,
            'nama' => $a->nama(),
            'email' => $a->jawaban('email'),
            'no_hp' => $a->jawaban('no_hp'),
            'posisi' => $a->jawaban('posisi') ?? $a->form?->posisi,
            'form' => $a->form ? ['id' => $a->form->id, 'title' => $a->form->title] : null,
            'submitted_at' => $a->submitted_at?->toIso8601String(),
            'status' => $a->status,
            'cv' => $cv ? ['nama' => $cv['nama'] ?? 'CV', 'url' => route('recruitment.pelamar.file', [$a, self::cvField($a)])] : null,
            'tahap' => $a->candidate ? ['title' => $a->candidate->stage?->title, 'color' => $a->candidate->stage?->color, 'ditolak' => $a->candidate->ditolak_at !== null] : null,
        ];
    }

    private static function cvField(Applicant $a): string
    {
        foreach ($a->data ?? [] as $id => $answer) {
            if (($answer['kunci'] ?? null) === 'cv' && is_array($answer['value'] ?? null)) {
                return (string) $id;
            }
        }

        return '0';
    }
}
