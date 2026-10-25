<?php

namespace App\Support\Employees;

use App\Models\Employee;
use App\Models\EmploymentContract;
use Carbon\CarbonImmutable;

/** Everything the Man Power dashboard shows, computed over active employees. */
class Dashboard
{
    /** Contracts ending within this many months are listed as "akan berakhir". */
    private const PKWT_LIST_MONTHS = 1;

    /** Employees reaching retirement age within this many months are listed. */
    private const PENSIUN_LIST_MONTHS = 12;

    private const BULAN = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    public static function build(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        $employees = Employee::active()
            ->with(['position', 'profile.contracts', 'profile.children'])
            ->orderBy('employee_number')
            ->get();

        // --- completeness ---------------------------------------------------
        $groupDefs = ColumnGroups::build();
        $tally = array_fill_keys(array_column($groupDefs, 'id'), ['filled' => 0, 'total' => 0, 'applicable' => 0]);

        foreach ($employees as $k) {
            foreach (Completeness::score($k->profile) as $id => $s) {
                if (! $s['applicable']) {
                    continue;
                }
                $tally[$id]['applicable']++;
                $tally[$id]['filled'] += $s['filled'];
                $tally[$id]['total'] += $s['total'];
            }
        }

        $groups = array_map(fn (array $g) => [
            'id' => $g['id'],
            'title' => $g['title'],
            // null when no employee currently needs to fill this group.
            'percent' => $tally[$g['id']]['total'] > 0
                ? (int) round($tally[$g['id']]['filled'] / $tally[$g['id']]['total'] * 100)
                : null,
            ...$tally[$g['id']],
        ], $groupDefs);

        // --- origin, contract type and the follow-up lists --------------------
        $origin = ['local' => 0, 'transferred' => 0, 'not_filled' => 0];
        $statusPegawai = ['contract' => 0, 'permanent' => 0];
        $akanBerakhir = [];
        $ends = [];
        $retirements = [];
        $birthdays = [];

        foreach ($employees as $k) {
            $p = $k->profile;
            $base = ['employee_id' => $k->id, 'employee_number' => $k->employee_number, 'name' => $k->name, 'position' => $k->position?->name ?? 'Belum ditempatkan'];

            // Read from the NRP; unknown when its lokasi penerimaan is not mapped to a work location.
            match (EmployeeNumber::originStatus($k->employee_number, $k->work_location_id)) {
                'Lokal' => $origin['local']++,
                'Kiriman' => $origin['transferred']++,
                default => $origin['not_filled']++,
            };

            $permanent = $p?->is_permanent === true;
            $permanent ? $statusPegawai['permanent']++ : $statusPegawai['contract']++;

            // Contracts to follow up: only staff not yet appointed permanent.
            if (! $permanent && ($latest = $p?->latestContract())) {
                $sisaHari = Dates::daysUntil($latest->end_date, $today);
                $item = $base + [
                    'period_label' => EmploymentContract::label($latest->sequence_no),
                    'start_date' => $latest->start_date->toDateString(),
                    'end_date' => $latest->end_date->toDateString(),
                    'days_left' => $sisaHari, // negative once the contract has ended
                    'period_count' => $p->contracts->count(),
                    // Default start of an extension: the day after the latest contract ends.
                    'next_start' => $latest->end_date->addDay()->toDateString(),
                ];

                if ($sisaHari < 0) {
                    $ends[] = $item;
                } elseif (Dates::isWithinMonths($latest->end_date, self::PKWT_LIST_MONTHS, $today)) {
                    $akanBerakhir[] = $item;
                }
            }

            if ($birth = $p?->birth_date) {
                $tanggalPensiun = $birth->addYears(Completeness::PENSIUN_AGE);
                if (Dates::isWithinMonths($tanggalPensiun, self::PENSIUN_LIST_MONTHS, $today)) {
                    $retirements[] = $base + [
                        'birth_date' => $birth->toDateString(),
                        'retirement_date' => $tanggalPensiun->toDateString(),
                        'days_left' => Dates::daysUntil($tanggalPensiun, $today),
                    ];
                }

                if ($birth->month === $today->month) {
                    $birthdays[] = $base + [
                        'birth_date' => $birth->toDateString(),
                        'days' => $birth->day,
                        'new_age' => $today->year - $birth->year,
                        'today' => $birth->day === $today->day,
                    ];
                }
            }
        }

        usort($akanBerakhir, fn ($a, $b) => $a['days_left'] <=> $b['days_left']);
        usort($ends, fn ($a, $b) => $b['days_left'] <=> $a['days_left']); // most recently lapsed first
        usort($retirements, fn ($a, $b) => $a['days_left'] <=> $b['days_left']);
        usort($birthdays, fn ($a, $b) => $a['days'] <=> $b['days']);

        $sumFilled = array_sum(array_column($groups, 'filled'));
        $sumTotal = array_sum(array_column($groups, 'total'));

        return [
            'today' => $today->toDateString(),
            'total_active' => $employees->count(),
            'completeness' => [
                'overall_percent' => $sumTotal > 0 ? (int) round($sumFilled / $sumTotal * 100) : null,
                'groups' => $groups,
            ],
            'origin' => $origin,
            'employment_status' => $statusPegawai,
            'contracts_ending_soon' => $akanBerakhir,
            'contracts_ended' => $ends,
            'retirement_age' => Completeness::PENSIUN_AGE,
            'retirements' => $retirements,
            'birthday_month' => self::BULAN[$today->month],
            'birthdays' => $birthdays,
        ];
    }
}
