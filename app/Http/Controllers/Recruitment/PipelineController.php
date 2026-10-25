<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\CandidateNote;
use App\Models\Employee;
use App\Models\RecruitmentStage;
use App\Models\CandidateStageHistory;
use App\Support\Employees\EmployeeFormOptions;
use App\Support\Employees\EmployeeNumber;
use App\Support\Organization\Placement;
use App\Support\Recruitment\Application;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pipeline Kandidat: the stages as columns and the candidates as cards, moved from
 * stage to stage (every move is kept in the candidate's history). A candidate at the
 * last stage becomes an employee with an NRP and a position.
 */
class PipelineController extends Controller
{
    public const COLORS = ['#64748b', '#f43f5e', '#f59e0b', '#84cc16', '#10b981', '#0ea5e9', '#3b82f6', '#8b5cf6', '#ec4899', '#22c55e'];

    public function index(Request $request): Response
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'position_title' => ['nullable', 'string', 'max:120'],
            'rejected' => ['nullable', 'boolean'],
        ]);
        $q = trim($f['q'] ?? '');
        $rejected = (bool) ($f['rejected'] ?? false);

        $stages = RecruitmentStage::orderBy('sort_order')->get();
        $candidates = Candidate::with(['employee:id,employee_number,candidate_id', 'statusHistories' => fn ($h) => $h->limit(1)])
            ->withCount('notes')
            ->when(! $rejected, fn ($w) => $w->whereNull('rejected_at'))
            ->when($f['position_title'] ?? null, fn ($w, $p) => $w->where('position', $p))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('position', 'like', "%{$q}%")))
            ->orderBy('sort_order')
            ->get()
            ->groupBy('recruitment_stage_id');
        $today = CarbonImmutable::today();

        return Inertia::render('Recruitment/Pipeline', [
            'stages' => $stages->map(fn (RecruitmentStage $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'color' => $s->color ?? '#64748b',
                'candidates' => ($candidates[$s->id] ?? collect())->map(fn (Candidate $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'position' => $c->position,
                    'email' => $c->email,
                    'phone' => $c->phone,
                    'applied_date' => $c->applied_date?->toDateString(),
                    // Days in this stage: since the last move, else since it was added.
                    'days_in_stage' => (int) CarbonImmutable::parse($c->statusHistories->first()?->created_at ?? $c->created_at)->startOfDay()->diffInDays($today),
                    'notes_count' => $c->notes_count,
                    'has_cv' => (bool) $c->cv_path,
                    'rejected' => $c->rejected_at !== null,
                    'employee_number' => $c->employee?->employee_number,
                ])->values(),
            ]),
            'filters' => ['q' => $q, 'position_title' => $f['position_title'] ?? '', 'rejected' => $rejected],
            'position_title_options' => Candidate::distinct()->orderBy('position')->pluck('position'),
            'colors' => self::COLORS,
            'quantity' => ['active' => Candidate::whereNull('rejected_at')->count(), 'rejected' => Candidate::whereNotNull('rejected_at')->count()],
            'can_edit' => $request->user()->canEdit('recruitment'),
            'can_employee' => $request->user()->canEdit('employees'),
            ...EmployeeFormOptions::props(),
        ]);
    }

    // --- stages ------------------------------------------------------------------------------

    public function storeStage(Request $request): RedirectResponse
    {
        $data = $this->stageData($request);
        RecruitmentStage::create([...$data, 'status' => $data['title'], 'sort_order' => (int) RecruitmentStage::max('sort_order') + 1]);
        Inertia::flash('success', "Tahap {$data['title']} ditambahkan.");

        return back();
    }

    public function updateStage(Request $request, RecruitmentStage $stage): RedirectResponse
    {
        $data = $this->stageData($request, $stage);
        DB::transaction(function () use ($stage, $data) {
            $stage->update([...$data, 'status' => $data['title']]);
            // The cards show their stage's name.
            $stage->candidates()->update(['status' => $data['title']]);
        });
        Inertia::flash('success', "Tahap {$data['title']} diperbarui.");

        return back();
    }

    /** One place to the left or right. */
    public function moveStage(Request $request, RecruitmentStage $stage): RedirectResponse
    {
        $direction = $request->validate(['direction' => ['required', Rule::in(['left', 'right'])]])['direction'];
        $stages = RecruitmentStage::orderBy('sort_order')->get()->values();
        $i = $stages->search(fn ($s) => $s->is($stage));
        $j = $direction === 'left' ? $i - 1 : $i + 1;
        if (isset($stages[$j])) {
            [$stages[$i], $stages[$j]] = [$stages[$j], $stages[$i]];
            DB::transaction(fn () => $stages->each(fn (RecruitmentStage $s, int $n) => $s->update(['sort_order' => $n])));
        }

        return back();
    }

    public function destroyStage(RecruitmentStage $stage): RedirectResponse
    {
        if ($stage->candidates()->exists()) {
            throw ValidationException::withMessages(['stage' => "Tahap {$stage->title} masih berisi kandidat; pindahkan dulu kandidatnya."]);
        }
        if (RecruitmentStage::count() <= 1) {
            throw ValidationException::withMessages(['stage' => 'Harus ada minimal satu tahap.']);
        }
        $stage->delete();
        Inertia::flash('success', "Tahap {$stage->title} dihapus.");

        return back();
    }

    private function stageData(Request $request, ?RecruitmentStage $current = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:60', Rule::unique('recruitment_stages', 'title')->ignore($current?->id)],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], ['title.unique' => 'Sudah ada tahap bernama ini.'], ['title' => 'Nama tahap']);
    }

    // --- candidates on the board -------------------------------------------------------------

    /** A card dropped in $stage_id; $ordered_ids is that column's order after the drop. */
    public function move(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'candidate_id' => ['required', 'integer', 'exists:candidates,id'],
            'stage_id' => ['required', 'integer', 'exists:recruitment_stages,id'],
            'ordered_ids' => ['required', 'array'],
            'ordered_ids.*' => ['integer'],
        ]);
        $stage = RecruitmentStage::findOrFail($data['stage_id']);
        $candidate = Candidate::findOrFail($data['candidate_id']);
        DB::transaction(function () use ($stage, $candidate, $data, $request) {
            if ($candidate->recruitment_stage_id !== $stage->id) {
                CandidateStageHistory::create(['candidate_id' => $candidate->id, 'from' => $candidate->status, 'to' => $stage->title, 'changed_by' => $request->user()->name]);
                $candidate->update(['recruitment_stage_id' => $stage->id, 'status' => $stage->title]);
            }
            foreach (array_values($data['ordered_ids']) as $order => $id) {
                Candidate::whereKey($id)->where('recruitment_stage_id', $stage->id)->update(['sort_order' => $order]);
            }
        });

        return back();
    }

    /** A candidate typed in by HR (a referral, a walk-in), in the chosen stage. */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->candidateData($request);
        $stage = RecruitmentStage::findOrFail($request->validate(['stage_id' => ['required', 'integer', 'exists:recruitment_stages,id']])['stage_id']);
        $candidate = Candidate::create([
            ...$data,
            'recruitment_stage_id' => $stage->id,
            'status' => $stage->title,
            'sort_order' => (int) Candidate::where('recruitment_stage_id', $stage->id)->max('sort_order') + 1,
        ]);
        $this->storeCv($request, $candidate);
        Inertia::flash('success', "{$candidate->name} ditambahkan ke tahap {$stage->title}.");

        return back();
    }

    public function update(Request $request, Candidate $candidate): RedirectResponse
    {
        $candidate->update($this->candidateData($request));
        $this->storeCv($request, $candidate);
        Inertia::flash('success', "Data {$candidate->name} diperbarui.");

        return back();
    }

    /** Everything about one candidate for the detail window. */
    public function show(Candidate $candidate): JsonResponse
    {
        $candidate->load(['notes', 'statusHistories', 'employee:id,employee_number,name,candidate_id', 'applicant.form:id,title,position_id', 'applicant.form.position:id,name,work_location_id', 'stage:id,title,color']);
        $last = RecruitmentStage::orderByDesc('sort_order')->value('id');

        return response()->json([
            'id' => $candidate->id,
            'name' => $candidate->name,
            'email' => $candidate->email,
            'phone' => $candidate->phone,
            'position' => $candidate->position,
            'portfolio_url' => $candidate->portfolio_url,
            'applied_date' => $candidate->applied_date?->toDateString(),
            'stage' => ['id' => $candidate->stage->id, 'title' => $candidate->stage->title, 'color' => $candidate->stage->color],
            'last_stage' => $candidate->recruitment_stage_id === $last,
            'cv' => $candidate->cv_path ? ['name' => $candidate->cv_filename ?? 'CV', 'url' => route('recruitment.candidate.cv', $candidate)] : null,
            'rejected' => $candidate->rejected_at ? ['date' => $candidate->rejected_at->toDateString(), 'reason' => $candidate->rejection_reason] : null,
            'employee' => $candidate->employee ? ['id' => $candidate->employee->id, 'employee_number' => $candidate->employee->employee_number] : null,
            'form' => $candidate->applicant?->form?->title,
            // A vacancy opened from Man Power Planning: hired into that position.
            'job_position' => ($j = $candidate->applicant?->form?->position) ? ['id' => $j->id, 'name' => $j->name, 'work_location_id' => $j->work_location_id] : null,
            'answers' => $candidate->applicant ? Application::view($candidate->applicant) : [],
            'notes' => $candidate->notes->map(fn (CandidateNote $n) => ['id' => $n->id, 'author' => $n->author, 'content' => $n->content, 'created_at' => $n->created_at->toIso8601String()]),
            'history' => $candidate->statusHistories->map(fn (CandidateStageHistory $h) => ['from' => $h->from, 'to' => $h->to, 'by' => $h->changed_by, 'created_at' => $h->created_at->toIso8601String()]),
        ]);
    }

    public function storeNote(Request $request, Candidate $candidate): JsonResponse
    {
        $data = $request->validate(['content' => ['required', 'string', 'max:2000']], [], ['content' => 'Catatan']);
        $note = $candidate->notes()->create(['author' => $request->user()->name, 'content' => trim($data['content'])]);

        return response()->json(['id' => $note->id, 'author' => $note->author, 'content' => $note->content, 'created_at' => $note->created_at->toIso8601String()], 201);
    }

    public function destroyNote(CandidateNote $note): JsonResponse
    {
        $note->delete();

        return response()->json(['ok' => true]);
    }

    /** Not taken further (kept, out of the board), or back on the board. */
    public function reject(Request $request, Candidate $candidate): RedirectResponse
    {
        $data = $request->validate(['reject' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:255', 'required_if_accepted:reject']],
            ['reason.required_if_accepted' => 'Tulis alasan tidak dilanjutkan.']);
        $reject = (bool) $data['reject'];
        $candidate->update(['rejected_at' => $reject ? now() : null, 'rejection_reason' => $reject ? trim($data['reason']) : null]);
        CandidateStageHistory::create(['candidate_id' => $candidate->id, 'from' => $candidate->status, 'to' => $reject ? 'Tidak dilanjutkan' : 'Dilanjutkan kembali', 'changed_by' => $request->user()->name]);
        Inertia::flash('success', $reject ? "{$candidate->name} tidak dilanjutkan." : "{$candidate->name} kembali ke papan.");

        return back();
    }

    public function destroy(Candidate $candidate): RedirectResponse
    {
        if ($candidate->employee()->exists()) {
            throw ValidationException::withMessages(['candidate' => "{$candidate->name} sudah menjadi karyawan; datanya tidak bisa dihapus dari sini."]);
        }
        $candidate->delete();
        // Its application goes back to the Pelamar list.
        $candidate->applicant?->update(['status' => 'new', 'is_processed' => false]);
        Inertia::flash('success', "{$candidate->name} dihapus dari Pipeline Kandidat.");

        return back();
    }

    public function cv(Candidate $candidate): StreamedResponse
    {
        abort_unless($candidate->cv_path && Storage::disk(Application::DISK)->exists($candidate->cv_path), 404);

        return Storage::disk(Application::DISK)->response($candidate->cv_path, $candidate->cv_filename ?? 'cv', ['X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Hired: an employee with the NRP and position chosen (as in Tambah Karyawan), linked to this
     * candidate, with what is known (start date, phone, email) already in the profile.
     */
    public function convert(Request $request, Candidate $candidate): RedirectResponse
    {
        if ($candidate->employee()->exists()) {
            throw ValidationException::withMessages(['employee_number' => "{$candidate->name} sudah menjadi karyawan."]);
        }
        $data = $request->validate([
            'employee_number' => ['required', 'string', 'max:20', 'unique:employees,employee_number'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'hire_date' => ['nullable', 'date_format:Y-m-d'],
        ], ['employee_number.unique' => 'NRP ":input" sudah digunakan.']);
        if ($error = EmployeeNumber::error($data['employee_number'])) {
            throw ValidationException::withMessages(['employee_number' => $error]);
        }
        ['attributes' => $placement, 'note' => $note] = Placement::resolve($request);

        $employee = DB::transaction(function () use ($candidate, $data, $placement, $request) {
            $employee = Employee::create([...collect($data)->only(['employee_number', 'name', 'email'])->all(), ...$placement, 'status' => Employee::STATUS_ACTIVE, 'candidate_id' => $candidate->id]);
            $employee->profile()->create([
                'hire_date' => $data['hire_date'] ?? CarbonImmutable::today()->toDateString(),
                'employment_status' => 'PKWT',
                'phone' => $candidate->phone,
                'whatsapp_number' => $candidate->phone,
                'personal_email' => $candidate->email ?: null,
            ]);
            CandidateStageHistory::create(['candidate_id' => $candidate->id, 'from' => $candidate->status, 'to' => "Menjadi karyawan (NRP {$employee->employee_number})", 'changed_by' => $request->user()->name]);

            return $employee;
        });
        Inertia::flash('success', "{$employee->name} menjadi karyawan dengan NRP {$employee->employee_number}.".($note ? " {$note}" : ' Lengkapi profilnya di Data Karyawan.'));

        return back();
    }

    private function candidateData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'position' => ['required', 'string', 'max:120'],
            'portfolio_url' => ['nullable', 'url', 'max:255'],
            'applied_date' => ['required', 'date_format:Y-m-d'],
            'cv' => ['nullable', 'file', 'mimes:'.implode(',', Application::FILE_MIMES), 'max:'.intdiv(Application::maxBytes(), 1024)],
        ], ['cv.mimes' => 'CV harus PDF, Word, JPG atau PNG.', 'cv.max' => 'CV paling besar '.Application::maxLabel().'.'],
            ['name' => 'Nama', 'position' => 'Posisi', 'applied_date' => 'Tanggal melamar', 'phone' => 'Nomor HP']);
        unset($data['cv']);

        return [...$data, 'email' => $data['email'] ?? ''];
    }

    private function storeCv(Request $request, Candidate $candidate): void
    {
        if (! $request->hasFile('cv')) {
            return;
        }
        $file = $request->file('cv');
        $old = $candidate->cv_path;
        $candidate->update([
            'cv_path' => $file->storeAs("recruitment/candidate/{$candidate->id}", Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()), Application::DISK),
            'cv_filename' => Str::limit($file->getClientOriginalName(), 120, ''),
        ]);
        // Only a CV HR uploaded before; one from the application stays with it.
        if ($old && str_starts_with($old, 'recruitment/candidate/')) {
            Storage::disk(Application::DISK)->delete($old);
        }
    }
}
