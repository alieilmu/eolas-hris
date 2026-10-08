<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\CandidateNote;
use App\Models\Karyawan;
use App\Models\RecruitmentStage;
use App\Models\StatusHistory;
use App\Support\Karyawan\KaryawanFormOptions;
use App\Support\Karyawan\Nrp;
use App\Support\Org\Penempatan;
use App\Support\Recruitment\Lamaran;
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
 * Recruitment Tracking: the stages as columns and the candidates as cards, moved from
 * stage to stage (every move is kept in the candidate's history). A candidate at the
 * last stage becomes an employee with an NRP and a position.
 */
class TrackingController extends Controller
{
    public const COLORS = ['#64748b', '#f43f5e', '#f59e0b', '#84cc16', '#10b981', '#0ea5e9', '#3b82f6', '#8b5cf6', '#ec4899', '#22c55e'];

    public function index(Request $request): Response
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'posisi' => ['nullable', 'string', 'max:120'],
            'ditolak' => ['nullable', 'boolean'],
        ]);
        $q = trim($f['q'] ?? '');
        $ditolak = (bool) ($f['ditolak'] ?? false);

        $stages = RecruitmentStage::orderBy('order')->get();
        $candidates = Candidate::with(['karyawan:id,nrp,candidate_id', 'statusHistories' => fn ($h) => $h->limit(1)])
            ->withCount('notes')
            ->when(! $ditolak, fn ($w) => $w->whereNull('ditolak_at'))
            ->when($f['posisi'] ?? null, fn ($w, $p) => $w->where('position', $p))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('position', 'like', "%{$q}%")))
            ->orderBy('order')
            ->get()
            ->groupBy('recruitment_stage_id');
        $today = CarbonImmutable::today();

        return Inertia::render('Recruitment/Tracking', [
            'stages' => $stages->map(fn (RecruitmentStage $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'color' => $s->color ?? '#64748b',
                'candidates' => ($candidates[$s->id] ?? collect())->map(fn (Candidate $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'position' => $c->position,
                    'email' => $c->email,
                    'no_hp' => $c->no_hp,
                    'applied_date' => $c->applied_date?->toDateString(),
                    // Days in this stage: since the last move, else since it was added.
                    'hari_di_tahap' => (int) CarbonImmutable::parse($c->statusHistories->first()?->created_at ?? $c->created_at)->startOfDay()->diffInDays($today),
                    'notes_count' => $c->notes_count,
                    'has_cv' => (bool) $c->cv_path,
                    'ditolak' => $c->ditolak_at !== null,
                    'nrp' => $c->karyawan?->nrp,
                ])->values(),
            ]),
            'filters' => ['q' => $q, 'posisi' => $f['posisi'] ?? '', 'ditolak' => $ditolak],
            'posisi_options' => Candidate::distinct()->orderBy('position')->pluck('position'),
            'colors' => self::COLORS,
            'jumlah' => ['aktif' => Candidate::whereNull('ditolak_at')->count(), 'ditolak' => Candidate::whereNotNull('ditolak_at')->count()],
            'can_edit' => $request->user()->canEdit('recruitment'),
            'can_karyawan' => $request->user()->canEdit('karyawan'),
            ...KaryawanFormOptions::props(),
        ]);
    }

    // --- stages ------------------------------------------------------------------------------

    public function storeStage(Request $request): RedirectResponse
    {
        $data = $this->stageData($request);
        RecruitmentStage::create([...$data, 'status' => $data['title'], 'order' => (int) RecruitmentStage::max('order') + 1]);
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
        $arah = $request->validate(['arah' => ['required', Rule::in(['kiri', 'kanan'])]])['arah'];
        $stages = RecruitmentStage::orderBy('order')->get()->values();
        $i = $stages->search(fn ($s) => $s->is($stage));
        $j = $arah === 'kiri' ? $i - 1 : $i + 1;
        if (isset($stages[$j])) {
            [$stages[$i], $stages[$j]] = [$stages[$j], $stages[$i]];
            DB::transaction(fn () => $stages->each(fn (RecruitmentStage $s, int $n) => $s->update(['order' => $n])));
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
                StatusHistory::create(['candidate_id' => $candidate->id, 'from' => $candidate->status, 'to' => $stage->title, 'changed_by' => $request->user()->name]);
                $candidate->update(['recruitment_stage_id' => $stage->id, 'status' => $stage->title]);
            }
            foreach (array_values($data['ordered_ids']) as $order => $id) {
                Candidate::whereKey($id)->where('recruitment_stage_id', $stage->id)->update(['order' => $order]);
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
            'order' => (int) Candidate::where('recruitment_stage_id', $stage->id)->max('order') + 1,
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
        $candidate->load(['notes', 'statusHistories', 'karyawan:id,nrp,name,candidate_id', 'applicant.form:id,title', 'stage:id,title,color']);
        $last = RecruitmentStage::orderByDesc('order')->value('id');

        return response()->json([
            'id' => $candidate->id,
            'name' => $candidate->name,
            'email' => $candidate->email,
            'no_hp' => $candidate->no_hp,
            'position' => $candidate->position,
            'portfolio_url' => $candidate->portfolio_url,
            'applied_date' => $candidate->applied_date?->toDateString(),
            'stage' => ['id' => $candidate->stage->id, 'title' => $candidate->stage->title, 'color' => $candidate->stage->color],
            'tahap_terakhir' => $candidate->recruitment_stage_id === $last,
            'cv' => $candidate->cv_path ? ['nama' => $candidate->cv_nama ?? 'CV', 'url' => route('recruitment.kandidat.cv', $candidate)] : null,
            'ditolak' => $candidate->ditolak_at ? ['tanggal' => $candidate->ditolak_at->toDateString(), 'alasan' => $candidate->alasan_ditolak] : null,
            'karyawan' => $candidate->karyawan ? ['id' => $candidate->karyawan->id, 'nrp' => $candidate->karyawan->nrp] : null,
            'form' => $candidate->applicant?->form?->title,
            'jawaban' => $candidate->applicant ? Lamaran::view($candidate->applicant) : [],
            'notes' => $candidate->notes->map(fn (CandidateNote $n) => ['id' => $n->id, 'author' => $n->author, 'content' => $n->content, 'waktu' => $n->created_at->toIso8601String()]),
            'history' => $candidate->statusHistories->map(fn (StatusHistory $h) => ['from' => $h->from, 'to' => $h->to, 'by' => $h->changed_by, 'waktu' => $h->created_at->toIso8601String()]),
        ]);
    }

    public function storeNote(Request $request, Candidate $candidate): JsonResponse
    {
        $data = $request->validate(['content' => ['required', 'string', 'max:2000']], [], ['content' => 'Catatan']);
        $note = $candidate->notes()->create(['author' => $request->user()->name, 'content' => trim($data['content'])]);

        return response()->json(['id' => $note->id, 'author' => $note->author, 'content' => $note->content, 'waktu' => $note->created_at->toIso8601String()], 201);
    }

    public function destroyNote(CandidateNote $note): JsonResponse
    {
        $note->delete();

        return response()->json(['ok' => true]);
    }

    /** Not taken further (kept, out of the board), or back on the board. */
    public function reject(Request $request, Candidate $candidate): RedirectResponse
    {
        $data = $request->validate(['tolak' => ['required', 'boolean'], 'alasan' => ['nullable', 'string', 'max:255', 'required_if_accepted:tolak']],
            ['alasan.required_if_accepted' => 'Tulis alasan tidak dilanjutkan.']);
        $tolak = (bool) $data['tolak'];
        $candidate->update(['ditolak_at' => $tolak ? now() : null, 'alasan_ditolak' => $tolak ? trim($data['alasan']) : null]);
        StatusHistory::create(['candidate_id' => $candidate->id, 'from' => $candidate->status, 'to' => $tolak ? 'Tidak dilanjutkan' : 'Dilanjutkan kembali', 'changed_by' => $request->user()->name]);
        Inertia::flash('success', $tolak ? "{$candidate->name} tidak dilanjutkan." : "{$candidate->name} kembali ke papan.");

        return back();
    }

    public function destroy(Candidate $candidate): RedirectResponse
    {
        if ($candidate->karyawan()->exists()) {
            throw ValidationException::withMessages(['candidate' => "{$candidate->name} sudah menjadi karyawan; datanya tidak bisa dihapus dari sini."]);
        }
        $candidate->delete();
        // Its application goes back to the Pelamar list.
        $candidate->applicant?->update(['status' => 'baru', 'is_processed' => false]);
        Inertia::flash('success', "{$candidate->name} dihapus dari Recruitment Tracking.");

        return back();
    }

    public function cv(Candidate $candidate): StreamedResponse
    {
        abort_unless($candidate->cv_path && Storage::disk(Lamaran::DISK)->exists($candidate->cv_path), 404);

        return Storage::disk(Lamaran::DISK)->response($candidate->cv_path, $candidate->cv_nama ?? 'cv', ['X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Hired: an employee with the NRP and position chosen (as in Tambah Karyawan), linked to this
     * candidate, with what is known (start date, phone, email) already in the profile.
     */
    public function convert(Request $request, Candidate $candidate): RedirectResponse
    {
        if ($candidate->karyawan()->exists()) {
            throw ValidationException::withMessages(['nrp' => "{$candidate->name} sudah menjadi karyawan."]);
        }
        $data = $request->validate([
            'nrp' => ['required', 'string', 'max:20', 'unique:karyawans,nrp'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'tanggal_masuk' => ['nullable', 'date_format:Y-m-d'],
        ], ['nrp.unique' => 'NRP ":input" sudah digunakan.']);
        if ($error = Nrp::error($data['nrp'])) {
            throw ValidationException::withMessages(['nrp' => $error]);
        }
        ['attributes' => $placement, 'note' => $note] = Penempatan::resolve($request);

        $karyawan = DB::transaction(function () use ($candidate, $data, $placement, $request) {
            $karyawan = Karyawan::create([...collect($data)->only(['nrp', 'name', 'email'])->all(), ...$placement, 'status' => Karyawan::STATUS_AKTIF, 'candidate_id' => $candidate->id]);
            $karyawan->profile()->create([
                'tanggal_masuk' => $data['tanggal_masuk'] ?? CarbonImmutable::today()->toDateString(),
                'status_pegawai' => 'PKWT',
                'no_hp' => $candidate->no_hp,
                'no_whatsapp' => $candidate->no_hp,
                'email_pribadi' => $candidate->email ?: null,
            ]);
            StatusHistory::create(['candidate_id' => $candidate->id, 'from' => $candidate->status, 'to' => "Menjadi karyawan (NRP {$karyawan->nrp})", 'changed_by' => $request->user()->name]);

            return $karyawan;
        });
        Inertia::flash('success', "{$karyawan->name} menjadi karyawan dengan NRP {$karyawan->nrp}.".($note ? " {$note}" : ' Lengkapi profilnya di Data Karyawan.'));

        return back();
    }

    private function candidateData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'no_hp' => ['nullable', 'string', 'max:30'],
            'position' => ['required', 'string', 'max:120'],
            'portfolio_url' => ['nullable', 'url', 'max:255'],
            'applied_date' => ['required', 'date_format:Y-m-d'],
            'cv' => ['nullable', 'file', 'mimes:'.implode(',', Lamaran::FILE_MIMES), 'max:'.intdiv(Lamaran::maxBytes(), 1024)],
        ], ['cv.mimes' => 'CV harus PDF, Word, JPG atau PNG.', 'cv.max' => 'CV paling besar '.Lamaran::maxLabel().'.'],
            ['name' => 'Nama', 'position' => 'Posisi', 'applied_date' => 'Tanggal melamar', 'no_hp' => 'Nomor HP']);
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
            'cv_path' => $file->storeAs("rekrutmen/kandidat/{$candidate->id}", Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()), Lamaran::DISK),
            'cv_nama' => Str::limit($file->getClientOriginalName(), 120, ''),
        ]);
        // Only a CV HR uploaded before; one from the application stays with it.
        if ($old && str_starts_with($old, 'rekrutmen/kandidat/')) {
            Storage::disk(Lamaran::DISK)->delete($old);
        }
    }
}
