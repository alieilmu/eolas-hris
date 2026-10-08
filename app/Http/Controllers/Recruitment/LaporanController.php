<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\ApplicationForm;
use App\Models\Candidate;
use App\Models\RecruitmentStage;
use App\Support\Excel\TableExcel;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan Recruitment for a year: how many applied, how far candidates got (each stage
 * counts everyone who reached it), who was hired or not taken further, per form, month
 * and position — on screen and in Excel.
 */
class LaporanController extends Controller
{
    public function index(Request $request): Response
    {
        $tahun = $this->tahun($request);

        return Inertia::render('Recruitment/Laporan', [
            'tahun' => $tahun,
            'tahuns' => $this->tahuns($tahun),
            ...$this->data($tahun),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $tahun = $this->tahun($request);
        $d = $this->data($tahun);
        $info = TableExcel::info(["Tahun {$tahun}", "{$d['ringkasan']['pelamar']} pelamar, {$d['ringkasan']['kandidat']} kandidat, {$d['ringkasan']['diterima']} diterima"]);
        $bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

        return TableExcel::download("laporan-recruitment-{$tahun}-".now()->format('Y-m-d').'.xlsx', [
            [
                'title' => 'Tahapan',
                'info' => $info,
                'columns' => [['No', 5, 'int'], ['Tahap', 26], ['Mencapai Tahap', 12, 'int'], ['% dari Kandidat', 12], ['Ada di Tahap Sekarang', 14, 'int'], ['Tidak Dilanjutkan di Tahap Ini', 16, 'int']],
                'rows' => array_map(fn (array $s, int $i) => [$i + 1, $s['title'], $s['mencapai'], $s['persen'].'%', $s['sekarang'], $s['ditolak']], $d['tahapan'], array_keys($d['tahapan'])),
            ],
            [
                'title' => 'Per Form',
                'info' => $info,
                'columns' => [['No', 5, 'int'], ['Form / Lowongan', 34], ['Posisi', 22], ['Pelamar', 9, 'int', 'sum' => true], ['Baru', 8, 'int', 'sum' => true], ['Ke Tracking', 10, 'int', 'sum' => true], ['Ditolak', 9, 'int', 'sum' => true], ['Diterima', 9, 'int', 'sum' => true]],
                'rows' => array_map(fn (array $f, int $i) => [$i + 1, $f['title'], $f['posisi'], $f['pelamar'], $f['baru'], $f['diproses'], $f['ditolak'], $f['diterima']], $d['per_form'], array_keys($d['per_form'])),
            ],
            [
                'title' => 'Per Bulan',
                'info' => $info,
                'columns' => [['Bulan', 10], ['Pelamar', 9, 'int', 'sum' => true], ['Kandidat Baru', 11, 'int', 'sum' => true], ['Diterima', 9, 'int', 'sum' => true], ['Tidak Dilanjutkan', 12, 'int', 'sum' => true]],
                'rows' => array_map(fn (array $m) => [$bulan[$m['bulan'] - 1]." {$tahun}", $m['pelamar'], $m['kandidat'], $m['diterima'], $m['ditolak']], $d['per_bulan']),
            ],
            [
                'title' => 'Kandidat',
                'info' => $info,
                'freeze' => 2,
                'columns' => [['No', 5, 'int'], ['Nama', 26], ['Posisi', 22], ['Email', 26], ['No HP', 15], ['Tgl Melamar', 12, 'date'], ['Tahap Sekarang', 20], ['Status', 18], ['NRP', 11], ['Hari Proses', 9, 'int'], ['Alasan Tidak Dilanjutkan', 30]],
                'rows' => array_map(fn (array $c, int $i) => [$i + 1, $c['name'], $c['position'], $c['email'], $c['no_hp'], $c['applied_date'], $c['tahap'], $c['status'], $c['nrp'], $c['hari'], $c['alasan']], $d['kandidat'], array_keys($d['kandidat'])),
            ],
        ]);
    }

    private function tahun(Request $request): int
    {
        return (int) ($request->validate(['tahun' => ['nullable', 'integer', 'min:2000', 'max:2100']])['tahun'] ?? now()->year);
    }

    private function tahuns(int $tahun): array
    {
        $first = (int) min(Applicant::min('submitted_at') ? CarbonImmutable::parse(Applicant::min('submitted_at'))->year : now()->year,
            Candidate::min('applied_date') ? CarbonImmutable::parse(Candidate::min('applied_date'))->year : now()->year, $tahun);

        return range(max(now()->year, $tahun), $first);
    }

    /** Everything on the page, for applications and candidates of $tahun. */
    private function data(int $tahun): array
    {
        $applicants = Applicant::whereYear('submitted_at', $tahun)->get(['id', 'application_form_id', 'status', 'submitted_at']);
        $candidates = Candidate::with(['statusHistories:id,candidate_id,to,created_at', 'karyawan:id,nrp,candidate_id,created_at', 'stage:id,title,order', 'applicant:id,application_form_id'])
            ->whereYear('applied_date', $tahun)->get();
        $stages = RecruitmentStage::orderBy('order')->get();
        $orderOf = $stages->pluck('order', 'title');

        // The furthest stage each candidate reached: the current one, or a later one it passed through.
        $reached = $candidates->mapWithKeys(fn (Candidate $c) => [$c->id => max([
            $c->stage?->order ?? -1,
            ...$c->statusHistories->map(fn ($h) => $orderOf[$h->to] ?? -1)->all(),
        ])]);
        $total = max(1, $candidates->count());
        $tahapan = $stages->map(fn (RecruitmentStage $s) => [
            'id' => $s->id,
            'title' => $s->title,
            'color' => $s->color ?? '#64748b',
            'mencapai' => $n = $reached->filter(fn ($o) => $o >= $s->order)->count(),
            'persen' => round($n / $total * 100),
            'sekarang' => $candidates->where('recruitment_stage_id', $s->id)->whereNull('ditolak_at')->count(),
            'ditolak' => $candidates->where('recruitment_stage_id', $s->id)->whereNotNull('ditolak_at')->count(),
        ])->values()->all();

        $hired = $candidates->filter(fn (Candidate $c) => $c->karyawan);
        $days = $hired->map(fn (Candidate $c) => (int) $c->applied_date->diffInDays(CarbonImmutable::parse($c->karyawan->created_at)->startOfDay()));

        $perForm = ApplicationForm::orderBy('title')->get(['id', 'title', 'posisi'])->map(function (ApplicationForm $f) use ($applicants, $candidates) {
            $mine = $applicants->where('application_form_id', $f->id);

            return [
                'id' => $f->id,
                'title' => $f->title,
                'posisi' => $f->posisi,
                'pelamar' => $mine->count(),
                'baru' => $mine->where('status', 'baru')->count(),
                'diproses' => $mine->where('status', 'diproses')->count(),
                'ditolak' => $mine->where('status', 'ditolak')->count() + $candidates->filter(fn ($c) => $c->applicant?->application_form_id === $f->id && $c->ditolak_at)->count(),
                'diterima' => $candidates->filter(fn ($c) => $c->applicant?->application_form_id === $f->id && $c->karyawan)->count(),
            ];
        })->filter(fn ($f) => $f['pelamar'] > 0)->values()->all();

        $perBulan = array_map(fn (int $m) => [
            'bulan' => $m,
            'pelamar' => $applicants->filter(fn ($a) => $a->submitted_at->month === $m)->count(),
            'kandidat' => $candidates->filter(fn ($c) => $c->applied_date->month === $m)->count(),
            'diterima' => $hired->filter(fn ($c) => CarbonImmutable::parse($c->karyawan->created_at)->year === $tahun && CarbonImmutable::parse($c->karyawan->created_at)->month === $m)->count(),
            'ditolak' => $candidates->filter(fn ($c) => $c->ditolak_at && $c->ditolak_at->year === $tahun && $c->ditolak_at->month === $m)->count(),
        ], range(1, 12));

        $perPosisi = $candidates->groupBy('position')->map(fn (Collection $g, string $posisi) => [
            'posisi' => $posisi,
            'kandidat' => $g->count(),
            'proses' => $g->filter(fn ($c) => ! $c->ditolak_at && ! $c->karyawan)->count(),
            'diterima' => $g->filter(fn ($c) => $c->karyawan)->count(),
            'ditolak' => $g->filter(fn ($c) => $c->ditolak_at)->count(),
        ])->sortByDesc('kandidat')->values()->all();

        $today = CarbonImmutable::today();

        return [
            'ringkasan' => [
                'pelamar' => $applicants->count(),
                'pelamar_baru' => $applicants->where('status', 'baru')->count(),
                'kandidat' => $candidates->count(),
                'proses' => $candidates->filter(fn ($c) => ! $c->ditolak_at && ! $c->karyawan)->count(),
                'diterima' => $hired->count(),
                'ditolak' => $candidates->whereNotNull('ditolak_at')->count() + $applicants->where('status', 'ditolak')->count(),
                'rata_hari' => $days->isNotEmpty() ? (int) round($days->avg()) : null,
            ],
            'tahapan' => $tahapan,
            'per_form' => $perForm,
            'per_bulan' => $perBulan,
            'per_posisi' => $perPosisi,
            'kandidat' => $candidates->sortBy('applied_date')->values()->map(fn (Candidate $c) => [
                'name' => $c->name,
                'position' => $c->position,
                'email' => $c->email ?: null,
                'no_hp' => $c->no_hp,
                'applied_date' => $c->applied_date->toDateString(),
                'tahap' => $c->stage?->title,
                'status' => match (true) {
                    (bool) $c->karyawan => 'Diterima',
                    (bool) $c->ditolak_at => 'Tidak dilanjutkan',
                    default => 'Dalam proses',
                },
                'nrp' => $c->karyawan?->nrp,
                'hari' => (int) $c->applied_date->diffInDays($c->karyawan ? CarbonImmutable::parse($c->karyawan->created_at)->startOfDay() : ($c->ditolak_at ? CarbonImmutable::parse($c->ditolak_at)->startOfDay() : $today)),
                'alasan' => $c->alasan_ditolak,
            ])->all(),
        ];
    }
}
