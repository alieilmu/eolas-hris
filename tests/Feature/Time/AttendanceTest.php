<?php

namespace Tests\Feature\Time;

use App\Http\Controllers\Time\SheetController;
use App\Models\AttendanceRecord;
use App\Models\PublicHoliday;
use App\Models\Employee;
use App\Models\LookupValue;
use App\Models\ShiftSchedule;
use App\Models\ShiftPattern;
use App\Models\ShiftCode;
use App\Models\SubLocation;
use App\Models\LeaveTravelRequest;
use App\Models\User;
use App\Support\Time\Period;
use App\Support\Time\Sheet;
use App\Support\Time\Stats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-15 09:00:00');
        CarbonImmutable::setTestNow('2026-10-15 09:00:00');
        $this->seed();
        $this->actingAs(User::where('role', User::ROLE_HR)->firstOrFail());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function code(string $code): ShiftCode
    {
        return ShiftCode::where('code', $code)->firstOrFail();
    }

    /** Active employees who joined before October 2026. */
    private function people(int $count = 2): array
    {
        return Employee::with('profile')->where('status', Employee::STATUS_ACTIVE)->get()
            ->filter(fn (Employee $k) => ! $k->profile?->hire_date || $k->profile->hire_date->lt('2026-10-01'))
            ->take($count)->values()->all();
    }

    private function save(string $type, array $changes): TestResponse
    {
        return $this->post('/time/'.SheetController::PATH[$type].'/save', ['changes' => $changes]);
    }

    private function cell(Employee $k, string $date, ?string $code, ?string $source = null): array
    {
        return ['employee_id' => $k->id, 'date' => $date, 'shift_code_id' => $code ? $this->code($code)->id : null, 'source' => $source];
    }

    private function leave_travel(Employee $k, string $from, string $to, string $jenis = 'business_trip'): TestResponse
    {
        return $this->post('/time/leave-travel', [
            'type' => $jenis,
            'leave_category' => $jenis === 'leave' ? 'other' : null,
            'employee_id' => $k->id,
            'start_date' => $from,
            'end_date' => $to,
            'origin_city' => 'Balikpapan',
            'destination_city' => 'Jakarta',
            'attachment' => [UploadedFile::fake()->create('form.pdf', 40, 'application/pdf')],
        ]);
    }

    private function attendanceCode(Employee $k, string $date): ?string
    {
        return AttendanceRecord::where('employee_id', $k->id)->whereDate('date', $date)->first()?->shiftCode->code;
    }

    private function upload(Spreadsheet $book): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'att').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'schedule.xlsx', null, null, true);
    }

    // --- pages -------------------------------------------------------------------------------

    public function test_pages_render_and_viewers_are_kept_out(): void
    {
        [$a] = $this->people(1);
        $this->get('/time/shift-schedule?period=2026-10')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Time/Sheet')
            ->where('type', 'schedule')
            ->where('period.value', '2026-10')
            ->where('period.label', 'Oktober 2026')
            ->has('period.days', 31)
            ->where('period.days.3.weekday', 0) // 04.10.2026 is a Sunday
            ->has('codes', 9) // incl. IP (Izin Potong Gapok)
            ->has('patterns', 4)
            ->where('rows', fn ($rows) => collect($rows)->contains('id', $a->id)));
        $this->get('/time/attendance')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('type', 'attendance')
            ->where('period.value', '2026-10')
            ->has('rows.0.plan')
            ->has('rows.0.trip_days'));
        $this->get('/time/leave-travel')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Time/LeaveTravel')->has('daily', 31)->has('today.total'));
        $this->get('/time/travel-requests')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Time/TravelRequests')->where('filters.type', 'business_trip'));
        $this->get('/time/settings')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Time/Settings')
            ->where('cash_advance_status.values.0.value', 'Belum diajukan'));

        $this->actingAs(User::where('email', 'viewer@eolas.local')->firstOrFail());
        $this->get('/time/shift-schedule')->assertForbidden();
        $this->post('/time/leave-travel', [])->assertForbidden();
    }

    public function test_employees_outside_their_employment_days_and_the_month(): void
    {
        [$a] = $this->people(1);
        $a->profile->update(['hire_date' => '2026-10-10']);

        $this->get('/time/shift-schedule?period=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => collect($rows)->firstWhere('id', $a->id)['from'] === 10));
        $this->get('/time/shift-schedule?period=2026-09')->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => ! collect($rows)->contains('id', $a->id)));
    }

    public function test_an_employee_who_left_is_blocked_from_the_exit_date_and_gone_the_month_after(): void
    {
        [$a, $b] = $this->people();
        $this->save('schedule', [$this->cell($a, '2026-10-14', 'D'), $this->cell($a, '2026-10-20', 'D')]);
        $a->update(['status' => Employee::STATUS_TERMINATED, 'termination_date' => '2026-10-15', 'termination_reason' => 'Mutasi']);
        // Leaving on the 1st: no working day that month at all.
        $b->update(['status' => Employee::STATUS_TERMINATED, 'termination_date' => '2026-10-01', 'termination_reason' => 'Resign']);

        $this->get('/time/shift-schedule?period=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('rows', function ($rows) use ($a, $b) {
                $row = collect($rows)->firstWhere('id', $a->id);

                return $row['until'] === 14
                    && $row['termination'] === ['date' => '2026-10-15', 'reason' => 'Mutasi']
                    // What was planned after leaving is not shown.
                    && array_keys((array) $row['cells']) === [14]
                    && ! collect($rows)->contains('id', $b->id);
            }));
        $this->get('/time/shift-schedule?period=2026-11')->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => ! collect($rows)->contains('id', $a->id)));
        $this->get('/time/attendance?period=2026-09')->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => collect($rows)->firstWhere('id', $b->id)['termination'] === null));

        // No codes from the exit date on, whether typed or imported.
        $this->save('schedule', [$this->cell($a, '2026-10-15', 'D'), $this->cell($a, '2026-10-13', 'D')])
            ->assertInertiaFlash('success', 'Roster disimpan: 1 hari diisi; 1 hari dilewati (belum masuk / sudah keluar).');

        // The export shows the block, with the reason, from the exit day.
        $path = tempnam(sys_get_temp_dir(), 'exit').'.xlsx';
        file_put_contents($path, $this->get("/time/shift-schedule/export?period=2026-10&q={$a->employee_number}")->streamedContent());
        $sheet = IOFactory::load($path)->getSheetByName('Roster');
        $col = fn (int $day) => Coordinate::stringFromColumnIndex(5 + $day);
        $this->assertSame(['D', 'D', 'MUTASI'], [$sheet->getCell($col(13).'4')->getValue(), $sheet->getCell($col(14).'4')->getValue(), $sheet->getCell($col(15).'4')->getValue()]);
        $this->assertSame('1E293B', $sheet->getStyle($col(31).'4')->getFill()->getStartColor()->getRGB());
        // Imported back as it is: the block is passed over, not an unknown code.
        $read = $this->post('/time/shift-schedule/import', ['file' => new UploadedFile($path, 'r.xlsx', null, null, true)], ['Accept' => 'application/json'])->json();
        $this->assertSame([], $read['errors']);
        $this->assertSame(['2026-10-13', '2026-10-14'], collect($read['changes'])->pluck('date')->sort()->values()->all());

        // Man Onsite no longer counts them from the exit date.
        $location = $a->work_location_id;
        $this->get("/time/leave-travel?period=2026-10&location={$location}")->assertInertia(fn (Assert $page) => $page
            ->where('daily', fn ($days) => $days[13]['total'] - $days[14]['total'] === 1));
    }

    // --- roster & absensi ----------------------------------------------------------------------

    public function test_saving_roster_cells_fills_and_empties_days(): void
    {
        [$a, $b] = $this->people();
        $this->save('schedule', [
            $this->cell($a, '2026-10-01', 'D'),
            $this->cell($a, '2026-10-02', 'N'),
            $this->cell($b, '2026-10-01', 'OFF'),
        ])->assertInertiaFlash('success', 'Roster disimpan: 3 hari diisi.');
        $this->assertSame(3, ShiftSchedule::count());

        // Changing one and emptying another.
        $this->save('schedule', [$this->cell($a, '2026-10-01', 'OFF'), $this->cell($b, '2026-10-01', null)])
            ->assertInertiaFlash('success', 'Roster disimpan: 1 hari diisi, 1 dikosongkan.');
        $this->assertSame(2, ShiftSchedule::count());
        $this->get('/time/shift-schedule?period=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => (array) collect($rows)->firstWhere('id', $a->id)['cells'] == [1 => $this->code('OFF')->id, 2 => $this->code('N')->id]));

        $this->save('schedule', [['employee_id' => 999999, 'date' => '2026-10-01', 'shift_code_id' => $this->code('D')->id]])->assertSessionHasErrors('changes');
        $this->save('schedule', [['employee_id' => $a->id, 'date' => '01.10.2026', 'shift_code_id' => null]])->assertSessionHasErrors('changes.0.date');
    }

    public function test_absensi_against_roster_and_its_percentages(): void
    {
        [$a] = $this->people(1);
        $this->save('schedule', array_map(fn ($d) => $this->cell($a, sprintf('2026-10-%02d', $d), $d <= 4 ? 'D' : 'OFF'), range(1, 5)))->assertSessionHasNoErrors();
        $this->save('attendance', [
            $this->cell($a, '2026-10-01', 'D', 'schedule'),
            $this->cell($a, '2026-10-02', 'D', 'schedule'),
            $this->cell($a, '2026-10-03', 'S'),
            $this->cell($a, '2026-10-04', 'A'),
            $this->cell($a, '2026-10-05', 'OFF', 'import'),
        ])->assertSessionHasNoErrors();
        $this->assertSame('schedule', AttendanceRecord::whereDate('date', '2026-10-01')->value('source'));
        $this->assertSame('import', AttendanceRecord::whereDate('date', '2026-10-05')->value('source'));

        $row = collect(Sheet::rows('attendance', Period::from('2026-10')))->firstWhere('id', $a->id);
        $jenis = ShiftCode::pluck('type', 'id')->all();
        // Worked 2 of the 4 days due (OFF not counted); 3 of 5 days match the roster.
        $this->assertSame(['time_clock' => 50.0, 'matching' => 60.0], Stats::of((array) $row['cells'], (array) $row['plan'], $jenis, 15));
    }

    // --- trip & leave --------------------------------------------------------------------------

    public function test_a_trip_fills_absensi_and_takes_its_days_back(): void
    {
        [$a] = $this->people(1);
        $this->save('attendance', [$this->cell($a, '2026-10-20', 'D')]);

        $this->leave_travel($a, '2026-10-20', '2026-10-22')->assertSessionHasNoErrors()
            ->assertInertiaFlash('success', "Dinas {$a->name} ke Jakarta disimpan; Absensi 3 hari ikut terisi.");
        $leave_travel = LeaveTravelRequest::firstOrFail();
        $this->assertSame('Belum diajukan', $leave_travel->cash_advance_status);
        $this->assertSame(['DL', 'DL', 'DL'], [$this->attendanceCode($a, '2026-10-20'), $this->attendanceCode($a, '2026-10-21'), $this->attendanceCode($a, '2026-10-22')]);
        $this->assertSame(3, AttendanceRecord::where('leave_travel_request_id', $leave_travel->id)->count());

        // Overlapping another trip of the same person is refused.
        $this->leave_travel($a, '2026-10-22', '2026-10-25', 'leave')->assertSessionHasErrors(['start_date' => 'Karyawan ini sudah tercatat Dinas ke Jakarta pada 20.10.2026 – 22.10.2026.']);
        $this->leave_travel($a, '2026-10-26', '2026-10-25')->assertSessionHasErrors('end_date');

        // A day corrected by hand no longer belongs to the trip.
        $this->save('attendance', [$this->cell($a, '2026-10-22', 'D')]);
        $this->assertNull(AttendanceRecord::whereDate('date', '2026-10-22')->value('leave_travel_request_id'));

        // Moving the trip moves its days.
        $this->put("/time/leave-travel/{$leave_travel->id}", [
            'type' => 'leave', 'leave_category' => 'other', 'employee_id' => $a->id, 'start_date' => '2026-10-21', 'end_date' => '2026-10-21',
            'origin_city' => 'Balikpapan', 'destination_city' => 'Yogyakarta',
        ])->assertSessionHasNoErrors();
        $this->assertNull($this->attendanceCode($a, '2026-10-20'));
        $this->assertSame('CT', $this->attendanceCode($a, '2026-10-21'));
        $this->assertSame('D', $this->attendanceCode($a, '2026-10-22'));

        $this->delete("/time/leave-travel/{$leave_travel->id}")->assertSessionHasNoErrors();
        $this->assertNull($this->attendanceCode($a, '2026-10-21'));
        $this->assertSame('D', $this->attendanceCode($a, '2026-10-22'));
    }

    public function test_trip_needs_an_active_employee_and_a_code_of_its_kind(): void
    {
        [$a, $b] = $this->people();
        $b->update(['status' => Employee::STATUS_INACTIVE]);
        $this->leave_travel($b, '2026-10-20', '2026-10-21')->assertSessionHasErrors(['employee_id' => 'NRP tidak ditemukan di karyawan aktif.']);

        $this->code('DL')->update(['type' => 'work']);
        $this->leave_travel($a, '2026-10-20', '2026-10-21')->assertSessionHasErrors(['type' => 'Belum ada Kode Shift berjenis "Dinas luar". Tambahkan dulu di Pengaturan Waktu & Kehadiran.']);
        $this->assertSame(0, LeaveTravelRequest::count());
    }

    public function test_man_onsite_per_day(): void
    {
        [$a, $b] = $this->people();
        $location = $a->work_location_id;
        $b->update(['work_location_id' => $location]);
        $total = Employee::where('work_location_id', $location)->where('status', '!=', Employee::STATUS_TERMINATED)->count();

        $this->save('schedule', [$this->cell($a, '2026-10-16', 'D'), $this->cell($b, '2026-10-16', 'D')]);
        $this->save('attendance', [$this->cell($a, '2026-10-14', 'D'), $this->cell($b, '2026-10-14', 'S')]);
        $this->leave_travel($b, '2026-10-16', '2026-10-17', 'leave');

        $this->get("/time/leave-travel?period=2026-10&location={$location}")->assertInertia(fn (Assert $page) => $page
            ->where('daily.13.date', '2026-10-14')
            ->where('daily.13.total', $total)
            ->where('daily.13.onsite', 1)
            ->where('daily.13.attendance', 1)
            ->where('daily.13.from_plan', 0)
            // 16.10: A from the roster, B on leave (the trip beats the roster).
            ->where('daily.15.onsite', 1)
            ->where('daily.15.leave', 1)
            ->where('daily.15.from_plan', 1)
            ->where('away', []));

        $this->leave_travel($a, '2026-10-15', '2026-10-15');
        $this->get("/time/leave-travel?location={$location}")->assertInertia(fn (Assert $page) => $page
            ->where('today.business_trip', 1)
            ->where('away.0.name', $a->name)
            ->where('away.0.days_left', 0));
    }

    public function test_filtering_by_sub_lokasi_kerja(): void
    {
        [$a, $b] = $this->people();
        $b->update(['work_location_id' => $a->work_location_id, 'sub_location_id' => null]);
        $jetty = SubLocation::create(['work_location_id' => $a->work_location_id, 'name' => 'Jetty', 'sort_order' => 1]);
        $a->update(['sub_location_id' => $jetty->id]);
        $this->save('schedule', [$this->cell($a, '2026-10-01', 'D'), $this->cell($b, '2026-10-01', 'D')]);

        $this->get('/time/shift-schedule?period=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('sub_locations', fn ($subs) => collect($subs)->contains('name', 'Jetty'))
            ->where('rows', fn ($rows) => collect($rows)->firstWhere('id', $a->id)['sub_location'] === 'Jetty'));

        $exported = function (string $sub) {
            $path = tempnam(sys_get_temp_dir(), 'sub').'.xlsx';
            file_put_contents($path, $this->get("/time/shift-schedule/export?period=2026-10&shape=daily&sub={$sub}")->assertOk()->streamedContent());

            return collect(IOFactory::load($path)->getSheet(0)->toArray())->skip(1)->pluck(0)->all();
        };
        $this->assertSame([$a->employee_number], $exported((string) $jetty->id));
        $this->assertContains($b->employee_number, $exported('none'));
        $this->assertNotContains($a->employee_number, $exported('none'));

        // Man Onsite of the Sub Lokasi only.
        $this->save('attendance', [$this->cell($a, '2026-10-15', 'D'), $this->cell($b, '2026-10-15', 'D')]);
        $this->get("/time/leave-travel?location={$a->work_location_id}&sub={$jetty->id}")->assertInertia(fn (Assert $page) => $page
            ->where('sub', (string) $jetty->id)
            ->where('today.total', 1)
            ->where('today.onsite', 1));
        $this->leave_travel($b, '2026-10-15', '2026-10-15');
        $this->get("/time/leave-travel?sub={$jetty->id}")->assertInertia(fn (Assert $page) => $page->where('away', []));
        $this->get('/time/leave-travel?sub=none')->assertInertia(fn (Assert $page) => $page->where('away.0.name', $b->name));
    }

    public function test_pengajuan_dinas_lists_ten_per_page_with_trip_status(): void
    {
        $people = $this->people(12);
        foreach ($people as $i => $k) {
            // 2 upcoming, 1 ongoing (today = 15.10), the rest done.
            [$from, $to] = match (true) {
                $i < 2 => ['2026-10-20', '2026-10-22'],
                $i === 2 => ['2026-10-14', '2026-10-16'],
                default => ['2026-10-01', '2026-10-03'],
            };
            $this->leave_travel($k, $from, $to)->assertSessionHasNoErrors();
        }
        $this->leave_travel($people[0], '2026-11-01', '2026-11-02', 'leave');

        $this->get('/time/travel-requests')->assertInertia(fn (Assert $page) => $page
            ->has('leave_travel_requests.data', 10)
            ->where('leave_travel_requests.total', 12)
            ->where('leave_travel_requests.data.0.travel', 'soon')
            ->where('leave_travel_requests.data.0.day_number', 5));
        $this->get('/time/travel-requests?page=2')->assertInertia(fn (Assert $page) => $page->has('leave_travel_requests.data', 2));
        $this->get('/time/travel-requests?travel=ongoing')->assertInertia(fn (Assert $page) => $page
            ->has('leave_travel_requests.data', 1)
            ->where('leave_travel_requests.data.0.day_number', 2)
            ->where('leave_travel_requests.data.0.old', 3));
        $this->get('/time/travel-requests?type=leave')->assertInertia(fn (Assert $page) => $page->where('leave_travel_requests.total', 1));
        $this->get('/time/travel-requests?type=all')->assertInertia(fn (Assert $page) => $page->where('leave_travel_requests.total', 13));

        $leave_travel = LeaveTravelRequest::where('employee_id', $people[3]->id)->firstOrFail();
        $this->put("/time/leave-travel/{$leave_travel->id}/cash-status", ['cash_advance_status' => 'Dicairkan'])->assertSessionHasNoErrors();
        $this->put("/time/leave-travel/{$leave_travel->id}/cash-status", ['cash_advance_status' => 'Entah'])->assertSessionHasErrors('cash_advance_status');
        $this->get('/time/travel-requests?cash=Dicairkan')->assertInertia(fn (Assert $page) => $page->where('leave_travel_requests.total', 1));
    }

    // --- Excel ---------------------------------------------------------------------------------

    public function test_export_as_code_or_dws_and_import_back_into_the_draft(): void
    {
        [$a, $b] = $this->people();
        $this->save('schedule', [$this->cell($a, '2026-10-01', 'D'), $this->cell($a, '2026-10-02', 'OFF')]);

        $response = $this->get("/time/shift-schedule/export?period=2026-10&format=daily_work_schedule&q={$a->employee_number}")->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $exported = IOFactory::load($path);
        $this->assertSame(['Petunjuk', 'Roster'], $exported->getSheetNames());

        // Petunjuk comes first: the codes from Master Data as a table, the steps beside it.
        $guide = $exported->getSheet(0);
        $this->assertSame(['Shift Code', 'DWS', 'Description'], $guide->rangeToArray('A1:C1')[0]);
        $this->assertSame(['D', 'DAY', 'Day shift'], $guide->rangeToArray('A2:C2')[0]);
        $this->assertSame(['A1:C10'], array_map(fn ($t) => $t->getRange(), $guide->getTableCollection()->getArrayCopy()));
        $this->assertSame('Petunjuk import Roster', $guide->getCell('E1')->getValue());
        $this->assertStringStartsWith('1. Isi kolom tanggal di sheet Roster', $guide->getCell('E3')->getValue());

        $sheet = $exported->getSheetByName('Roster');
        $this->assertSame('ROSTER — OKTOBER 2026 (DWS)', $sheet->getCell('A1')->getValue());
        $this->assertSame('01.10.2026', $sheet->getCell('F2')->getValue());
        $this->assertSame('Kam', $sheet->getCell('F3')->getValue());
        $this->assertSame($a->employee_number, $sheet->getCell('A4')->getValue());
        $this->assertSame(['DAY', 'OFF'], [$sheet->getCell('F4')->getValue(), $sheet->getCell('G4')->getValue()]);
        $this->assertNull($sheet->getCell('A5')->getValue());

        $daily = $this->get("/time/shift-schedule/export?period=2026-10&format=code&shape=daily&q={$a->employee_number}")->assertOk();
        file_put_contents($path, $daily->streamedContent());
        $rows = IOFactory::load($path)->getSheet(0)->toArray();
        $this->assertSame(['Employee Number', 'Name', 'Date', 'Shift Code', 'Description'], $rows[0]);
        $this->assertSame([$a->employee_number, $a->name, '01.10.2026', 'D', 'Day shift'], $rows[1]);

        // Fill the template: a code, a DWS, a "-" and a typo; plus an unknown NRP.
        $book = new Spreadsheet;
        $s = $book->getActiveSheet();
        $s->fromArray(['ROSTER — OKTOBER 2026'], null, 'A1');
        $s->fromArray(['NRP', 'Nama', 'Jabatan', 'Departemen', 'Lokasi Kerja', '01.10.2026', '02.10.2026', '03.10.2026'], null, 'A2');
        $s->fromArray(['', '', '', '', '', 'Kam', 'Jum', 'Sab'], null, 'A3');
        $s->setCellValueExplicit('A4', $a->employee_number, 'str');
        $s->fromArray(['n', 'NIGHT', '-'], null, 'F4');
        $s->setCellValueExplicit('A5', $b->employee_number, 'str');
        $s->fromArray(['XX', 'D'], null, 'F5');
        $s->setCellValueExplicit('A6', '99999999', 'str');

        $result = $this->post('/time/shift-schedule/import', ['file' => $this->upload($book)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(3, $result['rows']);
        $this->assertSame([['value' => '2026-10', 'label' => 'Oktober 2026', 'cells' => 4]], $result['months']);
        $this->assertEqualsCanonicalizing([
            ['employee_id' => $a->id, 'date' => '2026-10-01', 'shift_code_id' => $this->code('N')->id],
            ['employee_id' => $a->id, 'date' => '2026-10-02', 'shift_code_id' => $this->code('N')->id],
            ['employee_id' => $a->id, 'date' => '2026-10-03', 'shift_code_id' => null],
            ['employee_id' => $b->id, 'date' => '2026-10-02', 'shift_code_id' => $this->code('D')->id],
        ], $result['changes']);
        $this->assertCount(2, $result['errors']);
        $this->assertStringContainsString('01.10.2026: "XX"', $result['errors'][0]['message']);
        $this->assertSame('NRP 99999999 tidak ada di data Man Power.', $result['errors'][1]['message']);
        // Reading saves nothing.
        $this->assertSame('D', ShiftSchedule::where('employee_id', $a->id)->whereDate('date', '2026-10-01')->first()->shiftCode->code);

        $empty = new Spreadsheet;
        $empty->getActiveSheet()->fromArray(['Nama', 'Tanggal'], null, 'A1');
        $none = $this->post('/time/shift-schedule/import', ['file' => $this->upload($empty)], ['Accept' => 'application/json'])->json();
        $this->assertStringStartsWith('Kolom "Employee Number" dan kolom tanggal', $none['errors'][0]['message']);
    }

    public function test_a_quarter_exported_on_one_sheet_and_imported_at_once(): void
    {
        [$a, $b] = $this->people();
        $this->save('schedule', [$this->cell($a, '2026-11-02', 'D')]);

        // Template for Q4: every day of October to December side by side on one sheet.
        $path = tempnam(sys_get_temp_dir(), 'q4').'.xlsx';
        file_put_contents($path, $this->get("/time/shift-schedule/export?from=2026-10&until=2026-12&q={$a->employee_number}")->assertOk()->streamedContent());
        $book = IOFactory::load($path);
        $this->assertSame(['Petunjuk', 'Roster'], $book->getSheetNames());
        $sheet = $book->getSheetByName('Roster');
        // Column of the n-th day of the quarter (day 1 = 01.10.2026 in column F).
        $col = fn (int $n) => Coordinate::stringFromColumnIndex(5 + $n);
        $this->assertSame('ROSTER — OKTOBER 2026 – DESEMBER 2026', $sheet->getCell('A1')->getValue());
        $this->assertSame(['01.10.2026', '01.11.2026', '31.12.2026'], [$sheet->getCell($col(1).'2')->getValue(), $sheet->getCell($col(32).'2')->getValue(), $sheet->getCell($col(92).'2')->getValue()]);
        $this->assertNull($sheet->getCell($col(93).'2')->getValue());
        $this->assertSame('D', $sheet->getCell($col(33).'4')->getValue()); // 02.11.2026
        $this->get('/time/shift-schedule/export?from=2026-01&until=2027-02')->assertSessionHasErrors(['until' => 'Paling banyak 12 bulan sekali unduh.']);
        $this->get('/time/shift-schedule/export?from=2026-12&until=2026-10')->assertSessionHasErrors('until');

        // Fill a day in each month and import the whole file.
        $sheet->setCellValue($col(1).'4', 'N');      // 01.10
        $sheet->setCellValue($col(33).'4', '-');     // 02.11
        $sheet->setCellValue($col(66).'4', 'OFF');   // 05.12
        $file = $this->upload($book);

        $preview = $this->post('/time/shift-schedule/import', ['file' => $file], ['Accept' => 'application/json'])->json();
        $this->assertSame(['2026-10', '2026-11', '2026-12'], array_column($preview['months'], 'value'));

        $this->post('/time/shift-schedule/import/save', ['file' => $this->upload($book)])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('success', 'Roster Oktober 2026 – Desember 2026 diimport: 2 hari diisi, 1 dikosongkan.');
        $codes = ShiftSchedule::where('employee_id', $a->id)->with('shiftCode')->orderBy('date')->get()->map(fn ($r) => [$r->date->toDateString(), $r->shiftCode->code])->all();
        $this->assertSame([['2026-10-01', 'N'], ['2026-12-05', 'OFF']], $codes);

        $empty = new Spreadsheet;
        $this->post('/time/shift-schedule/import/save', ['file' => $this->upload($empty)])->assertSessionHasErrors('file');
    }

    // --- master data ---------------------------------------------------------------------------

    public function test_master_data_codes_patterns_holidays_and_money_statuses(): void
    {
        [$a] = $this->people(1);
        $this->post('/time/settings/shift-codes', ['code' => 'ns', 'daily_work_schedule' => 'NS12', 'description' => 'Night shift 12 jam', 'type' => 'work'])->assertSessionHasNoErrors();
        $ns = $this->code('NS');
        $this->post('/time/settings/shift-codes', ['code' => 'D', 'type' => 'work'])->assertSessionHasErrors(['code' => 'Kode Shift D sudah ada.']);
        $this->post('/time/settings/shift-codes', ['code' => 'N S', 'type' => 'work'])->assertSessionHasErrors('code');

        $this->post('/time/settings/shift-patterns', ['name' => '14:7', 'steps' => [['shift_code_id' => $ns->id, 'days' => 14], ['shift_code_id' => $this->code('OFF')->id, 'days' => 7]]])
            ->assertSessionHasNoErrors();
        $this->assertSame(21, collect(ShiftPattern::where('name', '14:7')->firstOrFail()->steps)->sum('days'));
        $this->delete("/time/settings/shift-codes/{$ns->id}")->assertSessionHasErrors(['code' => 'Kode NS masih dipakai Pola Roster 14:7.']);

        $this->save('schedule', [$this->cell($a, '2026-10-01', 'D')]);
        $this->delete("/time/settings/shift-codes/{$this->code('D')->id}")->assertSessionHasErrors(['code' => 'Kode D masih dipakai di 1 hari Roster/Absensi.']);
        $this->delete('/time/settings/shift-codes/'.$this->code('I')->id)->assertSessionHasNoErrors();

        $this->post('/time/settings/public-holidays', ['date' => '2026-12-25', 'name' => 'Natal'])->assertSessionHasNoErrors();
        $this->post('/time/settings/public-holidays', ['date' => '2026-12-25', 'name' => 'Lagi'])->assertSessionHasErrors('date');
        $this->get('/time/shift-schedule?period=2026-12')->assertInertia(fn (Assert $page) => $page->where('period.days.24.holiday', 'Natal'));
        $this->assertSame(1, PublicHoliday::count());

        // Status Pengajuan Uang uses the shared option list; renaming follows through to trips.
        $this->leave_travel($a, '2026-10-20', '2026-10-21');
        $option = LookupValue::where(['category' => 'cash_advance_status', 'value' => 'Belum diajukan'])->firstOrFail();
        $this->put("/core-hr/settings/options/{$option->id}", ['value' => 'Draft'])->assertSessionHasNoErrors();
        $this->assertSame('Draft', LeaveTravelRequest::firstOrFail()->cash_advance_status);
        $this->delete("/core-hr/settings/options/{$option->id}")->assertSessionHasErrors();
        // A new trip starts on the first status.
        $this->leave_travel($a, '2026-10-25', '2026-10-25');
        $this->assertSame('Draft', LeaveTravelRequest::latest('id')->first()->cash_advance_status);
    }

    public function test_dates_use_dots_in_the_karyawan_excel_export(): void
    {
        $response = $this->get('/core-hr/employees/excel/export')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'emp').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getSheet(0);
        $headers = $sheet->rangeToArray('A2:'.$sheet->getHighestColumn().'2')[0];
        $index = collect($headers)->search(fn ($h) => str_starts_with((string) $h, 'Hire Date'));
        $this->assertIsInt($index);
        $col = Coordinate::stringFromColumnIndex($index + 1);
        $this->assertMatchesRegularExpression('/^\d{2}\.\d{2}\.\d{4}$/', (string) $sheet->getCell("{$col}3")->getValue());
    }
}
