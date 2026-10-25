<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\FieldLeaveEntitlement;
use App\Models\Employee;
use App\Models\PointOfHire;
use App\Models\ShiftCode;
use App\Models\LeaveTravelRequest;
use App\Models\User;
use App\Support\Time\LeaveTravelImport;
use App\Support\Leave\LeaveLedger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class FieldLeaveAllowanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-06 09:00:00');
        CarbonImmutable::setTestNow('2026-10-06 09:00:00');
        $this->seed();
        foreach (['leave' => 'CT', 'business_trip' => 'DL'] as $type => $code) {
            if (! ShiftCode::forType($type)) {
                ShiftCode::create(['code' => $code, 'daily_work_schedule' => strtoupper($type), 'description' => $type, 'type' => $type]);
            }
        }
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** An active employee hired 2018 (enough annual leave), Staff or Non Staff. */
    private function employee(string $employee_class = 'Non Staff', int $skip = 0): Employee
    {
        $k = Employee::with('profile')->where('status', Employee::STATUS_ACTIVE)->whereNotNull('work_location_id')->orderBy('id')->skip($skip)->firstOrFail();
        $k->profile->update(['hire_date' => '2018-02-01', 'employee_class' => $employee_class, 'national_id_number' => '63710'.str_pad((string) $k->id, 11, '0', STR_PAD_LEFT)]);

        return $k->fresh(['profile', 'workLocation']);
    }

    private function leave_travel(Employee $k, array $data): void
    {
        $this->post('/time/leave-travel', [
            'employee_id' => $k->id, 'origin_city' => 'Tanjung', 'destination_city' => 'Banjarmasin',
            'attachment' => [UploadedFile::fake()->create('form.pdf', 20, 'application/pdf')], ...$data,
        ])->assertSessionHasNoErrors();
    }

    public function test_cuti_lapangan_gets_the_lumpsum_of_its_site_poh_and_klasifikasi(): void
    {
        $k = $this->employee('Non Staff');
        $point_of_hire = PointOfHire::where('code', substr($k->employee_number, 1, 2))->firstOrFail();
        FieldLeaveEntitlement::create(['work_location_id' => $k->work_location_id, 'leave_days' => 14, 'outbound_travel_days' => 2, 'return_travel_days' => 2]);

        // Without a lumpsum set: the form says why.
        $this->getJson("/time/leave-info/{$k->id}")->assertOk()
            ->assertJsonPath('field_leave_allowance.amount', null)
            ->assertJsonPath('field_leave_allowance.point_of_hire', $point_of_hire->name);

        $this->put('/compensation/settings/field-leave-allowances', [
            'work_location_id' => $k->work_location_id,
            'amount' => [$point_of_hire->id => ['Staff' => 3500000, 'Non Staff' => 2000000]],
        ])->assertSessionHasNoErrors();
        $this->get('/compensation/settings')->assertInertia(fn (Assert $page) => $page->has('field_leave_allowance', 2)->where('employee_class', ['Staff', 'Non Staff']));
        $this->getJson("/time/leave-info/{$k->id}")->assertJsonPath('field_leave_allowance.amount', 2000000)->assertJsonPath('field_leave_allowance.employee_class', 'Non Staff');

        // Only a Cuti Lapangan gets it, at the amount of the day it is entered.
        $this->leave_travel($k, ['type' => 'leave', 'leave_category' => 'field', 'start_date' => '2026-10-10', 'end_date' => '2026-10-27']);
        $this->leave_travel($k, ['type' => 'leave', 'leave_category' => 'annual', 'start_date' => '2026-11-02', 'end_date' => '2026-11-03']);
        $this->assertSame([2000000, null], LeaveTravelRequest::orderBy('id')->pluck('field_leave_allowance')->all());

        $k->profile->update(['employee_class' => 'Staff']);
        $this->leave_travel($k, ['type' => 'leave', 'leave_category' => 'field', 'start_date' => '2026-12-01', 'end_date' => '2026-12-10']);
        $this->assertSame(3500000, LeaveTravelRequest::latest('id')->value('field_leave_allowance'));

        // Emptied: no lumpsum for the next ones; earlier ones keep theirs.
        $this->put('/compensation/settings/field-leave-allowances', ['work_location_id' => $k->work_location_id, 'amount' => [$point_of_hire->id => ['Staff' => null, 'Non Staff' => null]]])
            ->assertSessionHasNoErrors();
        $this->assertSame(null, LeaveLedger::info($k->fresh())['field_leave_allowance']['amount']);
        $this->assertSame([2000000, null, 3500000], LeaveTravelRequest::orderBy('id')->pluck('field_leave_allowance')->all());
    }

    public function test_pengajuan_dinas_lists_nik_poh_and_whether_tickets_are_bought(): void
    {
        $k = $this->employee();
        $this->leave_travel($k, [
            'type' => 'business_trip', 'start_date' => '2026-10-12', 'end_date' => '2026-10-14',
            'needs_flight' => '1', 'departure_airport' => 'BDJ', 'arrival_airport' => 'CGK',
            'is_round_trip' => '1', 'return_date' => '2026-10-14', 'return_departure_airport' => 'CGK', 'return_arrival_airport' => 'BDJ',
        ]);
        $this->leave_travel($k, ['type' => 'business_trip', 'start_date' => '2026-10-20', 'end_date' => '2026-10-20']);
        $leave_travel = LeaveTravelRequest::orderBy('id')->firstOrFail();

        $this->get('/time/travel-requests')->assertInertia(fn (Assert $page) => $page
            ->where('tickets_pending', 1)
            ->where('leave_travel_requests.data.1.national_id_number', $k->profile->national_id_number)
            ->where('leave_travel_requests.data.1.point_of_hire', PointOfHire::where('code', substr($k->employee_number, 1, 2))->value('name'))
            ->where('leave_travel_requests.data.1.tickets_issued.outbound', null));

        $this->put("/time/leave-travel/{$leave_travel->id}/ticket", ['direction' => 'outbound', 'issued' => true])->assertSessionHasNoErrors();
        $this->assertNotNull($leave_travel->fresh()->outbound_ticket_issued_at);
        // Still to buy: the return flight.
        $this->get('/time/travel-requests?ticket=not_yet')->assertInertia(fn (Assert $page) => $page->has('leave_travel_requests.data', 1)->where('tickets_pending', 1));
        $this->put("/time/leave-travel/{$leave_travel->id}/ticket", ['direction' => 'return', 'issued' => true])->assertSessionHasNoErrors();
        $this->get('/time/travel-requests?ticket=not_yet')->assertInertia(fn (Assert $page) => $page->has('leave_travel_requests.data', 0)->where('tickets_pending', 0));

        // A trip without a ticket has none to buy; search by NIK finds the employee.
        $other = LeaveTravelRequest::latest('id')->firstOrFail();
        $this->put("/time/leave-travel/{$other->id}/ticket", ['direction' => 'outbound', 'issued' => true])->assertSessionHasErrors('direction');
        $this->get('/time/travel-requests?q='.$k->profile->national_id_number)->assertInertia(fn (Assert $page) => $page->has('leave_travel_requests.data', 2));
        $this->get('/time/leave-travel/export')->assertOk();
    }

    public function test_import_checks_every_row_then_saves_the_valid_ones(): void
    {
        $a = $this->employee();
        $b = $this->employee('Staff', 1);
        $this->get('/time/travel-requests/template')->assertOk()->assertDownload('template-import-travel-requests.xlsx');

        $file = $this->xlsx([
            [$a->employee_number, '', 'Dinas', '', '02.11.2026', '05.11.2026', 'Tanjung', 'Jakarta', 'Rapat', 'BDJ', 'CGK', '05.11.2026', 'CGK', 'BDJ', '', '1.500.000', 'Ya', ''],
            [$b->employee_number, '', 'Cuti', 'Tahunan', '2026-11-10', '2026-11-11', 'Tanjung', 'Banjarmasin', '', '', '', '', '', '', '', '', '', ''],
            ['99999999', '', 'Dinas', '', '2026-11-10', '2026-11-11', 'Tanjung', 'Jakarta', '', '', '', '', '', '', '', '', '', ''],
            // Overlaps the first row of the same employee.
            [$a->employee_number, '', 'Dinas', '', '2026-11-04', '2026-11-06', 'Tanjung', 'Balikpapan', '', '', '', '', '', '', '', '', '', ''],
        ]);

        $check = $this->post('/time/travel-requests/import', ['file' => $file])->assertOk()->assertJsonPath('ok', 2)->assertJsonPath('failed', 2);
        $this->assertSame([5, 6, 7, 8], array_column($check->json('rows'), 'rows'));
        $this->assertStringContainsString('NRP 99999999 tidak ditemukan', $check->json('rows.2.message.0'));
        $this->assertSame(0, LeaveTravelRequest::count()); // only checked

        $this->post('/time/travel-requests/import', ['file' => $this->xlsxAgain(), 'save' => 1])->assertSessionHasNoErrors()
            ->assertInertiaFlash('success', '2 pengajuan diimport dan Absensi ikut terisi; 2 baris dilewati.');
        $business_trip = LeaveTravelRequest::where('employee_id', $a->id)->firstOrFail();
        $this->assertSame(['business_trip', 'approved', true, true, 1500000, 'BDJ'], [$business_trip->type, $business_trip->status, $business_trip->is_round_trip, $business_trip->needs_cash_advance, $business_trip->cash_advance_amount, $business_trip->departure_airport]);
        $this->assertNotNull($business_trip->outbound_ticket_issued_at);
        $this->assertNull($business_trip->return_ticket_issued_at);
        $this->assertSame(4, AttendanceRecord::where('leave_travel_request_id', $business_trip->id)->count());
        $this->assertSame(['annual', 2], [LeaveTravelRequest::where('employee_id', $b->id)->value('leave_category'), LeaveTravelRequest::where('employee_id', $b->id)->value('annual_leave_deducted')]);
    }

    public function test_the_portal_card_lists_the_active_annual_periods(): void
    {
        $t = LeaveLedger::annual(CarbonImmutable::parse('2023-01-10'), [2025 => 5, 2026 => 2], 0, CarbonImmutable::parse('2026-07-08'));
        // Quotas live two years: 2024 (paid the 5 days of 2025) is gone; 2025 paid the 2 days of 2026.
        $this->assertSame([
            ['year' => 2025, 'start_date' => '2025-01-10', 'effective_until' => '2026-12-31', 'entitlement' => 12, 'used' => 2, 'remaining' => 10],
            ['year' => 2026, 'start_date' => '2026-01-10', 'effective_until' => '2027-12-31', 'entitlement' => 12, 'used' => 0, 'remaining' => 12],
        ], $t['period']);
        $this->assertSame(['2027-01-10', 12, 22], [$t['next_entitlement_date'], $t['next_entitlement'], $t['balance']]);
    }

    private array $rows = [];

    private function xlsx(array $rows): UploadedFile
    {
        $this->rows = $rows;

        return $this->xlsxAgain();
    }

    /** A sheet like the template: title, info, header in row 4, data from row 5. */
    private function xlsxAgain(): UploadedFile
    {
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet();
        $ws->setCellValue('A1', 'Import Pengajuan Dinas');
        $ws->fromArray(array_keys(LeaveTravelImport::COLUMNS), null, 'A4');
        $ws->fromArray($this->rows, null, 'A5');
        foreach ($this->rows as $i => $row) {
            $ws->setCellValueExplicit('A'.($i + 5), $row[0], DataType::TYPE_STRING);
        }
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
