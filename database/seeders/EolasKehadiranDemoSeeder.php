<?php

namespace Database\Seeders;

use App\Models\Lembur;
use App\Models\MesinAbsen;
use App\Models\ShiftCode;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Kehadiran\Jadwal;
use App\Support\Kehadiran\KehadiranDefaults;
use App\Support\Kehadiran\Proses;
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
class EolasKehadiranDemoSeeder extends Seeder
{
    public function run(): void
    {
        KehadiranDefaults::seed();
        mt_srand(2027);
        $today = CarbonImmutable::today();
        $dari = $today->subMonths(2)->startOfMonth();
        $codes = ShiftCode::all()->keyBy('id');
        $ns = $codes->firstWhere('kode', 'NS');
        $d = $codes->firstWhere('kode', 'D');

        // Head Office works office hours.
        $ho = DB::table('karyawans')->join('work_locations', 'work_locations.id', '=', 'karyawans.work_location_id')
            ->where('work_locations.type', WorkLocation::TYPE_HEAD_OFFICE)->pluck('karyawans.id');
        foreach (['rosters', 'absensis'] as $table) {
            DB::table($table)->whereIn('karyawan_id', $ho)->where('shift_code_id', $d->id)->update(['shift_code_id' => $ns->id]);
        }

        // One machine per location, and one that pushed in before it was registered.
        $prefix = 'EOL'.strtoupper(substr(md5((string) DB::connection()->getDatabaseName()), 0, 4));
        $mesin = [];
        foreach (WorkLocation::orderBy('order')->get() as $i => $loc) {
            $mesin[$loc->id] = MesinAbsen::firstOrCreate(['serial_number' => sprintf('%s%03d', $prefix, $i + 1)], [
                'nama' => "Fingerprint {$loc->name}", 'merek' => 'ZKTeco', 'model' => $i % 2 ? 'SpeedFace-V5L' : 'MB460',
                'work_location_id' => $loc->id, 'status' => 'aktif',
                'last_seen_at' => $i === 2 ? now()->subHours(3) : now()->subMinutes(mt_rand(1, 4)), 'last_ip' => '10.10.'.($i + 1).'.21',
            ]);
        }
        MesinAbsen::firstOrCreate(['serial_number' => $prefix.'NEW9'], ['nama' => "Mesin baru {$prefix}NEW9", 'status' => 'baru', 'last_seen_at' => now()->subMinutes(2), 'last_ip' => '10.10.9.40']);

        // Clock-ins for the worked days.
        $people = DB::table('karyawans')->leftJoin('karyawan_profiles', 'karyawan_profiles.karyawan_id', '=', 'karyawans.id')
            ->leftJoin('sub_groups', 'sub_groups.id', '=', 'karyawans.sub_group_id')
            ->get(['karyawans.id', 'karyawans.nrp', 'karyawans.pin_absen', 'karyawans.work_location_id', 'sub_groups.layer'])->keyBy('id');
        // Each rostered day as it went: the Absensi code where there is one (sick, leave, …),
        // else the roster's; days of a trip have no clock-ins.
        $absensi = DB::table('rosters')
            ->leftJoin('absensis', fn ($j) => $j->on('absensis.karyawan_id', '=', 'rosters.karyawan_id')->on('absensis.tanggal', '=', 'rosters.tanggal'))
            ->whereBetween('rosters.tanggal', [$dari->toDateString(), $today->subDay()->toDateString()])
            ->whereNull('absensis.trip_id')
            ->whereRaw("coalesce(absensis.sumber, '') not in ('mesin')")
            ->get(['rosters.karyawan_id', 'rosters.tanggal', DB::raw('coalesce(absensis.shift_code_id, rosters.shift_code_id) as shift_code_id')]);
        // A day the machines already wrote: the roster says what was planned.
        $absensi = $absensi->concat(DB::table('rosters')
            ->join('absensis', fn ($j) => $j->on('absensis.karyawan_id', '=', 'rosters.karyawan_id')->on('absensis.tanggal', '=', 'rosters.tanggal'))
            ->whereBetween('rosters.tanggal', [$dari->toDateString(), $today->subDay()->toDateString()])
            ->where('absensis.sumber', 'mesin')->get(['rosters.karyawan_id', 'rosters.tanggal', 'rosters.shift_code_id']));
        $now = now();
        $logs = [];
        $push = function (object $p, CarbonImmutable $t, int $status) use (&$logs, $mesin, $now) {
            $logs[] = ['mesin_absen_id' => $mesin[$p->work_location_id]->id ?? null, 'pin' => $p->pin_absen ?: $p->nrp, 'karyawan_id' => $p->id,
                'waktu' => $t->addSeconds(mt_rand(0, 59))->format('Y-m-d H:i:s'), 'status_code' => $status, 'verify' => 'FP', 'sumber' => 'mesin',
                'created_at' => $now, 'updated_at' => $now];
        };
        foreach ($absensi as $a) {
            $p = $people[$a->karyawan_id] ?? null;
            $code = $codes[$a->shift_code_id] ?? null;
            if (! $p || ! $code) {
                continue;
            }
            $tanggal = CarbonImmutable::parse($a->tanggal);
            $site = ! $ho->contains($p->id);
            if ($code->jenis === 'off') {
                // A few non-staff at the sites work a day off.
                if ($site && ($p->layer ?? 1) <= 1 && mt_rand(1, 1000) <= 15) {
                    $push($p, $tanggal->setTime(7, 0)->subMinutes(mt_rand(2, 15)), 0);
                    $push($p, $tanggal->setTime(15, 0)->addMinutes(mt_rand(0, 90)), 1);
                }

                continue;
            }
            if ($code->jenis !== 'hadir' || ! ($j = Jadwal::of($code, $tanggal))) {
                continue;
            }
            $r = mt_rand(1, 1000);
            $ci = $r <= 60 ? $j->masuk->addMinutes(mt_rand(3, 45)) : $j->masuk->subMinutes(mt_rand(3, 25));
            $co = $j->keluar->addMinutes(mt_rand(1, 20));
            if ($site && ($p->layer ?? 1) <= 1 && mt_rand(1, 100) <= 30) {
                $co = $j->keluar->addMinutes(mt_rand(60, 180)); // overtime after the shift
            } elseif (! $site && mt_rand(1, 100) <= 12) {
                $co = $j->keluar->addMinutes(mt_rand(45, 150)); // office overtime
            } elseif (mt_rand(1, 100) <= 4) {
                $co = $j->keluar->subMinutes(mt_rand(10, 90)); // left early
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
            DB::table('absen_logs')->insertOrIgnore($part);
        }

        Proses::run($dari, $today->subDay());

        // Older overtime decided: most approved, a few rejected.
        $admin = User::where('role', User::ROLE_ADMIN)->orderBy('id')->value('id');
        Lembur::where('status', 'menunggu')->where('tanggal', '<', $today->subDays(7)->toDateString())->get()
            ->each(function (Lembur $l) use ($admin) {
                $tolak = mt_rand(1, 100) <= 6;
                $l->update(['status' => $tolak ? 'ditolak' : 'disetujui', 'verified_by' => $admin, 'verified_at' => now(),
                    'catatan_verifikasi' => $tolak ? 'Tanpa surat perintah lembur' : null]);
            });
    }
}
