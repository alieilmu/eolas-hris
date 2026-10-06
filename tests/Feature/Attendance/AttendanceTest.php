<?php

namespace Tests\Feature\Attendance;

use App\Models\Absensi;
use App\Models\Holiday;
use App\Models\Karyawan;
use App\Models\MasterOption;
use App\Models\Roster;
use App\Models\RosterPattern;
use App\Models\ShiftCode;
use App\Models\SubLocation;
use App\Models\Trip;
use App\Models\User;
use App\Support\Attendance\Period;
use App\Support\Attendance\Sheet;
use App\Support\Attendance\Stats;
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

    private function code(string $kode): ShiftCode
    {
        return ShiftCode::where('kode', $kode)->firstOrFail();
    }

    /** Active employees who joined before October 2026. */
    private function people(int $count = 2): array
    {
        return Karyawan::with('profile')->where('status', Karyawan::STATUS_AKTIF)->get()
            ->filter(fn (Karyawan $k) => ! $k->profile?->tanggal_masuk || $k->profile->tanggal_masuk->lt('2026-10-01'))
            ->take($count)->values()->all();
    }

    private function save(string $type, array $changes): TestResponse
    {
        return $this->post("/attendance/{$type}/simpan", ['changes' => $changes]);
    }

    private function cell(Karyawan $k, string $date, ?string $kode, ?string $sumber = null): array
    {
        return ['karyawan_id' => $k->id, 'tanggal' => $date, 'shift_code_id' => $kode ? $this->code($kode)->id : null, 'sumber' => $sumber];
    }

    private function trip(Karyawan $k, string $from, string $to, string $jenis = 'dinas'): TestResponse
    {
        return $this->post('/attendance/trip-leave', [
            'jenis' => $jenis,
            'kategori_cuti' => $jenis === 'cuti' ? 'lainnya' : null,
            'karyawan_id' => $k->id,
            'tanggal_awal' => $from,
            'tanggal_akhir' => $to,
            'kota_asal' => 'Balikpapan',
            'kota_tujuan' => 'Jakarta',
            'lampiran' => [UploadedFile::fake()->create('form.pdf', 40, 'application/pdf')],
        ]);
    }

    private function absensiCode(Karyawan $k, string $date): ?string
    {
        return Absensi::where('karyawan_id', $k->id)->whereDate('tanggal', $date)->first()?->shiftCode->kode;
    }

    private function upload(Spreadsheet $book): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'att').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'roster.xlsx', null, null, true);
    }

    // --- pages -------------------------------------------------------------------------------

    public function test_pages_render_and_viewers_are_kept_out(): void
    {
        [$a] = $this->people(1);
        $this->get('/attendance/roster?periode=2026-10')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/Sheet')
            ->where('type', 'roster')
            ->where('period.value', '2026-10')
            ->where('period.label', 'Oktober 2026')
            ->has('period.days', 31)
            ->where('period.days.3.weekday', 0) // 04.10.2026 is a Sunday
            ->has('codes', 8)
            ->has('patterns', 4)
            ->where('rows', fn ($rows) => collect($rows)->contains('id', $a->id)));
        $this->get('/attendance/absensi')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('type', 'absensi')
            ->where('period.value', '2026-10')
            ->has('rows.0.plan')
            ->has('rows.0.trip_days'));
        $this->get('/attendance/trip-leave')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Attendance/TripLeave')->has('daily', 31)->has('today.total'));
        $this->get('/attendance/pengajuan-dinas')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Attendance/Pengajuan')->where('filters.jenis', 'dinas'));
        $this->get('/attendance/master-data')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Attendance/MasterData')
            ->where('status_uang.values.0.nilai', 'Belum diajukan'));

        $this->actingAs(User::where('email', 'viewer@hrpuspita.local')->firstOrFail());
        $this->get('/attendance/roster')->assertForbidden();
        $this->post('/attendance/trip-leave', [])->assertForbidden();
    }

    public function test_employees_outside_their_employment_days_and_the_month(): void
    {
        [$a] = $this->people(1);
        $a->profile->update(['tanggal_masuk' => '2026-10-10']);

        $this->get('/attendance/roster?periode=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => collect($rows)->firstWhere('id', $a->id)['from'] === 10));
        $this->get('/attendance/roster?periode=2026-09')->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => ! collect($rows)->contains('id', $a->id)));
    }

    public function test_an_employee_who_left_is_blocked_from_the_exit_date_and_gone_the_month_after(): void
    {
        [$a, $b] = $this->people();
        $this->save('roster', [$this->cell($a, '2026-10-14', 'D'), $this->cell($a, '2026-10-20', 'D')]);
        $a->update(['status' => Karyawan::STATUS_KELUAR, 'tanggal_keluar' => '2026-10-15', 'alasan_keluar' => 'Mutasi']);
        // Leaving on the 1st: no working day that month at all.
        $b->update(['status' => Karyawan::STATUS_KELUAR, 'tanggal_keluar' => '2026-10-01', 'alasan_keluar' => 'Resign']);

        $this->get('/attendance/roster?periode=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('rows', function ($rows) use ($a, $b) {
                $row = collect($rows)->firstWhere('id', $a->id);

                return $row['until'] === 14
                    && $row['keluar'] === ['tanggal' => '2026-10-15', 'alasan' => 'Mutasi']
                    // What was planned after leaving is not shown.
                    && array_keys((array) $row['cells']) === [14]
                    && ! collect($rows)->contains('id', $b->id);
            }));
        $this->get('/attendance/roster?periode=2026-11')->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => ! collect($rows)->contains('id', $a->id)));
        $this->get('/attendance/absensi?periode=2026-09')->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => collect($rows)->firstWhere('id', $b->id)['keluar'] === null));

        // No codes from the exit date on, whether typed or imported.
        $this->save('roster', [$this->cell($a, '2026-10-15', 'D'), $this->cell($a, '2026-10-13', 'D')])
            ->assertInertiaFlash('success', 'Roster disimpan: 1 hari diisi; 1 hari dilewati (belum masuk / sudah keluar).');

        // The export shows the block, with the reason, from the exit day.
        $path = tempnam(sys_get_temp_dir(), 'exit').'.xlsx';
        file_put_contents($path, $this->get("/attendance/roster/export?periode=2026-10&q={$a->nrp}")->streamedContent());
        $sheet = IOFactory::load($path)->getSheetByName('Roster');
        $col = fn (int $day) => Coordinate::stringFromColumnIndex(5 + $day);
        $this->assertSame(['D', 'D', 'MUTASI'], [$sheet->getCell($col(13).'4')->getValue(), $sheet->getCell($col(14).'4')->getValue(), $sheet->getCell($col(15).'4')->getValue()]);
        $this->assertSame('1E293B', $sheet->getStyle($col(31).'4')->getFill()->getStartColor()->getRGB());
        // Imported back as it is: the block is passed over, not an unknown code.
        $read = $this->post('/attendance/roster/import', ['file' => new UploadedFile($path, 'r.xlsx', null, null, true)], ['Accept' => 'application/json'])->json();
        $this->assertSame([], $read['errors']);
        $this->assertSame(['2026-10-13', '2026-10-14'], collect($read['changes'])->pluck('tanggal')->sort()->values()->all());

        // Man Onsite no longer counts them from the exit date.
        $lokasi = $a->work_location_id;
        $this->get("/attendance/trip-leave?periode=2026-10&lokasi={$lokasi}")->assertInertia(fn (Assert $page) => $page
            ->where('daily', fn ($days) => $days[13]['total'] - $days[14]['total'] === 1));
    }

    // --- roster & absensi ----------------------------------------------------------------------

    public function test_saving_roster_cells_fills_and_empties_days(): void
    {
        [$a, $b] = $this->people();
        $this->save('roster', [
            $this->cell($a, '2026-10-01', 'D'),
            $this->cell($a, '2026-10-02', 'N'),
            $this->cell($b, '2026-10-01', 'OFF'),
        ])->assertInertiaFlash('success', 'Roster disimpan: 3 hari diisi.');
        $this->assertSame(3, Roster::count());

        // Changing one and emptying another.
        $this->save('roster', [$this->cell($a, '2026-10-01', 'OFF'), $this->cell($b, '2026-10-01', null)])
            ->assertInertiaFlash('success', 'Roster disimpan: 1 hari diisi, 1 dikosongkan.');
        $this->assertSame(2, Roster::count());
        $this->get('/attendance/roster?periode=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('rows', fn ($rows) => (array) collect($rows)->firstWhere('id', $a->id)['cells'] == [1 => $this->code('OFF')->id, 2 => $this->code('N')->id]));

        $this->save('roster', [['karyawan_id' => 999999, 'tanggal' => '2026-10-01', 'shift_code_id' => $this->code('D')->id]])->assertSessionHasErrors('changes');
        $this->save('roster', [['karyawan_id' => $a->id, 'tanggal' => '01.10.2026', 'shift_code_id' => null]])->assertSessionHasErrors('changes.0.tanggal');
    }

    public function test_absensi_against_roster_and_its_percentages(): void
    {
        [$a] = $this->people(1);
        $this->save('roster', array_map(fn ($d) => $this->cell($a, sprintf('2026-10-%02d', $d), $d <= 4 ? 'D' : 'OFF'), range(1, 5)))->assertSessionHasNoErrors();
        $this->save('absensi', [
            $this->cell($a, '2026-10-01', 'D', 'roster'),
            $this->cell($a, '2026-10-02', 'D', 'roster'),
            $this->cell($a, '2026-10-03', 'S'),
            $this->cell($a, '2026-10-04', 'A'),
            $this->cell($a, '2026-10-05', 'OFF', 'import'),
        ])->assertSessionHasNoErrors();
        $this->assertSame('roster', Absensi::whereDate('tanggal', '2026-10-01')->value('sumber'));
        $this->assertSame('import', Absensi::whereDate('tanggal', '2026-10-05')->value('sumber'));

        $row = collect(Sheet::rows('absensi', Period::from('2026-10')))->firstWhere('id', $a->id);
        $jenis = ShiftCode::pluck('jenis', 'id')->all();
        // Worked 2 of the 4 days due (OFF not counted); 3 of 5 days match the roster.
        $this->assertSame(['kehadiran' => 50.0, 'sesuai' => 60.0], Stats::of((array) $row['cells'], (array) $row['plan'], $jenis, 15));
    }

    // --- trip & leave --------------------------------------------------------------------------

    public function test_a_trip_fills_absensi_and_takes_its_days_back(): void
    {
        [$a] = $this->people(1);
        $this->save('absensi', [$this->cell($a, '2026-10-20', 'D')]);

        $this->trip($a, '2026-10-20', '2026-10-22')->assertSessionHasNoErrors()
            ->assertInertiaFlash('success', "Dinas {$a->name} ke Jakarta disimpan; Absensi 3 hari ikut terisi.");
        $trip = Trip::firstOrFail();
        $this->assertSame('Belum diajukan', $trip->status_uang);
        $this->assertSame(['DL', 'DL', 'DL'], [$this->absensiCode($a, '2026-10-20'), $this->absensiCode($a, '2026-10-21'), $this->absensiCode($a, '2026-10-22')]);
        $this->assertSame(3, Absensi::where('trip_id', $trip->id)->count());

        // Overlapping another trip of the same person is refused.
        $this->trip($a, '2026-10-22', '2026-10-25', 'cuti')->assertSessionHasErrors(['tanggal_awal' => 'Karyawan ini sudah tercatat Dinas ke Jakarta pada 20.10.2026 – 22.10.2026.']);
        $this->trip($a, '2026-10-26', '2026-10-25')->assertSessionHasErrors('tanggal_akhir');

        // A day corrected by hand no longer belongs to the trip.
        $this->save('absensi', [$this->cell($a, '2026-10-22', 'D')]);
        $this->assertNull(Absensi::whereDate('tanggal', '2026-10-22')->value('trip_id'));

        // Moving the trip moves its days.
        $this->put("/attendance/trip-leave/{$trip->id}", [
            'jenis' => 'cuti', 'kategori_cuti' => 'lainnya', 'karyawan_id' => $a->id, 'tanggal_awal' => '2026-10-21', 'tanggal_akhir' => '2026-10-21',
            'kota_asal' => 'Balikpapan', 'kota_tujuan' => 'Yogyakarta',
        ])->assertSessionHasNoErrors();
        $this->assertNull($this->absensiCode($a, '2026-10-20'));
        $this->assertSame('CT', $this->absensiCode($a, '2026-10-21'));
        $this->assertSame('D', $this->absensiCode($a, '2026-10-22'));

        $this->delete("/attendance/trip-leave/{$trip->id}")->assertSessionHasNoErrors();
        $this->assertNull($this->absensiCode($a, '2026-10-21'));
        $this->assertSame('D', $this->absensiCode($a, '2026-10-22'));
    }

    public function test_trip_needs_an_active_employee_and_a_code_of_its_kind(): void
    {
        [$a, $b] = $this->people();
        $b->update(['status' => Karyawan::STATUS_NONAKTIF]);
        $this->trip($b, '2026-10-20', '2026-10-21')->assertSessionHasErrors(['karyawan_id' => 'NRP tidak ditemukan di karyawan aktif.']);

        $this->code('DL')->update(['jenis' => 'hadir']);
        $this->trip($a, '2026-10-20', '2026-10-21')->assertSessionHasErrors(['jenis' => 'Belum ada Kode Shift berjenis "Dinas luar". Tambahkan dulu di Master Data Attendance.']);
        $this->assertSame(0, Trip::count());
    }

    public function test_man_onsite_per_day(): void
    {
        [$a, $b] = $this->people();
        $lokasi = $a->work_location_id;
        $b->update(['work_location_id' => $lokasi]);
        $total = Karyawan::where('work_location_id', $lokasi)->where('status', '!=', Karyawan::STATUS_KELUAR)->count();

        $this->save('roster', [$this->cell($a, '2026-10-16', 'D'), $this->cell($b, '2026-10-16', 'D')]);
        $this->save('absensi', [$this->cell($a, '2026-10-14', 'D'), $this->cell($b, '2026-10-14', 'S')]);
        $this->trip($b, '2026-10-16', '2026-10-17', 'cuti');

        $this->get("/attendance/trip-leave?periode=2026-10&lokasi={$lokasi}")->assertInertia(fn (Assert $page) => $page
            ->where('daily.13.date', '2026-10-14')
            ->where('daily.13.total', $total)
            ->where('daily.13.onsite', 1)
            ->where('daily.13.absen', 1)
            ->where('daily.13.from_plan', 0)
            // 16.10: A from the roster, B on leave (the trip beats the roster).
            ->where('daily.15.onsite', 1)
            ->where('daily.15.cuti', 1)
            ->where('daily.15.from_plan', 1)
            ->where('away', []));

        $this->trip($a, '2026-10-15', '2026-10-15');
        $this->get("/attendance/trip-leave?lokasi={$lokasi}")->assertInertia(fn (Assert $page) => $page
            ->where('today.dinas', 1)
            ->where('away.0.name', $a->name)
            ->where('away.0.sisa_hari', 0));
    }

    public function test_filtering_by_sub_lokasi_kerja(): void
    {
        [$a, $b] = $this->people();
        $b->update(['work_location_id' => $a->work_location_id, 'sub_location_id' => null]);
        $jetty = SubLocation::create(['work_location_id' => $a->work_location_id, 'name' => 'Jetty', 'order' => 1]);
        $a->update(['sub_location_id' => $jetty->id]);
        $this->save('roster', [$this->cell($a, '2026-10-01', 'D'), $this->cell($b, '2026-10-01', 'D')]);

        $this->get('/attendance/roster?periode=2026-10')->assertInertia(fn (Assert $page) => $page
            ->where('sub_locations', fn ($subs) => collect($subs)->contains('name', 'Jetty'))
            ->where('rows', fn ($rows) => collect($rows)->firstWhere('id', $a->id)['sub_location'] === 'Jetty'));

        $exported = function (string $sub) {
            $path = tempnam(sys_get_temp_dir(), 'sub').'.xlsx';
            file_put_contents($path, $this->get("/attendance/roster/export?periode=2026-10&bentuk=harian&sub={$sub}")->assertOk()->streamedContent());

            return collect(IOFactory::load($path)->getSheet(0)->toArray())->skip(1)->pluck(0)->all();
        };
        $this->assertSame([$a->nrp], $exported((string) $jetty->id));
        $this->assertContains($b->nrp, $exported('none'));
        $this->assertNotContains($a->nrp, $exported('none'));

        // Man Onsite of the Sub Lokasi only.
        $this->save('absensi', [$this->cell($a, '2026-10-15', 'D'), $this->cell($b, '2026-10-15', 'D')]);
        $this->get("/attendance/trip-leave?lokasi={$a->work_location_id}&sub={$jetty->id}")->assertInertia(fn (Assert $page) => $page
            ->where('sub', (string) $jetty->id)
            ->where('today.total', 1)
            ->where('today.onsite', 1));
        $this->trip($b, '2026-10-15', '2026-10-15');
        $this->get("/attendance/trip-leave?sub={$jetty->id}")->assertInertia(fn (Assert $page) => $page->where('away', []));
        $this->get('/attendance/trip-leave?sub=none')->assertInertia(fn (Assert $page) => $page->where('away.0.name', $b->name));
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
            $this->trip($k, $from, $to)->assertSessionHasNoErrors();
        }
        $this->trip($people[0], '2026-11-01', '2026-11-02', 'cuti');

        $this->get('/attendance/pengajuan-dinas')->assertInertia(fn (Assert $page) => $page
            ->has('trips.data', 10)
            ->where('trips.total', 12)
            ->where('trips.data.0.perjalanan', 'akan')
            ->where('trips.data.0.hari_ke', 5));
        $this->get('/attendance/pengajuan-dinas?page=2')->assertInertia(fn (Assert $page) => $page->has('trips.data', 2));
        $this->get('/attendance/pengajuan-dinas?perjalanan=berjalan')->assertInertia(fn (Assert $page) => $page
            ->has('trips.data', 1)
            ->where('trips.data.0.hari_ke', 2)
            ->where('trips.data.0.lama', 3));
        $this->get('/attendance/pengajuan-dinas?jenis=cuti')->assertInertia(fn (Assert $page) => $page->where('trips.total', 1));
        $this->get('/attendance/pengajuan-dinas?jenis=semua')->assertInertia(fn (Assert $page) => $page->where('trips.total', 13));

        $trip = Trip::where('karyawan_id', $people[3]->id)->firstOrFail();
        $this->put("/attendance/trip-leave/{$trip->id}/status-uang", ['status_uang' => 'Dicairkan'])->assertSessionHasNoErrors();
        $this->put("/attendance/trip-leave/{$trip->id}/status-uang", ['status_uang' => 'Entah'])->assertSessionHasErrors('status_uang');
        $this->get('/attendance/pengajuan-dinas?uang=Dicairkan')->assertInertia(fn (Assert $page) => $page->where('trips.total', 1));
    }

    // --- Excel ---------------------------------------------------------------------------------

    public function test_export_as_code_or_dws_and_import_back_into_the_draft(): void
    {
        [$a, $b] = $this->people();
        $this->save('roster', [$this->cell($a, '2026-10-01', 'D'), $this->cell($a, '2026-10-02', 'OFF')]);

        $response = $this->get("/attendance/roster/export?periode=2026-10&format=dws&q={$a->nrp}")->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $exported = IOFactory::load($path);
        $this->assertSame(['Petunjuk', 'Roster'], $exported->getSheetNames());

        // Petunjuk comes first: the codes from Master Data as a table, the steps beside it.
        $guide = $exported->getSheet(0);
        $this->assertSame(['KodeShift', 'DWS', 'Keterangan'], $guide->rangeToArray('A1:C1')[0]);
        $this->assertSame(['D', 'DAY', 'Day shift'], $guide->rangeToArray('A2:C2')[0]);
        $this->assertSame(['A1:C9'], array_map(fn ($t) => $t->getRange(), $guide->getTableCollection()->getArrayCopy()));
        $this->assertSame('Petunjuk import Roster', $guide->getCell('E1')->getValue());
        $this->assertStringStartsWith('1. Isi kolom tanggal di sheet Roster', $guide->getCell('E3')->getValue());

        $sheet = $exported->getSheetByName('Roster');
        $this->assertSame('ROSTER — OKTOBER 2026 (DWS)', $sheet->getCell('A1')->getValue());
        $this->assertSame('01.10.2026', $sheet->getCell('F2')->getValue());
        $this->assertSame('Kam', $sheet->getCell('F3')->getValue());
        $this->assertSame($a->nrp, $sheet->getCell('A4')->getValue());
        $this->assertSame(['DAY', 'OFF'], [$sheet->getCell('F4')->getValue(), $sheet->getCell('G4')->getValue()]);
        $this->assertNull($sheet->getCell('A5')->getValue());

        $daily = $this->get("/attendance/roster/export?periode=2026-10&format=kode&bentuk=harian&q={$a->nrp}")->assertOk();
        file_put_contents($path, $daily->streamedContent());
        $rows = IOFactory::load($path)->getSheet(0)->toArray();
        $this->assertSame(['NRP', 'Nama', 'Tanggal', 'Kode Shift', 'Keterangan'], $rows[0]);
        $this->assertSame([$a->nrp, $a->name, '01.10.2026', 'D', 'Day shift'], $rows[1]);

        // Fill the template: a code, a DWS, a "-" and a typo; plus an unknown NRP.
        $book = new Spreadsheet;
        $s = $book->getActiveSheet();
        $s->fromArray(['ROSTER — OKTOBER 2026'], null, 'A1');
        $s->fromArray(['NRP', 'Nama', 'Jabatan', 'Departemen', 'Lokasi Kerja', '01.10.2026', '02.10.2026', '03.10.2026'], null, 'A2');
        $s->fromArray(['', '', '', '', '', 'Kam', 'Jum', 'Sab'], null, 'A3');
        $s->setCellValueExplicit('A4', $a->nrp, 'str');
        $s->fromArray(['n', 'NIGHT', '-'], null, 'F4');
        $s->setCellValueExplicit('A5', $b->nrp, 'str');
        $s->fromArray(['XX', 'D'], null, 'F5');
        $s->setCellValueExplicit('A6', '99999999', 'str');

        $result = $this->post('/attendance/roster/import', ['file' => $this->upload($book)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(3, $result['rows']);
        $this->assertSame([['value' => '2026-10', 'label' => 'Oktober 2026', 'cells' => 4]], $result['months']);
        $this->assertEqualsCanonicalizing([
            ['karyawan_id' => $a->id, 'tanggal' => '2026-10-01', 'shift_code_id' => $this->code('N')->id],
            ['karyawan_id' => $a->id, 'tanggal' => '2026-10-02', 'shift_code_id' => $this->code('N')->id],
            ['karyawan_id' => $a->id, 'tanggal' => '2026-10-03', 'shift_code_id' => null],
            ['karyawan_id' => $b->id, 'tanggal' => '2026-10-02', 'shift_code_id' => $this->code('D')->id],
        ], $result['changes']);
        $this->assertCount(2, $result['errors']);
        $this->assertStringContainsString('01.10.2026: "XX"', $result['errors'][0]['message']);
        $this->assertSame('NRP 99999999 tidak ada di data Man Power.', $result['errors'][1]['message']);
        // Reading saves nothing.
        $this->assertSame('D', Roster::where('karyawan_id', $a->id)->whereDate('tanggal', '2026-10-01')->first()->shiftCode->kode);

        $empty = new Spreadsheet;
        $empty->getActiveSheet()->fromArray(['Nama', 'Tanggal'], null, 'A1');
        $none = $this->post('/attendance/roster/import', ['file' => $this->upload($empty)], ['Accept' => 'application/json'])->json();
        $this->assertStringStartsWith('Kolom "NRP" dan kolom tanggal', $none['errors'][0]['message']);
    }

    public function test_a_quarter_exported_on_one_sheet_and_imported_at_once(): void
    {
        [$a, $b] = $this->people();
        $this->save('roster', [$this->cell($a, '2026-11-02', 'D')]);

        // Template for Q4: every day of October to December side by side on one sheet.
        $path = tempnam(sys_get_temp_dir(), 'q4').'.xlsx';
        file_put_contents($path, $this->get("/attendance/roster/export?dari=2026-10&sampai=2026-12&q={$a->nrp}")->assertOk()->streamedContent());
        $book = IOFactory::load($path);
        $this->assertSame(['Petunjuk', 'Roster'], $book->getSheetNames());
        $sheet = $book->getSheetByName('Roster');
        // Column of the n-th day of the quarter (day 1 = 01.10.2026 in column F).
        $col = fn (int $n) => Coordinate::stringFromColumnIndex(5 + $n);
        $this->assertSame('ROSTER — OKTOBER 2026 – DESEMBER 2026', $sheet->getCell('A1')->getValue());
        $this->assertSame(['01.10.2026', '01.11.2026', '31.12.2026'], [$sheet->getCell($col(1).'2')->getValue(), $sheet->getCell($col(32).'2')->getValue(), $sheet->getCell($col(92).'2')->getValue()]);
        $this->assertNull($sheet->getCell($col(93).'2')->getValue());
        $this->assertSame('D', $sheet->getCell($col(33).'4')->getValue()); // 02.11.2026
        $this->get('/attendance/roster/export?dari=2026-01&sampai=2027-02')->assertSessionHasErrors(['sampai' => 'Paling banyak 12 bulan sekali unduh.']);
        $this->get('/attendance/roster/export?dari=2026-12&sampai=2026-10')->assertSessionHasErrors('sampai');

        // Fill a day in each month and import the whole file.
        $sheet->setCellValue($col(1).'4', 'N');      // 01.10
        $sheet->setCellValue($col(33).'4', '-');     // 02.11
        $sheet->setCellValue($col(66).'4', 'OFF');   // 05.12
        $file = $this->upload($book);

        $preview = $this->post('/attendance/roster/import', ['file' => $file], ['Accept' => 'application/json'])->json();
        $this->assertSame(['2026-10', '2026-11', '2026-12'], array_column($preview['months'], 'value'));

        $this->post('/attendance/roster/import/simpan', ['file' => $this->upload($book)])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('success', 'Roster Oktober 2026 – Desember 2026 diimport: 2 hari diisi, 1 dikosongkan.');
        $codes = Roster::where('karyawan_id', $a->id)->with('shiftCode')->orderBy('tanggal')->get()->map(fn ($r) => [$r->tanggal->toDateString(), $r->shiftCode->kode])->all();
        $this->assertSame([['2026-10-01', 'N'], ['2026-12-05', 'OFF']], $codes);

        $empty = new Spreadsheet;
        $this->post('/attendance/roster/import/simpan', ['file' => $this->upload($empty)])->assertSessionHasErrors('file');
    }

    // --- master data ---------------------------------------------------------------------------

    public function test_master_data_codes_patterns_holidays_and_money_statuses(): void
    {
        [$a] = $this->people(1);
        $this->post('/attendance/master-data/kode-shift', ['kode' => 'ns', 'dws' => 'NS12', 'keterangan' => 'Night shift 12 jam', 'jenis' => 'hadir'])->assertSessionHasNoErrors();
        $ns = $this->code('NS');
        $this->post('/attendance/master-data/kode-shift', ['kode' => 'D', 'jenis' => 'hadir'])->assertSessionHasErrors(['kode' => 'Kode Shift D sudah ada.']);
        $this->post('/attendance/master-data/kode-shift', ['kode' => 'N S', 'jenis' => 'hadir'])->assertSessionHasErrors('kode');

        $this->post('/attendance/master-data/pola-roster', ['nama' => '14:7', 'langkah' => [['shift_code_id' => $ns->id, 'hari' => 14], ['shift_code_id' => $this->code('OFF')->id, 'hari' => 7]]])
            ->assertSessionHasNoErrors();
        $this->assertSame(21, collect(RosterPattern::where('nama', '14:7')->firstOrFail()->langkah)->sum('hari'));
        $this->delete("/attendance/master-data/kode-shift/{$ns->id}")->assertSessionHasErrors(['kode' => 'Kode NS masih dipakai Pola Roster 14:7.']);

        $this->save('roster', [$this->cell($a, '2026-10-01', 'D')]);
        $this->delete("/attendance/master-data/kode-shift/{$this->code('D')->id}")->assertSessionHasErrors(['kode' => 'Kode D masih dipakai di 1 hari Roster/Absensi.']);
        $this->delete('/attendance/master-data/kode-shift/'.$this->code('I')->id)->assertSessionHasNoErrors();

        $this->post('/attendance/master-data/hari-libur', ['tanggal' => '2026-12-25', 'nama' => 'Natal'])->assertSessionHasNoErrors();
        $this->post('/attendance/master-data/hari-libur', ['tanggal' => '2026-12-25', 'nama' => 'Lagi'])->assertSessionHasErrors('tanggal');
        $this->get('/attendance/roster?periode=2026-12')->assertInertia(fn (Assert $page) => $page->where('period.days.24.holiday', 'Natal'));
        $this->assertSame(1, Holiday::count());

        // Status Pengajuan Uang uses the shared option list; renaming follows through to trips.
        $this->trip($a, '2026-10-20', '2026-10-21');
        $option = MasterOption::where(['kategori' => 'status_uang', 'nilai' => 'Belum diajukan'])->firstOrFail();
        $this->put("/manpower/master-data/opsi/{$option->id}", ['nilai' => 'Draft'])->assertSessionHasNoErrors();
        $this->assertSame('Draft', Trip::firstOrFail()->status_uang);
        $this->delete("/manpower/master-data/opsi/{$option->id}")->assertSessionHasErrors();
        // A new trip starts on the first status.
        $this->trip($a, '2026-10-25', '2026-10-25');
        $this->assertSame('Draft', Trip::latest('id')->first()->status_uang);
    }

    public function test_dates_use_dots_in_the_karyawan_excel_export(): void
    {
        $response = $this->get('/manpower/karyawan/excel/export')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'kar').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getSheet(0);
        $headers = $sheet->rangeToArray('A2:'.$sheet->getHighestColumn().'2')[0];
        $index = collect($headers)->search(fn ($h) => str_starts_with((string) $h, 'Tanggal Masuk'));
        $this->assertIsInt($index);
        $col = Coordinate::stringFromColumnIndex($index + 1);
        $this->assertMatchesRegularExpression('/^\d{2}\.\d{2}\.\d{4}$/', (string) $sheet->getCell("{$col}3")->getValue());
    }
}
