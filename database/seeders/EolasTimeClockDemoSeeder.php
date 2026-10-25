<?php

namespace Database\Seeders;

use App\Models\OvertimeRecord;
use App\Models\AttendanceDevice;
use App\Models\ShiftCode;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\TimeClock\ShiftWindow;
use App\Support\TimeClock\TimeClockDefaults;
use App\Support\TimeClock\AttendanceProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo Kehadiran: Head Office on the office shift (NS), an attendance machine per location
 * (and one new machine waiting to be accepted), and clock-ins for the worked days of the
 * demo Absensi — mostly on time, some late or early, some forgetting CI/CO, overtime after
 * the shift at the sites and a few days off worked. Then Kehadiran is processed into
 * Absensi and Lembur, and the older overtime is approved.
 */
class EolasTimeClockDemoSeeder extends Seeder
{
    public function run(): void
    {
        TimeClockDefaults::seed();
        mt_srand(2027);
        $today = CarbonImmutable::today();
        $from = $today->subMonths(2)->startOfMonth();
        $codes = ShiftCode::all()->keyBy('id');
        $ns = $codes->firstWhere('code', 'NS');
        $d = $codes->firstWhere('code', 'D');

        // Head Office works office hours.
        $ho = DB::table('employees')->join('work_locations', 'work_locations.id', '=', 'employees.work_location_id')
            ->where('work_locations.type', WorkLocation::TYPE_HEAD_OFFICE)->pluck('employees.id');
        foreach (['shift_schedules', 'attendance_records'] as $table) {
            DB::table($table)->whereIn('employee_id', $ho)->where('shift_code_id', $d->id)->update(['shift_code_id' => $ns->id]);
        }

        // One machine per location, and one that pushed in before it was registered.
        $prefix = 'EOL'.strtoupper(substr(md5((string) DB::connection()->getDatabaseName()), 0, 4));
        $device = [];
        foreach (WorkLocation::orderBy('sort_order')->get() as $i => $loc) {
            $device[$loc->id] = AttendanceDevice::firstOrCreate(['serial_number' => sprintf('%s%03d', $prefix, $i + 1)], [
                'name' => "Fingerprint {$loc->name}", 'brand' => 'ZKTeco', 'model' => $i % 2 ? 'SpeedFace-V5L' : 'MB460',
                'work_location_id' => $loc->id, 'status' => 'active',
                'last_seen_at' => $i === 2 ? now()->subHours(3) : now()->subMinutes(mt_rand(1, 4)), 'last_ip' => '10.10.'.($i + 1).'.21',
            ]);
        }
        AttendanceDevice::firstOrCreate(['serial_number' => $prefix.'NEW9'], ['name' => "Mesin baru {$prefix}NEW9", 'status' => 'new', 'last_seen_at' => now()->subMinutes(2), 'last_ip' => '10.10.9.40']);

        // Clock-ins for the worked days.
        $people = DB::table('employees')->leftJoin('employee_profiles', 'employee_profiles.employee_id', '=', 'employees.id')
            ->leftJoin('job_levels', 'job_levels.id', '=', 'employees.job_level_id')
            ->get(['employees.id', 'employees.employee_number', 'employees.attendance_pin', 'employees.work_location_id', 'job_levels.layer'])->keyBy('id');
        // Each rostered day as it went: the Absensi code where there is one (sick, leave, …),
        // else the roster's; days of a trip have no clock-ins.
        $attendance = DB::table('shift_schedules')
            ->leftJoin('attendance_records', fn ($j) => $j->on('attendance_records.employee_id', '=', 'shift_schedules.employee_id')->on('attendance_records.date', '=', 'shift_schedules.date'))
            ->whereBetween('shift_schedules.date', [$from->toDateString(), $today->subDay()->toDateString()])
            ->whereNull('attendance_records.leave_travel_request_id')
            ->whereRaw("coalesce(attendance_records.source, '') not in ('device')")
            ->get(['shift_schedules.employee_id', 'shift_schedules.date', DB::raw('coalesce(attendance_records.shift_code_id, shift_schedules.shift_code_id) as shift_code_id')]);
        // A day the machines already wrote: the roster says what was planned.
        $attendance = $attendance->concat(DB::table('shift_schedules')
            ->join('attendance_records', fn ($j) => $j->on('attendance_records.employee_id', '=', 'shift_schedules.employee_id')->on('attendance_records.date', '=', 'shift_schedules.date'))
            ->whereBetween('shift_schedules.date', [$from->toDateString(), $today->subDay()->toDateString()])
            ->where('attendance_records.source', 'device')->get(['shift_schedules.employee_id', 'shift_schedules.date', 'shift_schedules.shift_code_id']));
        $now = now();
        $logs = [];
        $push = function (object $p, CarbonImmutable $t, int $status) use (&$logs, $device, $now) {
            $logs[] = ['attendance_device_id' => $device[$p->work_location_id]->id ?? null, 'pin' => $p->attendance_pin ?: $p->employee_number, 'employee_id' => $p->id,
                'punched_at' => $t->addSeconds(mt_rand(0, 59))->format('Y-m-d H:i:s'), 'status_code' => $status, 'verify' => 'FP', 'source' => 'device',
                'created_at' => $now, 'updated_at' => $now];
        };
        foreach ($attendance as $a) {
            $p = $people[$a->employee_id] ?? null;
            $code = $codes[$a->shift_code_id] ?? null;
            if (! $p || ! $code) {
                continue;
            }
            $date = CarbonImmutable::parse($a->date);
            $site = ! $ho->contains($p->id);
            if ($code->type === 'off') {
                // A few non-staff at the sites work a day off.
                if ($site && ($p->layer ?? 1) <= 1 && mt_rand(1, 1000) <= 15) {
                    $push($p, $date->setTime(7, 0)->subMinutes(mt_rand(2, 15)), 0);
                    $push($p, $date->setTime(15, 0)->addMinutes(mt_rand(0, 90)), 1);
                }

                continue;
            }
            if ($code->type !== 'work' || ! ($j = ShiftWindow::of($code, $date))) {
                continue;
            }
            $r = mt_rand(1, 1000);
            $ci = $r <= 60 ? $j->start->addMinutes(mt_rand(3, 45)) : $j->start->subMinutes(mt_rand(3, 25));
            $co = $j->end->addMinutes(mt_rand(1, 20));
            if ($site && ($p->layer ?? 1) <= 1 && mt_rand(1, 100) <= 30) {
                $co = $j->end->addMinutes(mt_rand(60, 180)); // overtime after the shift
            } elseif (! $site && mt_rand(1, 100) <= 12) {
                $co = $j->end->addMinutes(mt_rand(45, 150)); // office overtime
            } elseif (mt_rand(1, 100) <= 4) {
                $co = $j->end->subMinutes(mt_rand(10, 90)); // left early
            }
            $r = mt_rand(1, 1000);
            if ($r > 10) {
                $push($p, $ci, 0); // 1%: forgot CI
            }
            if ($r <= 10 || $r > 30) {
                $push($p, $co, 1); // 2%: forgot CO
            }
        }
        foreach (array_chunk($logs, 1000) as $part) {
            DB::table('attendance_logs')->insertOrIgnore($part);
        }

        AttendanceProcessor::run($from, $today->subDay());

        // Older overtime decided: most approved, a few rejected.
        $admin = User::where('role', User::ROLE_ADMIN)->orderBy('id')->value('id');
        OvertimeRecord::where('status', 'pending')->where('date', '<', $today->subDays(7)->toDateString())->get()
            ->each(function (OvertimeRecord $l) use ($admin) {
                $reject = mt_rand(1, 100) <= 6;
                $l->update(['status' => $reject ? 'rejected' : 'approved', 'verified_by' => $admin, 'verified_at' => now(),
                    'verification_notes' => $reject ? 'Tanpa surat perintah lembur' : null]);
            });
    }
}
