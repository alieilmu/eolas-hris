<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\JobPosting;
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
 * Laporan Rekrutmen for a year: how many applied, how far candidates got (each stage
 * counts everyone who reached it), who was hired or not taken further, per form, month
 * and position — on screen and in Excel.
 */
class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $year = $this->year($request);

        return Inertia::render('Recruitment/Reports', [
            'year' => $year,
            'years' => $this->years($year),
            ...$this->data($year),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $year = $this->year($request);
        $d = $this->data($year);
        $info = TableExcel::info(["Tahun {$year}", "{$d['summary']['applicants']} pelamar, {$d['summary']['candidate']} kandidat, {$d['summary']['accepted']} diterima"]);
        $month = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

        return TableExcel::download("report-recruitment-{$year}-".now()->format('Y-m-d').'.xlsx', [
            [
                'title' => 'Stages',
                'info' => $info,
                'columns' => [['No', 5, 'int'], ['Stage', 26], ['Reached Stage', 12, 'int'], ['% of Candidates', 12], ['Currently at Stage', 14, 'int'], ['Dropped at Stage', 16, 'int']],
                'rows' => array_map(fn (array $s, int $i) => [$i + 1, $s['title'], $s['reached'], $s['percent'].'%', $s['now'], $s['rejected']], $d['stages'], array_keys($d['stages'])),
            ],
            [
                'title' => 'Per Form',
                'info' => $info,
                'columns' => [['No', 5, 'int'], ['Form / Job Posting', 34], ['Position', 22], ['Applicants', 9, 'int', 'sum' => true], ['New', 8, 'int', 'sum' => true], ['To Pipeline', 10, 'int', 'sum' => true], ['Rejected', 9, 'int', 'sum' => true], ['Hired', 9, 'int', 'sum' => true]],
                'rows' => array_map(fn (array $f, int $i) => [$i + 1, $f['title'], $f['position_title'], $f['applicants'], $f['new'], $f['in_process'], $f['rejected'], $f['accepted']], $d['per_form'], array_keys($d['per_form'])),
            ],
            [
                'title' => 'Per Month',
                'info' => $info,
                'columns' => [['Month', 10], ['Applicants', 9, 'int', 'sum' => true], ['New Candidates', 11, 'int', 'sum' => true], ['Hired', 9, 'int', 'sum' => true], ['Dropped', 12, 'int', 'sum' => true]],
                'rows' => array_map(fn (array $m) => [$month[$m['month'] - 1]." {$year}", $m['applicants'], $m['candidate'], $m['accepted'], $m['rejected']], $d['per_month']),
            ],
            [
                'title' => 'Candidates',
                'info' => $info,
                'freeze' => 2,
                'columns' => [['No', 5, 'int'], ['Name', 26], ['Position', 22], ['Email', 26], ['Mobile Number', 15], ['Applied Date', 12, 'date'], ['Current Stage', 20], ['Status', 18], ['Employee Number', 11], ['Days in Process', 9, 'int'], ['Drop Reason', 30]],
                'rows' => array_map(fn (array $c, int $i) => [$i + 1, $c['name'], $c['position'], $c['email'], $c['phone'], $c['applied_date'], $c['stage'], $c['status'], $c['employee_number'], $c['days'], $c['reason']], $d['candidate'], array_keys($d['candidate'])),
            ],
        ]);
    }

    private function year(Request $request): int
    {
        return (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']])['year'] ?? now()->year);
    }

    private function years(int $year): array
    {
        $first = (int) min(Applicant::min('submitted_at') ? CarbonImmutable::parse(Applicant::min('submitted_at'))->year : now()->year,
            Candidate::min('applied_date') ? CarbonImmutable::parse(Candidate::min('applied_date'))->year : now()->year, $year);

        return range(max(now()->year, $year), $first);
    }

    /** Everything on the page, for applications and candidates of $tahun. */
    private function data(int $year): array
    {
        $applicants = Applicant::whereYear('submitted_at', $year)->get(['id', 'job_posting_id', 'status', 'submitted_at']);
        $candidates = Candidate::with(['statusHistories:id,candidate_id,to,created_at', 'employee:id,employee_number,candidate_id,created_at', 'stage:id,title,sort_order', 'applicant:id,job_posting_id'])
            ->whereYear('applied_date', $year)->get();
        $stages = RecruitmentStage::orderBy('sort_order')->get();
        $orderOf = $stages->pluck('sort_order', 'title');

        // The furthest stage each candidate reached: the current one, or a later one it passed through.
        $reached = $candidates->mapWithKeys(fn (Candidate $c) => [$c->id => max([
            $c->stage?->sort_order ?? -1,
            ...$c->statusHistories->map(fn ($h) => $orderOf[$h->to] ?? -1)->all(),
        ])]);
        $total = max(1, $candidates->count());
        $tahapan = $stages->map(fn (RecruitmentStage $s) => [
            'id' => $s->id,
            'title' => $s->title,
            'color' => $s->color ?? '#64748b',
            'reached' => $n = $reached->filter(fn ($o) => $o >= $s->sort_order)->count(),
            'percent' => round($n / $total * 100),
            'now' => $candidates->where('recruitment_stage_id', $s->id)->whereNull('rejected_at')->count(),
            'rejected' => $candidates->where('recruitment_stage_id', $s->id)->whereNotNull('rejected_at')->count(),
        ])->values()->all();

        $hired = $candidates->filter(fn (Candidate $c) => $c->employee);
        $days = $hired->map(fn (Candidate $c) => (int) $c->applied_date->diffInDays(CarbonImmutable::parse($c->employee->created_at)->startOfDay()));

        $perForm = JobPosting::orderBy('title')->get(['id', 'title', 'position_title'])->map(function (JobPosting $f) use ($applicants, $candidates) {
            $mine = $applicants->where('job_posting_id', $f->id);

            return [
                'id' => $f->id,
                'title' => $f->title,
                'position_title' => $f->position_title,
                'applicants' => $mine->count(),
                'new' => $mine->where('status', 'new')->count(),
                'in_process' => $mine->where('status', 'in_process')->count(),
                'rejected' => $mine->where('status', 'rejected')->count() + $candidates->filter(fn ($c) => $c->applicant?->job_posting_id === $f->id && $c->rejected_at)->count(),
                'accepted' => $candidates->filter(fn ($c) => $c->applicant?->job_posting_id === $f->id && $c->employee)->count(),
            ];
        })->filter(fn ($f) => $f['applicants'] > 0)->values()->all();

        $perBulan = array_map(fn (int $m) => [
            'month' => $m,
            'applicants' => $applicants->filter(fn ($a) => $a->submitted_at->month === $m)->count(),
            'candidate' => $candidates->filter(fn ($c) => $c->applied_date->month === $m)->count(),
            'accepted' => $hired->filter(fn ($c) => CarbonImmutable::parse($c->employee->created_at)->year === $year && CarbonImmutable::parse($c->employee->created_at)->month === $m)->count(),
            'rejected' => $candidates->filter(fn ($c) => $c->rejected_at && $c->rejected_at->year === $year && $c->rejected_at->month === $m)->count(),
        ], range(1, 12));

        $perPosisi = $candidates->groupBy('position')->map(fn (Collection $g, string $position_title) => [
            'position_title' => $position_title,
            'candidate' => $g->count(),
            'process' => $g->filter(fn ($c) => ! $c->rejected_at && ! $c->employee)->count(),
            'accepted' => $g->filter(fn ($c) => $c->employee)->count(),
            'rejected' => $g->filter(fn ($c) => $c->rejected_at)->count(),
        ])->sortByDesc('candidate')->values()->all();

        $today = CarbonImmutable::today();

        return [
            'summary' => [
                'applicants' => $applicants->count(),
                'new_applicants' => $applicants->where('status', 'new')->count(),
                'candidate' => $candidates->count(),
                'process' => $candidates->filter(fn ($c) => ! $c->rejected_at && ! $c->employee)->count(),
                'accepted' => $hired->count(),
                'rejected' => $candidates->whereNotNull('rejected_at')->count() + $applicants->where('status', 'rejected')->count(),
                'average_days' => $days->isNotEmpty() ? (int) round($days->avg()) : null,
            ],
            'stages' => $tahapan,
            'per_form' => $perForm,
            'per_month' => $perBulan,
            'per_position_title' => $perPosisi,
            'candidate' => $candidates->sortBy('applied_date')->values()->map(fn (Candidate $c) => [
                'name' => $c->name,
                'position' => $c->position,
                'email' => $c->email ?: null,
                'phone' => $c->phone,
                'applied_date' => $c->applied_date->toDateString(),
                'stage' => $c->stage?->title,
                'status' => match (true) {
                    (bool) $c->employee => 'Diterima',
                    (bool) $c->rejected_at => 'Tidak dilanjutkan',
                    default => 'Dalam proses',
                },
                'employee_number' => $c->employee?->employee_number,
                'days' => (int) $c->applied_date->diffInDays($c->employee ? CarbonImmutable::parse($c->employee->created_at)->startOfDay() : ($c->rejected_at ? CarbonImmutable::parse($c->rejected_at)->startOfDay() : $today)),
                'reason' => $c->rejection_reason,
            ])->all(),
        ];
    }
}
