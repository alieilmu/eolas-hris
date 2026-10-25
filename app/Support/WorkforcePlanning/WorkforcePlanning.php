<?php

namespace App\Support\WorkforcePlanning;

use App\Models\Applicant;
use App\Models\JobPosting;
use App\Models\Candidate;
use App\Models\Grade;
use App\Models\Position;
use App\Models\Employee;
use App\Models\WorkforcePlanSetting;
use App\Models\WorkforcePlan;
use App\Models\WorkforcePlanItem;
use App\Models\MedicalBenefitLimit;
use App\Models\SalaryScale;
use App\Models\SalaryScalePeriod;
use App\Models\SalaryScaleAmount;
use App\Models\JobLevel;
use App\Models\WorkLocation;
use App\Support\Employees\Completeness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Man Power Planning, as the MPP sheet in Excel: per position of a work location the Plan,
 * the Actual (active employees in it), who is expected to leave it this period (promotion,
 * rotation, demotion, leaving, retirement, long sickness), and the Gap:
 *
 *     Gap = Actual − (Plan + Promosi + Rotasi + Demosi + Keluar + Pensiun + Sakit)
 *
 * A minus gap is people still needed; its cost is estimated from the Skala Upah.
 */
class WorkforcePlanning
{
    /** Excel's categories: the SubGroup of the position (every staff layer is STAFF). */
    public const KATEGORI = ['STAFF' => 'Staff', 'NONOM' => 'Nonom', 'OPERATOR' => 'Operator', 'MEKANIK' => 'Mekanik & Welder'];

    // --- periods -----------------------------------------------------------------------------

    /** @return array{jenis: string, tahun: int, ke: int, mulai: CarbonImmutable, selesai: CarbonImmutable} */
    public static function periodOf(CarbonImmutable $date, ?string $type = null): array
    {
        $type ??= WorkforcePlanSetting::current()->period;
        $months = $type === 'semiannual' ? 6 : 3;
        $period_number = intdiv($date->month - 1, $months) + 1;
        $start_date = CarbonImmutable::create($date->year, ($period_number - 1) * $months + 1, 1);

        return ['type' => $type, 'year' => $date->year, 'period_number' => $period_number, 'start_date' => $start_date, 'end_date' => $start_date->addMonths($months)->subDay()];
    }

    /** "2027-Q2" / "2027-S1" → the period, or null when it does not read as one. */
    public static function parseCode(?string $code): ?array
    {
        if (! $code || ! preg_match('/^(\d{4})-([QS])([1-4])$/', $code, $m) || ($m[2] === 'S' && $m[3] > 2)) {
            return null;
        }
        $type = $m[2] === 'S' ? 'semiannual' : 'quarterly';

        return self::periodOf(CarbonImmutable::create((int) $m[1], ((int) $m[3] - 1) * ($type === 'semiannual' ? 6 : 3) + 1, 1), $type);
    }

    public static function code(array $p): string
    {
        return "{$p['year']}-".($p['type'] === 'semiannual' ? 'S' : 'Q').$p['period_number'];
    }

    public static function label(array $p): string
    {
        $month = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

        return ($p['type'] === 'semiannual' ? 'Semester ' : 'Kuartal ').$p['period_number'].' '.$p['year'].' ('.$month[$p['start_date']->month - 1].'–'.$month[$p['end_date']->month - 1].')';
    }

    /** The periods to choose from: the past ones that have a plan, this one and the next. */
    public static function periodOptions(?WorkLocation $location, CarbonImmutable $today): array
    {
        $now = self::periodOf($today);
        $next = self::periodOf($now['end_date']->addDay());
        $kodes = WorkforcePlan::when($location, fn ($q) => $q->where('work_location_id', $location->id))
            ->where('start_date', '<', $now['start_date']->toDateString())->orderByDesc('start_date')->get()
            ->map(fn (WorkforcePlan $p) => $p->code())->unique()->take(8)->all();

        return collect([self::code($next), self::code($now), ...$kodes])->unique()
            ->map(fn (string $k) => ['value' => $k, 'label' => self::label(self::parseCode($k)), 'now' => $k === self::code($now)])->values()->all();
    }

    /**
     * The location's plan for the period; a period not planned yet starts as a copy of the
     * latest plan before it (so the plan carries on from quarter to quarter).
     */
    public static function plan(WorkLocation $location, array $p): WorkforcePlan
    {
        $existing = WorkforcePlan::where(['work_location_id' => $location->id, 'type' => $p['type'], 'year' => $p['year'], 'period_number' => $p['period_number']])->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($location, $p) {
            $from = WorkforcePlan::where('work_location_id', $location->id)->where('start_date', '<', $p['start_date']->toDateString())->orderByDesc('start_date')->first();
            $plan = WorkforcePlan::create([
                'work_location_id' => $location->id, 'type' => $p['type'], 'year' => $p['year'], 'period_number' => $p['period_number'],
                'start_date' => $p['start_date']->toDateString(), 'end_date' => $p['end_date']->toDateString(), 'source_plan_id' => $from?->id,
            ]);
            if ($from) {
                // What was planned carries on; movements belong to their own period.
                $plan->items()->createMany($from->items->map(fn (WorkforcePlanItem $i) => [
                    'position_id' => $i->position_id, 'plan' => $i->plan, 'grade_id' => $i->grade_id, 'source' => $i->source, 'description' => $i->description,
                ])->all());
            }

            return $plan;
        });
    }

    // --- the table ---------------------------------------------------------------------------

    /**
     * Every position of the plan's location with its numbers, grouped per departemen, plus
     * the totals and the per-category summary.
     */
    public static function table(WorkforcePlan $plan, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $location = $plan->workLocation;
        $positions = Position::with(['jobLevel', 'department', 'section.department', 'division', 'subLocation'])
            ->where('work_location_id', $location->id)
            ->get();
        $items = $plan->items()->get()->keyBy('position_id');
        $holders = Employee::withoutGlobalScope('access')->with('profile:id,employee_id,birth_date,long_sick_start_date,long_sick_end_date,long_sick_notes')
            ->where('status', Employee::STATUS_ACTIVE)->whereIn('position_id', $positions->pluck('id'))
            ->get(['id', 'employee_number', 'name', 'position_id'])->groupBy('position_id');
        $careers = self::careers($positions->pluck('id'));
        $golonganDefault = self::gradeDefaults();
        $grades = Grade::pluck('code', 'id');

        $rows = $positions->map(function (Position $j) use ($items, $holders, $plan, $golonganDefault, $grades, $careers, $location) {
            $item = $items[$j->id] ?? null;
            $people = $holders[$j->id] ?? collect();
            [$pensiunAuto, $pensiunList] = self::retirements($people, $plan);
            [$sakitAuto, $sakitList] = self::sick($people, $plan);
            $retirements = $item?->retirements ?? $pensiunAuto;
            $sick = $item?->sick ?? $sakitAuto;
            $actual = $people->count();
            $planCount = $item?->plan ?? 0;
            $termination = ($item?->promotions ?? 0) + ($item?->rotations ?? 0) + ($item?->demotions ?? 0) + ($item?->termination ?? 0) + $retirements + $sick;
            $gap = $actual - ($planCount + $termination);
            $gradeId = $item?->grade_id ?? ($golonganDefault[$j->job_level_id] ?? null);
            $wage = $gradeId ? self::wage($gradeId, $location->id, $plan->year) : null;
            $department = $j->department ?? $j->section?->department;

            return [
                'position_id' => $j->id,
                'position' => $j->name,
                'category' => self::category($j),
                'job_level' => $j->jobLevel?->name,
                'layer' => $j->jobLevel?->layer ?? 0,
                'sub_location' => $j->subLocation?->name,
                'department_id' => $department?->id,
                'department' => $department?->name ?? ($j->division?->name ? "DIVISI {$j->division->name}" : 'MANAJEMEN LOKASI'),
                'department_order' => $department ? [1, $department->sort_order ?? 0, $department->name] : [0, 0, ''],
                'sort_order' => $j->sort_order ?? 0,
                'plan' => $planCount,
                'actual' => $actual,
                'promotions' => $item?->promotions ?? 0,
                'rotations' => $item?->rotations ?? 0,
                'demotions' => $item?->demotions ?? 0,
                'termination' => $item?->termination ?? 0,
                'retirements' => $retirements,
                'retirements_auto' => $pensiunAuto,
                'retirements_manual' => $item?->retirements !== null && $item !== null,
                'retirements_list' => $pensiunList,
                'sick' => $sick,
                'sick_auto' => $sakitAuto,
                'sick_manual' => $item?->sick !== null && $item !== null,
                'sick_list' => $sakitList,
                'gap' => $gap,
                'movement_month' => $item?->movement_month ?? ($pensiunList[0]['month'] ?? null),
                'fulfilment_month' => $item?->fulfilment_month,
                'source' => $item?->source,
                'grade_id' => $gradeId,
                'grade' => $gradeId ? ($grades[$gradeId] ?? null) : null,
                'grade_manual' => (bool) $item?->grade_id,
                'wage' => $wage,
                'cost' => $gap < 0 && $wage ? -$gap * $wage : 0,
                'description' => $item?->description,
                'employee' => $people->map(fn (Employee $k) => ['employee_number' => $k->employee_number, 'name' => $k->name])->values(),
                'careers' => $careers[$j->id] ?? null,
            ];
        })->sort(fn ($a, $b) => [$a['department_order'], -$a['layer'], $a['category'] === 'STAFF' ? 0 : 1, $a['sort_order'], $a['position']]
            <=> [$b['department_order'], -$b['layer'], $b['category'] === 'STAFF' ? 0 : 1, $b['sort_order'], $b['position']])->values();

        $sum = fn (Collection $c) => [
            'plan' => $c->sum('plan'), 'actual' => $c->sum('actual'),
            'promotions' => $c->sum('promotions'), 'rotations' => $c->sum('rotations'), 'demotions' => $c->sum('demotions'), 'termination' => $c->sum('termination'),
            'retirements' => $c->sum('retirements'), 'sick' => $c->sum('sick'), 'gap' => $c->sum('gap'),
            'short' => $c->sum(fn ($r) => max(0, -$r['gap'])), 'over' => $c->sum(fn ($r) => max(0, $r['gap'])), 'cost' => $c->sum('cost'),
        ];
        $groups = $rows->groupBy('department')->map(fn (Collection $c, string $name) => ['department' => $name, 'rows' => $c->values(), 'total' => $sum($c)])->values();
        $category = collect(self::KATEGORI)->map(fn (string $label, string $k) => ['category' => $k, 'label' => $label, ...$sum($rows->where('category', $k))])->values();
        $not_yet = Employee::withoutGlobalScope('access')->where('status', Employee::STATUS_ACTIVE)->where('work_location_id', $location->id)->whereNull('position_id')->count();

        return ['groups' => $groups, 'total' => $sum($rows), 'category' => $category, 'unplaced' => $not_yet, 'period_months' => self::periodMonths($plan)];
    }

    /** Totals of one location for the All Site overview (without the per-position detail). */
    public static function summary(WorkforcePlan $plan): array
    {
        $t = self::table($plan);

        return $t['total'] + ['position' => $t['groups']->sum(fn ($g) => count($g['rows']))];
    }

    public static function category(Position $j): string
    {
        $nama = strtoupper((string) $j->jobLevel?->name);

        return match (true) {
            ($j->jobLevel?->layer ?? 1) >= 2 => 'STAFF',
            str_contains($nama, 'OPERATOR') => 'OPERATOR',
            str_contains($nama, 'MEKANIK') || str_contains($nama, 'WELDER') => 'MEKANIK',
            default => 'NONOM',
        };
    }

    /** Holders who reach the retirement age inside the period. */
    private static function retirements(Collection $people, WorkforcePlan $plan): array
    {
        $list = $people->map(function (Employee $k) use ($plan) {
            $birth = $k->profile?->birth_date;
            $tanggal = $birth?->addYears(Completeness::PENSIUN_AGE);

            return $tanggal && $tanggal->betweenIncluded($plan->start_date, $plan->end_date)
                ? ['employee_number' => $k->employee_number, 'name' => $k->name, 'date' => $tanggal->toDateString(), 'month' => $tanggal->month] : null;
        })->filter()->sortBy('date')->values()->all();

        return [count($list), $list];
    }

    /** Holders on long sick leave at any time in the period. */
    private static function sick(Collection $people, WorkforcePlan $plan): array
    {
        $list = $people->map(function (Employee $k) use ($plan) {
            $start_date = $k->profile?->long_sick_start_date;
            $end_date = $k->profile?->long_sick_end_date;

            return $start_date && $start_date->lte($plan->end_date) && (! $end_date || $end_date->gte($plan->start_date))
                ? ['employee_number' => $k->employee_number, 'name' => $k->name, 'start_date' => $start_date->toDateString(), 'end_date' => $end_date?->toDateString(), 'description' => $k->profile->long_sick_notes] : null;
        })->filter()->values()->all();

        return [count($list), $list];
    }

    /** The months of the period, for "Waktu pindah / pemenuhan". */
    private static function periodMonths(WorkforcePlan $plan): array
    {
        $month = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $out = [];
        for ($m = $plan->start_date->month; $m <= $plan->end_date->month; $m++) {
            $out[] = ['value' => $m, 'label' => $month[$m - 1]];
        }

        return $out;
    }

    // --- costs -------------------------------------------------------------------------------

    /**
     * SubGroup => golongan for the cost: as set in Master Data, else the lowest golongan its
     * Plafon Pengobatan row expects.
     *
     * @return array<int, int>
     */
    public static function gradeDefaults(): array
    {
        static $cache = null;
        if ($cache !== null && ! app()->runningUnitTests()) {
            return $cache;
        }
        $grades = Grade::orderBy('sort_order')->orderBy('id')->get(['id', 'grade_group']);
        $order = $grades->pluck('id')->flip();
        $grupOf = $grades->pluck('grade_group', 'id')->map(fn ($g) => strtoupper((string) $g));
        $jobLevels = JobLevel::pluck('name', 'id')->map(fn ($n) => strtoupper((string) $n));
        $map = [];
        foreach (MedicalBenefitLimit::orderBy('sort_order')->get() as $row) {
            $ids = collect($row->grade_ids ?? [])->sortBy(fn ($id) => $order[$id] ?? PHP_INT_MAX)->values();
            foreach ($row->job_level_ids ?? [] as $sg) {
                // A row shared by several SubGroups (Nonom, Operator, Mekanik, Welder): the lowest golongan of its own group.
                $own = $ids->first(fn ($id) => ($nama = $jobLevels[$sg] ?? '') !== '' && str_contains($nama, $grupOf[$id] ?? '#'));
                $map[(int) $sg] ??= ($own ?? $ids->first()) ? (int) ($own ?? $ids->first()) : null;
            }
        }
        foreach (WorkforcePlanSetting::current()->grade_by_job_level ?? [] as $sg => $grade) {
            if ($grade) {
                $map[(int) $sg] = (int) $grade;
            }
        }

        return $cache = array_filter($map);
    }

    /** The monthly wage of the golongan's first step at the location in that year (or the nearest earlier year). */
    public static function wage(int $gradeId, int $workLocationId, int $year): ?int
    {
        static $cache = [];
        $key = "{$gradeId}:{$workLocationId}:{$year}";
        if (array_key_exists($key, $cache) && ! app()->runningUnitTests()) {
            return $cache[$key];
        }
        $step = SalaryScale::where('grade_id', $gradeId)->orderBy('sort_order')->orderBy('id')->value('id');
        $period_column = SalaryScalePeriod::where('year', '<=', $year)
            ->where(fn ($q) => $q->where('work_location_id', $workLocationId)->orWhereNull('work_location_id'))
            ->orderByDesc('year')->orderByRaw('work_location_id is null')->value('id');

        return $cache[$key] = $step && $period_column ? SalaryScaleAmount::where(['salary_scale_id' => $step, 'salary_scale_period_id' => $period_column])->value('amount') : null;
    }

    // --- recruitment -------------------------------------------------------------------------

    /**
     * Open vacancies made for these positions, with the candidates still in process.
     *
     * @return array<int, array{form_id: int, title: string, is_open: bool, pelamar: int, kandidat: int}>
     */
    public static function careers(Collection $jabatanIds): array
    {
        $forms = JobPosting::whereIn('position_id', $jabatanIds)->orderByDesc('id')->get(['id', 'title', 'is_open', 'position_id']);
        $out = [];
        foreach ($forms->groupBy('position_id') as $positionId => $list) {
            $ids = $list->pluck('id');
            $out[$positionId] = [
                'form_id' => $list->first()->id,
                'title' => $list->first()->title,
                'is_open' => $list->contains('is_open', true),
                'applicants' => Applicant::whereIn('job_posting_id', $ids)->where('status', 'new')->count(),
                'candidate' => Candidate::whereNull('rejected_at')->whereDoesntHave('employee')
                    ->whereHas('applicant', fn ($q) => $q->whereIn('job_posting_id', $ids))->count(),
            ];
        }

        return $out;
    }
}
