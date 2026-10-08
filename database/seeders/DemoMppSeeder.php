<?php

namespace Database\Seeders;

use App\Http\Controllers\Manpower\MppController;
use App\Models\Karyawan;
use App\Models\MppRevisi;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Mpp\Mpp;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Man Power Planning for the demo: this period's plan of every location around its actual
 * crew (some positions short, a few over), a promotion and a resignation expected, and one
 * employee on long sick leave. Runs inside DemoSeeder, or alone on a demo database:
 *
 *     php artisan db:seed --class=DemoMppSeeder
 */
class DemoMppSeeder extends Seeder
{
    public function run(): void
    {
        $today = CarbonImmutable::today();
        $admin = User::where('role', User::ROLE_ADMIN)->first();
        mt_srand(77);

        // A mechanic in hospital since last month.
        Karyawan::with('profile')->where('name', 'Samsul Arifin')->first()?->profile?->update([
            'sakit_mulai' => $today->subDays(40)->toDateString(), 'sakit_selesai' => $today->addDays(50)->toDateString(), 'sakit_keterangan' => 'Pemulihan pasca operasi',
        ]);

        foreach (WorkLocation::orderBy('order')->get() as $location) {
            $plan = Mpp::plan($location, Mpp::periodeOf($today));
            if ($plan->items()->exists()) {
                continue; // already planned
            }
            $rows = collect(Mpp::table($plan, $today)['groups'])->flatMap(fn ($g) => $g['rows']);
            $input = [];
            foreach ($rows as $i => $row) {
                $staff = $row['kategori'] === 'STAFF';
                // Staff positions mostly as filled; crews short by a few.
                $plan_ = $staff ? max(1, $row['actual'] + (mt_rand(1, 5) === 1 ? 1 : 0)) : $row['actual'] + mt_rand(1, 4);
                $fields = ['plan' => $plan_];
                if ($plan_ > $row['actual']) {
                    $fields += ['bulan_penuhi' => min(12, $plan->mulai->month + mt_rand(0, 2)), 'sumber' => $staff ? 'internal' : 'eksternal'];
                }
                if (! $staff && $row['actual'] >= 3 && $i % 3 === 0) {
                    $fields += ['keluar' => 1, 'bulan_pindah' => $plan->mulai->month + 1, 'keterangan' => 'Rencana resign (habis kontrak tidak diperpanjang)'];
                }
                if ($staff && $row['actual'] >= 2 && $i % 4 === 1) {
                    $fields += ['promosi' => 1, 'bulan_pindah' => $plan->mulai->month + 2, 'keterangan' => 'Kandidat promosi ke Section Head'];
                }
                $input[$row['jabatan_id']] = $fields;
            }
            $perubahan = MppController::diff($plan, $input);
            if (! $perubahan) {
                continue;
            }
            MppController::apply($plan, $perubahan);
            MppRevisi::create([
                'mpp_plan_id' => $plan->id, 'status' => 'disetujui', 'perubahan' => $perubahan, 'langsung' => true,
                'catatan' => 'Plan awal '.$plan->label(), 'created_by' => $admin?->id, 'verified_by' => $admin?->id, 'verified_at' => now(),
            ]);
        }
    }
}
