<?php

namespace App\Support\TimeClock;

use App\Models\OvertimePolicy;
use App\Models\ShiftCode;
use App\Models\JobLevel;
use App\Models\WorkLocation;
use Illuminate\Support\Facades\DB;

/**
 * The starting point of Kehadiran & Lembur, filled only where nothing was set yet:
 *  - hours of the work shifts: D 07.00–19.00 and N 19.00–07.00 (site, break 1 hour),
 *    and NS 08.00–17.00 (office, break 12.00–13.00);
 *  - working time: Head Office 5 days × 8 hours, sites the ESDM roster scheme with
 *    7 normal hours a day;
 *  - overtime: everyone at Head Office; at the sites only the non-staff SubGroups
 *    (layer 1: operator, mekanik, welder, …).
 */
class TimeClockDefaults
{
    private const JAM = [
        'D' => ['07:00', '19:00', '12:00', '13:00'],
        'N' => ['19:00', '07:00', '00:00', '01:00'],
    ];

    public static function seed(): void
    {
        foreach (self::JAM as $code => [$hired, $termination, $start_date, $end_date]) {
            ShiftCode::where('code', $code)->whereNull('start_time')
                ->update(['start_time' => $hired, 'end_time' => $termination, 'break_start' => $start_date, 'break_end' => $end_date]);
        }
        ShiftCode::firstOrCreate(['code' => 'NS'], [
            'daily_work_schedule' => 'NORMAL', 'description' => 'Normal office 08.00–17.00', 'type' => 'work',
            'start_time' => '08:00', 'end_time' => '17:00', 'break_start' => '12:00', 'break_end' => '13:00',
            'sort_order' => (ShiftCode::max('sort_order') ?? 0) + 1,
        ]);

        WorkLocation::where('type', '!=', WorkLocation::TYPE_HEAD_OFFICE)->where('work_schedule_scheme', 'five_day')->where('daily_normal_hours', 8)
            ->update(['work_schedule_scheme' => 'mining_roster', 'daily_normal_hours' => 7]);

        OvertimePolicy::current();

        $now = now();
        foreach (JobLevel::all() as $g) {
            foreach ([WorkLocation::TYPE_HEAD_OFFICE => true, WorkLocation::TYPE_SITE => $g->layer <= 1] as $tipe => $is_eligible) {
                DB::table('overtime_eligibilities')->insertOrIgnore(['job_level_id' => $g->id, 'location_type' => $tipe, 'is_eligible' => $is_eligible, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
}
