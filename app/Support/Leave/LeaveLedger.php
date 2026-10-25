<?php

namespace App\Support\Leave;

use App\Models\LeavePolicy;
use App\Models\LongServiceLeave;
use App\Models\FieldLeaveEntitlement;
use App\Models\LeaveTransaction;
use App\Models\Employee;
use App\Models\LeaveTravelRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Cuti Tahunan & Cuti Besar.
 *
 * Tahunan (as the old LEDGER CUTI sheet): the quota of a year arrives on the
 * anniversary of the hire date, from the second year on; it lives N years (2) and
 * is used oldest first (FIFO). Days taken beyond what is left become hutang, paid
 * from the next quotas. Days count in the year the leave starts.
 *
 * Besar: every N years of service (5) the employee chooses: take the days (25,
 * plus 1x gapok) or cash it in (2x gapok); not chosen by the deadline = cashed in.
 *
 * A cuti in Cuti & Dinas takes days by its kind: lapangan (field leave) takes from
 * the annual quota only what goes beyond the location's allowance (leave days +
 * travel out + travel back); tahunan takes all its days; besar takes from the Cuti
 * Besar taken (the rest from the annual quota); lainnya takes nothing.
 */
class LeaveLedger
{
    public const KATEGORI = [
        'field' => 'Cuti Lapangan',
        'annual' => 'Cuti Tahunan',
        'long_service' => 'Cuti Besar',
        'other' => 'Cuti Lainnya (tidak memotong)',
    ];

    /** Pengambilan, trips (not rejected) and Cuti Besar choices of these employees, grouped by employee. */
    public static function load(iterable $employeeIds): array
    {
        $ids = collect($employeeIds)->values()->all();

        return [
            'transaction' => LeaveTransaction::withoutGlobalScopes()->whereIn('employee_id', $ids)->orderBy('year')->orderBy('start_date')->orderBy('id')->get()->groupBy('employee_id'),
            'leave_travel_requests' => LeaveTravelRequest::withoutGlobalScopes()->whereIn('employee_id', $ids)->where('type', 'leave')->where('status', '!=', 'rejected')->orderBy('start_date')->get()->groupBy('employee_id'),
            'long_service' => LongServiceLeave::withoutGlobalScopes()->whereIn('employee_id', $ids)->get()->groupBy('employee_id'),
        ];
    }

    /** Everything about one employee's leave: annual balance per year and the Cuti Besar entitlements. */
    public static function summary(Employee $k, ?CarbonImmutable $today = null, ?array $data = null, ?int $exceptTrip = null): array
    {
        $today ??= CarbonImmutable::today();
        $data ??= self::load([$k->id]);
        $k->loadMissing('profile');
        $hired = $k->profile?->hire_date;
        $transaction = ($data['transaction'][$k->id] ?? collect())->where('type', 'annual');
        $leave_travel_requests = ($data['leave_travel_requests'][$k->id] ?? collect())->when($exceptTrip, fn ($c) => $c->where('id', '!=', $exceptTrip));

        // Each leave taken, in the year it counts in, oldest first: the ledger says which quota it used.
        $items = [];
        foreach ($transaction as $p) {
            $items[] = ['key' => "p{$p->id}", 'year' => $p->year, 'days' => $p->days, 'sequence' => $p->start_date?->toDateString() ?? "{$p->year}-00"];
        }
        foreach ($leave_travel_requests->where('status', 'approved')->where('annual_leave_deducted', '>', 0) as $t) {
            $items[] = ['key' => "t{$t->id}", 'year' => $t->start_date->year, 'days' => $t->annual_leave_deducted, 'sequence' => $t->start_date->toDateString()];
        }
        $taken = [];
        foreach ($items as $i) {
            $taken[$i['year']] = ($taken[$i['year']] ?? 0) + $i['days'];
        }
        $pending = (int) $leave_travel_requests->where('status', 'pending')->sum('annual_leave_deducted');

        return [
            'annual' => self::annual($hired, $taken, $pending, $today, $items),
            'long_service' => self::longService($hired, $data['long_service'][$k->id] ?? collect(), $leave_travel_requests, $today),
        ];
    }

    /**
     * The annual balance: quota buckets per year, used oldest first.
     *
     * With $items (each leave: key, tahun, hari, urut) it also tells, per leave, which year's
     * quota its days came from — or hutang, and the later quota that paid it.
     *
     * @param  array<int, int>  $diambil  year => days taken
     * @param  list<array{key: string, tahun: int, hari: int, urut: string}>  $items
     */
    public static function annual(?CarbonImmutable $hired, array $taken, int $pending, CarbonImmutable $today, array $items = []): array
    {
        $a = LeavePolicy::current();
        if (! $hired) {
            return ['present' => false, 'reason' => 'Tanggal masuk belum diisi di profil.', 'pending' => $pending, 'taken_total' => array_sum($taken)];
        }
        if (! $items) {
            foreach ($taken as $y => $hari) {
                $items[] = ['key' => "y{$y}", 'year' => $y, 'days' => $hari, 'sequence' => "{$y}"];
            }
        }
        usort($items, fn ($x, $y) => [$x['year'], $x['sequence']] <=> [$y['year'], $y['sequence']]);
        $byYear = [];
        foreach ($items as $i) {
            $byYear[$i['year']][] = $i;
        }
        $first = min($hired->year + 1, ...(array_keys($taken) ?: [$today->year]));
        $buckets = []; // [year granted => days left]
        $debts = []; // [[key, days]] taken beyond the balance, oldest first
        $allocation = []; // key => [{jatah, hari, hutang}]
        $years = [];
        $hangus = 0;
        for ($y = $first; $y <= $today->year; $y++) {
            // Buckets older than the life of a quota are gone.
            foreach ($buckets as $from => $left) {
                if ($from + $a->annual_validity_years - 1 < $y) {
                    unset($buckets[$from]);
                }
            }
            $entitlement = $y >= $hired->year + 1 && self::anniversary($hired, $y)->lte($today) ? $a->annual_entitlement_days : 0;
            // A new quota first pays the hutang, oldest first.
            $left = $entitlement;
            foreach ($debts as $n => [$key, $days]) {
                if ($left <= 0) {
                    break;
                }
                $pay = min($days, $left);
                $allocation[$key][] = ['entitlement' => $y, 'days' => $pay, 'debt' => true];
                $debts[$n][1] -= $pay;
                $left -= $pay;
            }
            $debts = array_values(array_filter($debts, fn ($d) => $d[1] > 0));
            if ($left > 0) {
                $buckets[$y] = $left;
            }
            ksort($buckets);
            foreach ($byYear[$y] ?? [] as $item) {
                $take = $item['days'];
                foreach ($buckets as $from => $rest) {
                    if ($take <= 0) {
                        break;
                    }
                    $use = min($rest, $take);
                    if ($use > 0) {
                        $buckets[$from] -= $use;
                        $take -= $use;
                        $allocation[$item['key']][] = ['entitlement' => $from, 'days' => $use, 'debt' => false];
                    }
                }
                if ($take > 0) {
                    $debts[] = [$item['key'], $take];
                }
            }
            $debt = array_sum(array_column($debts, 1));
            $balance = array_sum($buckets);
            // What expires at the end of this year unused.
            $hangus = array_sum(array_filter($buckets, fn ($left, $from) => $from + $a->annual_validity_years - 1 === $y, ARRAY_FILTER_USE_BOTH));
            $years[] = ['year' => $y, 'entitlement' => $entitlement, 'taken' => $taken[$y] ?? 0, 'remaining' => $balance, 'debt' => $debt];
        }
        // Hutang not paid yet.
        foreach ($debts as [$key, $days]) {
            $allocation[$key][] = ['entitlement' => null, 'days' => $days, 'debt' => true];
        }
        // The quotas still alive (oldest used first) and what is left of each.
        $period = [];
        foreach ($years as $row) {
            $until = $row['year'] + $a->annual_validity_years - 1;
            if ($row['entitlement'] > 0 && $until >= $today->year) {
                $remaining = $buckets[$row['year']] ?? 0;
                $period[] = [
                    'year' => $row['year'],
                    'start_date' => self::anniversary($hired, $row['year'])->toDateString(),
                    'effective_until' => "{$until}-12-31",
                    'entitlement' => $row['entitlement'],
                    'used' => $row['entitlement'] - $remaining,
                    'remaining' => $remaining,
                ];
            }
        }
        $now = end($years) ?: ['entitlement' => 0, 'taken' => 0, 'remaining' => 0, 'debt' => 0];
        $next = self::anniversary($hired, $today->year);
        if ($next->lte($today) || $today->year < $hired->year + 1) {
            $next = self::anniversary($hired, max($today->year + 1, $hired->year + 1));
        }

        return [
            'present' => true,
            'hired' => $hired->toDateString(),
            'balance' => $now['remaining'],
            'debt' => $now['debt'],
            'pending' => $pending,
            'available' => $now['remaining'] - $now['debt'] - $pending,
            'entitlement_this_year' => $now['entitlement'],
            'taken_this_year' => $now['taken'],
            'taken_total' => array_sum($taken),
            'expires_end_of_year' => $hangus,
            'next_entitlement_date' => $next->toDateString(),
            'next_entitlement' => $a->annual_entitlement_days,
            'period' => $period,
            'year' => $years,
            'allocation' => $allocation,
        ];
    }

    /** The Cuti Besar entitlements reached so far (and the next one). */
    public static function longService(?CarbonImmutable $hired, Collection $choices, Collection $leave_travel_requests, CarbonImmutable $today): array
    {
        $a = LeavePolicy::current();
        if (! $hired) {
            return ['period' => [], 'next' => null];
        }
        $period = [];
        for ($n = 1; ($eligibility = $hired->addYears($n * $a->long_service_interval_years))->lte($today); $n++) {
            $choice = $choices->firstWhere('sequence_no', $n);
            $limit = $eligibility->addMonths($a->long_service_decision_months);
            $used = (int) $leave_travel_requests->where('status', 'approved')->where('long_service_period', $n)->sum('long_service_leave_deducted');
            $pending = (int) $leave_travel_requests->where('status', 'pending')->where('long_service_period', $n)->sum('long_service_leave_deducted');
            $status = match (true) {
                $choice?->status === 'pending' => 'pending',
                $choice?->decision === 'taken' => 'taken',
                $choice?->decision === 'cashed_out' => 'cashed_out',
                $today->gt($limit) => 'auto_cashed_out',
                default => 'not_chosen',
            };
            $period[] = [
                'id' => $choice?->id,
                'sequence_no' => $n,
                'years_of_service' => $n * $a->long_service_interval_years,
                'entitlement_date' => $eligibility->toDateString(),
                'limit' => $limit->toDateString(),
                'status' => $status,
                'decision' => $choice?->decision,
                'decision_status' => $choice?->status,
                'days' => $status === 'taken' ? $a->long_service_days : 0,
                'used' => $used,
                'pending' => $pending,
                'remaining' => $status === 'taken' ? $a->long_service_days - $used - $pending : 0,
                'base_pay' => match ($status) {
                    'taken' => $a->long_service_taken_salary_multiplier,
                    'cashed_out', 'auto_cashed_out' => $a->long_service_cashout_salary_multiplier,
                    default => null,
                },
            ];
        }

        return ['period' => $period, 'next' => $hired->addYears($n * $a->long_service_interval_years)->toDateString(), 'next_years_of_service' => $n * $a->long_service_interval_years];
    }

    /** The field-leave allowance of the employee's sub location, else of their work location. */
    public static function field(Employee $k): ?FieldLeaveEntitlement
    {
        if (! $k->work_location_id) {
            return null;
        }
        $rows = FieldLeaveEntitlement::where('work_location_id', $k->work_location_id)->get();

        return ($k->sub_location_id ? $rows->firstWhere('sub_location_id', $k->sub_location_id) : null) ?? $rows->whereNull('sub_location_id')->first();
    }

    /**
     * What a cuti of $hari days takes, by its kind.
     *
     * @return array{potong_tahunan: int, potong_besar: int, cuti_besar_periode: int|null}
     */
    public static function potongan(Employee $k, string $category, int $hari, ?int $exceptTrip = null): array
    {
        $out = ['annual_leave_deducted' => 0, 'long_service_leave_deducted' => 0, 'long_service_period' => null];
        switch ($category) {
            case 'annual':
                $out['annual_leave_deducted'] = $hari;
                break;
            case 'field':
                $allowance = self::field($k)
                    ?? throw ValidationException::withMessages(['leave_category' => 'Cuti lapangan belum diatur untuk lokasi karyawan ini (Pengaturan Kompensasi → Cuti Lapangan).']);
                $out['annual_leave_deducted'] = max(0, $hari - $allowance->total());
                break;
            case 'long_service':
                $open = collect(self::summary($k, exceptTrip: $exceptTrip)['long_service']['period'])->where('status', 'taken')->where('remaining', '>', 0)->first()
                    ?? throw ValidationException::withMessages(['leave_category' => 'Belum ada hak Cuti Besar yang dipilih "Diambil" dan masih bersisa.']);
                $out['long_service_leave_deducted'] = min($hari, $open['remaining']);
                $out['annual_leave_deducted'] = $hari - $out['long_service_leave_deducted'];
                $out['long_service_period'] = $open['sequence_no'];
                break;
        }

        return $out;
    }

    /** For the cuti form: the balance, the field-leave allowance and the Cuti Besar to take. */
    public static function info(Employee $k, ?int $exceptTrip = null): array
    {
        $s = self::summary($k, exceptTrip: $exceptTrip);
        $l = self::field($k);

        return [
            'annual' => array_diff_key($s['annual'], ['year' => 1, 'allocation' => 1]),
            'field' => $l ? ['leave' => $l->leave_days, 'departure' => $l->outbound_travel_days, 'back' => $l->return_travel_days, 'total' => $l->total()] : null,
            'field_leave_allowance' => FieldLeaveAllowanceRate::for($k),
            'long_service' => collect($s['long_service']['period'])->whereIn('status', ['taken', 'not_chosen', 'pending'])->values(),
            'category' => self::KATEGORI,
        ];
    }

    /** The anniversary of the hire date in a year (29 Feb → 28 Feb). */
    public static function anniversary(CarbonImmutable $hired, int $year): CarbonImmutable
    {
        return CarbonImmutable::create($year, $hired->month, min($hired->day, CarbonImmutable::create($year, $hired->month, 1)->daysInMonth));
    }
}
