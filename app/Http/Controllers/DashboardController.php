<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use App\Models\JobPosting;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\WorkforcePlan;
use App\Models\RecruitmentStage;
use App\Support\Access\AccessScope;
use App\Support\Time\Onsite;
use App\Support\WorkforcePlanning\WorkforcePlanning;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Eolas dashboard: one look over every module — people, attendance, leave and travel,
 * Comben, recruitment and Man Power Planning — for the account's locations. Lists with
 * names only appear for the menus the account may open.
 */
class DashboardController extends Controller
{
    private const BULAN = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    public function __invoke(Request $request): RedirectResponse|Response
    {
        $user = $request->user();
        if ($user->isEmployee()) {
            return redirect()->route('self_service');
        }
        AccessScope::enable();

        $today = CarbonImmutable::today();
        $locations = AccessScope::locations();
        $location = $request->integer('location') ?: null;
        if ($location && ! $locations->contains('id', $location)) {
            $location = null;
        }
        $ids = $this->employeeIds($location);

        return Inertia::render('Dashboard', [
            'today' => $today->toDateString(),
            'location' => $location,
            'locations' => $locations,
            'people' => $this->people($location, $today),
            'onsite' => rescue(fn () => Onsite::daily($today->subDays(20), $today->addDays(9), $location), [], false),
            'time_clock' => $this->timeClock($ids, $today),
            'submission' => $this->submission($ids),
            'travel' => $this->travel($ids, $today, $user->canView('leave-travel', 'travel-requests')),
            'compensation' => $this->compensation($ids, $today),
            'recruitment' => $this->recruitment($today),
            'workforce_planning' => $this->workforcePlanning($location, $locations),
            'birthdays' => $user->canView('employees') ? $this->birthdays($location, $today) : null,
        ]);
    }

    /** Active employees of the account's locations (or of one of them). */
    private function employeeIds(?int $location): Collection
    {
        return Employee::where('status', Employee::STATUS_ACTIVE)->when($location, fn ($q) => $q->where('work_location_id', $location))->pluck('id');
    }

    private function people(?int $location, CarbonImmutable $today): array
    {
        $active = Employee::where('status', Employee::STATUS_ACTIVE)->when($location, fn ($q) => $q->where('work_location_id', $location))
            ->with(['profile.contracts', 'workLocation'])->get();
        $termination = Employee::where('status', Employee::STATUS_TERMINATED)->when($location, fn ($q) => $q->where('work_location_id', $location))
            ->whereYear('termination_date', $today->year)->count();

        $tally = fn (callable $key) => $active->groupBy($key)->map->count()->sortDesc()->map(fn ($n, $label) => ['label' => (string) $label, 'value' => $n])->values();
        $age = ['< 25' => 0, '25–34' => 0, '35–44' => 0, '45–54' => 0, '55+' => 0];
        $yearsOfService = ['< 1 th' => 0, '1–3 th' => 0, '3–5 th' => 0, '5–10 th' => 0, '10+ th' => 0];
        $pkwtHabis = 0;
        $new = 0;
        foreach ($active as $k) {
            $p = $k->profile;
            if ($birth = $p?->birth_date) {
                $u = $birth->diffInYears($today);
                $age[match (true) { $u < 25 => '< 25', $u < 35 => '25–34', $u < 45 => '35–44', $u < 55 => '45–54', default => '55+' }]++;
            }
            if ($hired = $p?->hire_date) {
                $m = $hired->diffInYears($today);
                $yearsOfService[match (true) { $m < 1 => '< 1 th', $m < 3 => '1–3 th', $m < 5 => '3–5 th', $m < 10 => '5–10 th', default => '10+ th' }]++;
                $new += $hired->gte($today->subDays(90)) ? 1 : 0;
            }
            $akhir = $p?->is_permanent ? null : $p?->contracts?->sortByDesc('end_date')->first()?->end_date;
            $pkwtHabis += $akhir && $akhir->between($today, $today->addDays(30)) ? 1 : 0;
        }
        $pairs = fn (array $a) => collect($a)->map(fn ($n, $label) => ['label' => $label, 'value' => $n])->values();

        return [
            'active' => $active->count(),
            'terminated_this_year' => $termination,
            'new_90_days' => $new,
            'contracts_30_days' => $pkwtHabis,
            'per_location' => $tally(fn ($k) => $k->workLocation?->name ?? 'Belum ditempatkan'),
            'gender' => $tally(fn ($k) => $k->profile?->gender ?: 'Belum diisi'),
            'employment_status' => $tally(fn ($k) => $k->profile?->is_permanent ? 'Tetap' : 'PKWT'),
            'employee_class' => $tally(fn ($k) => $k->profile?->employee_class ?: 'Belum diisi'),
            'age' => $pairs($age),
            'years_of_service' => $pairs($yearsOfService),
        ];
    }

    /** Absensi of the last 30 days by kind (hadir, sakit, izin, mangkir, ...), and the attendance rate. */
    private function timeClock(Collection $ids, CarbonImmutable $today): array
    {
        $rows = DB::table('attendance_records')->join('shift_codes', 'shift_codes.id', '=', 'attendance_records.shift_code_id')
            ->whereIn('attendance_records.employee_id', $ids)->whereBetween('attendance_records.date', [$today->subDays(30)->toDateString(), $today->subDay()->toDateString()])
            ->selectRaw('shift_codes.type as k, count(*) as v')->groupBy('shift_codes.type')->pluck('v', 'k')->map(fn ($n) => (int) $n);
        $workday = $rows->only(['work', 'sick', 'permission', 'absent', 'business_trip'])->sum();

        return [
            'per_type' => $rows->map(fn ($n, $type) => ['label' => ucfirst((string) $type), 'key' => $type, 'value' => $n])->values(),
            'rate' => $workday > 0 ? round((($rows['work'] ?? 0) + ($rows['business_trip'] ?? 0)) / $workday * 100, 1) : null,
        ];
    }

    /** Pengajuan from the portal waiting for HR. */
    private function submission(Collection $ids): array
    {
        $count = fn (string $table) => DB::table($table)->where('status', 'pending')->whereIn('employee_id', $ids)->count();

        return [
            ['key' => 'leave_travel', 'label' => 'Cuti & Dinas', 'value' => $count('leave_travel_requests'), 'href' => '/time/travel-requests', 'menu' => 'travel-requests'],
            ['key' => 'claim', 'label' => 'Deklarasi Pengobatan', 'value' => $count('benefit_claims'), 'href' => '/compensation/claims', 'menu' => 'claim'],
            ['key' => 'update', 'label' => 'Update Data Karyawan', 'value' => $count('employee_change_requests'), 'href' => '/core-hr/change-requests', 'menu' => 'data-change-requests'],
            ['key' => 'long_service_leave', 'label' => 'Pilihan Cuti Besar', 'value' => $count('long_service_leaves'), 'href' => '/time/leave-balances', 'menu' => 'leave'],
        ];
    }

    /** Dinas and cuti running today, and the ones coming in the next two weeks. */
    private function travel(Collection $ids, CarbonImmutable $today, bool $names): array
    {
        $base = fn () => DB::table('leave_travel_requests')->where('leave_travel_requests.status', 'approved')->whereIn('leave_travel_requests.employee_id', $ids);
        $d = $today->toDateString();
        $running = $base()->where('leave_travel_requests.start_date', '<=', $d)->where('leave_travel_requests.end_date', '>=', $d)->selectRaw('leave_travel_requests.type as k, count(*) as v')->groupBy('leave_travel_requests.type')->pluck('v', 'k');
        $coming = $names ? $base()->join('employees', 'employees.id', '=', 'leave_travel_requests.employee_id')
            ->whereBetween('leave_travel_requests.start_date', [$today->addDay()->toDateString(), $today->addDays(14)->toDateString()])
            ->orderBy('leave_travel_requests.start_date')->limit(6)
            ->get(['employees.name', 'leave_travel_requests.type', 'leave_travel_requests.leave_category', 'leave_travel_requests.start_date', 'leave_travel_requests.end_date', 'leave_travel_requests.destination_city']) : null;

        return ['business_trips_today' => (int) ($running['business_trip'] ?? 0), 'leaves_today' => (int) ($running['leave'] ?? 0), 'upcoming' => $coming];
    }

    /** Deklarasi approved in this plafon year: per month and per kind. */
    private function compensation(Collection $ids, CarbonImmutable $today): array
    {
        $items = DB::table('benefit_claim_items')->join('benefit_claims', 'benefit_claims.id', '=', 'benefit_claim_items.benefit_claim_id')
            ->where('benefit_claims.status', 'approved')->where('benefit_claims.benefit_year', $today->year)->whereIn('benefit_claims.employee_id', $ids);
        $perBulan = (clone $items)->selectRaw('benefit_claims.accounting_month as k, sum(benefit_claim_items.amount) as v')->groupBy('benefit_claims.accounting_month')->pluck('v', 'k');
        $perJenis = (clone $items)->selectRaw('benefit_claim_items.type as k, sum(benefit_claim_items.amount) as v')->groupBy('benefit_claim_items.type')->pluck('v', 'k');

        return [
            'total' => (int) $perJenis->map(fn ($v) => (int) $v)->sum(),
            'quantity' => (clone $items)->distinct()->count('benefit_claims.id'),
            'per_month' => collect(range(1, 12))->map(fn ($m) => [
                'label' => self::BULAN[$m], 'value' => (int) ($perBulan[sprintf('%d-%02d', $today->year, $m)] ?? 0),
            ])->values(),
            'per_type' => $perJenis->map(fn ($v, $type) => ['label' => ucfirst((string) $type), 'value' => (int) $v])->sortByDesc('value')->values(),
        ];
    }

    /** Candidates per stage, new applicants and open vacancies. */
    private function recruitment(CarbonImmutable $today): array
    {
        $perStage = Candidate::whereNull('rejected_at')->selectRaw('recruitment_stage_id as k, count(*) as v')->groupBy('recruitment_stage_id')->pluck('v', 'k');

        return [
            'stages' => RecruitmentStage::orderBy('sort_order')->get(['id', 'title', 'color'])->map(fn ($s) => [
                'label' => $s->title, 'color' => $s->color, 'value' => (int) ($perStage[$s->id] ?? 0),
            ])->values(),
            'new_applicants' => Applicant::where('status', 'new')->count(),
            'applicants_30_days' => Applicant::where('submitted_at', '>=', $today->subDays(30))->count(),
            'open_postings' => JobPosting::where('is_open', true)->count(),
        ];
    }

    /** Plan, actual and gap of the latest Man Power Planning per work location. */
    private function workforcePlanning(?int $location, Collection $locations): array
    {
        return $locations->when($location, fn ($c) => $c->where('id', $location))->map(function ($loc) {
            $plan = WorkforcePlan::where('work_location_id', $loc->id)->orderByDesc('year')->orderByDesc('period_number')->orderByDesc('id')->first();
            $s = $plan ? rescue(fn () => WorkforcePlanning::summary($plan), null, false) : null;

            return $s ? ['label' => $loc->name, 'plan' => (int) ($s['plan'] ?? 0), 'actual' => (int) ($s['actual'] ?? 0), 'gap' => (int) ($s['gap'] ?? 0)] : null;
        })->filter()->values()->all();
    }

    /** Birthdays in the coming 30 days. */
    private function birthdays(?int $location, CarbonImmutable $today): array
    {
        return Employee::where('status', Employee::STATUS_ACTIVE)->when($location, fn ($q) => $q->where('work_location_id', $location))
            ->with(['profile:id,employee_id,birth_date', 'position:id,name'])->get()
            ->filter(fn ($k) => $k->profile?->birth_date)
            ->map(function ($k) use ($today) {
                $birth = $k->profile->birth_date;
                $next = $birth->setYear($today->year);
                $next = $next->lt($today) ? $next->addYear() : $next;

                return ['name' => $k->name, 'position' => $k->position?->name, 'date' => $next->toDateString(), 'age' => (int) round($birth->diffInYears($next)), 'days' => (int) $today->diffInDays($next)];
            })
            ->filter(fn ($b) => $b['days'] <= 30)->sortBy('days')->take(6)->values()->all();
    }
}
