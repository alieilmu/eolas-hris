<?php

namespace Database\Seeders;

use App\Http\Controllers\CoreHr\WorkforcePlanController;
use App\Models\Employee;
use App\Models\WorkforcePlanRevision;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\WorkforcePlanning\WorkforcePlanning;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Man Power Planning for the demo: this period's plan of every location around its actual
 * crew (some positions short, a few over), a promotion and a resignation expected, and one
 * employee on long sick leave. Runs inside DemoSeeder, or alone on a demo database:
 *
 *     php artisan db:seed --class=DemoMppSeeder
 */
class DemoWorkforcePlanSeeder extends Seeder
{
    public function run(): void
    {
        $today = CarbonImmutable::today();
        $admin = User::where('role', User::ROLE_ADMIN)->first();
        mt_srand(77);

        // A mechanic in hospital since last month.
        Employee::with('profile')->where('name', 'Samsul Arifin')->first()?->profile?->update([
            'long_sick_start_date' => $today->subDays(40)->toDateString(), 'long_sick_end_date' => $today->addDays(50)->toDateString(), 'long_sick_notes' => 'Pemulihan pasca operasi',
        ]);

        foreach (WorkLocation::orderBy('sort_order')->get() as $location) {
            $plan = WorkforcePlanning::plan($location, WorkforcePlanning::periodOf($today));
            if ($plan->items()->exists()) {
                continue; // already planned
            }
            $rows = collect(WorkforcePlanning::table($plan, $today)['groups'])->flatMap(fn ($g) => $g['rows']);
            $input = [];
            foreach ($rows as $i => $row) {
                $staff = $row['category'] === 'STAFF';
                // Staff positions mostly as filled; crews short by a few.
                $plan_ = $staff ? max(1, $row['actual'] + (mt_rand(1, 5) === 1 ? 1 : 0)) : $row['actual'] + mt_rand(1, 4);
                $fields = ['plan' => $plan_];
                if ($plan_ > $row['actual']) {
                    $fields += ['fulfilment_month' => min(12, $plan->start_date->month + mt_rand(0, 2)), 'source' => $staff ? 'internal' : 'external'];
                }
                if (! $staff && $row['actual'] >= 3 && $i % 3 === 0) {
                    $fields += ['termination' => 1, 'movement_month' => $plan->start_date->month + 1, 'description' => 'Rencana resign (habis kontrak tidak diperpanjang)'];
                }
                if ($staff && $row['actual'] >= 2 && $i % 4 === 1) {
                    $fields += ['promotions' => 1, 'movement_month' => $plan->start_date->month + 2, 'description' => 'Kandidat promosi ke Section Head'];
                }
                $input[$row['position_id']] = $fields;
            }
            $changes = WorkforcePlanController::diff($plan, $input);
            if (! $changes) {
                continue;
            }
            WorkforcePlanController::apply($plan, $changes);
            WorkforcePlanRevision::create([
                'workforce_plan_id' => $plan->id, 'status' => 'approved', 'changes' => $changes, 'is_direct' => true,
                'notes' => 'Plan awal '.$plan->label(), 'created_by' => $admin?->id, 'verified_by' => $admin?->id, 'verified_at' => now(),
            ]);
        }
    }
}
