<?php

namespace Database\Seeders;

use App\Models\PointOfHire;
use App\Models\FieldLeaveAllowance;
use App\Models\LeaveTravelRequest;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Leave\FieldLeaveAllowanceRate;
use Illuminate\Database\Seeder;

/**
 * Lumpsum Cuti Lapangan for the demo (hired far from the site = more), the lumpsum of the
 * cuti lapangan already there, and some tickets already bought. Runs inside DemoSeeder, or:
 *
 *     php artisan db:seed --class=DemoLumpsumSeeder
 */
class DemoFieldLeaveAllowanceSeeder extends Seeder
{
    public function run(): void
    {
        foreach (WorkLocation::all() as $site) {
            foreach (PointOfHire::all() as $point_of_hire) {
                $local = $point_of_hire->work_location_id === $site->id;
                foreach (['Staff' => $local ? 750000 : 3500000, 'Non Staff' => $local ? 500000 : 2500000] as $employee_class => $amount) {
                    FieldLeaveAllowance::updateOrCreate(
                        ['work_location_id' => $site->id, 'point_of_hire_id' => $point_of_hire->id, 'employee_class' => $employee_class],
                        ['amount' => $amount],
                    );
                }
            }
        }

        foreach (LeaveTravelRequest::with('employee')->where('leave_category', 'field')->whereNull('field_leave_allowance')->get() as $leave_travel) {
            $leave_travel->update(['field_leave_allowance' => FieldLeaveAllowanceRate::amount($leave_travel->employee, 'field')]);
        }

        // Tickets of the trips already over were bought; the coming ones partly.
        $admin = User::where('role', User::ROLE_HR)->first() ?? User::first();
        foreach (LeaveTravelRequest::where('status', 'approved')->where(fn ($q) => $q->where('needs_flight', true)->orWhere('needs_train', true))->orderBy('id')->get() as $i => $leave_travel) {
            $lewat = $leave_travel->start_date->isPast();
            if ($lewat || $i % 2 === 0) {
                $leave_travel->update(['outbound_ticket_issued_at' => $leave_travel->created_at, 'outbound_ticket_issued_by' => $admin?->id]);
            }
            if ($leave_travel->is_round_trip && $lewat) {
                $leave_travel->update(['return_ticket_issued_at' => $leave_travel->created_at, 'return_ticket_issued_by' => $admin?->id]);
            }
        }
    }
}
