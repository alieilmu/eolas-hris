<?php

namespace Tests\Feature;

use App\Models\Absensi;
use App\Models\CutiLapangan;
use App\Models\Karyawan;
use App\Models\LokasiPenerimaan;
use App\Models\ShiftCode;
use App\Models\Trip;
use App\Models\User;
use App\Support\Attendance\TripImport;
use App\Support\Cuti\Cuti;
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

class TripLumpsumTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-06 09:00:00');
        CarbonImmutable::setTestNow('2026-10-06 09:00:00');
        $this->seed();
        foreach (['cuti' => 'CT', 'dinas' => 'DL'] as $jenis => $kode) {
            if (! ShiftCode::forJenis($jenis)) {
                ShiftCode::create(['kode' => $kode, 'dws' => strtoupper($jenis), 'keterangan' => $jenis, 'jenis' => $jenis]);
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
    private function employee(string $klasifikasi = 'Non Staff', int $skip = 0): Karyawan
    {
        $k = Karyawan::with('profile')->where('status', Karyawan::STATUS_AKTIF)->whereNotNull('work_location_id')->orderBy('id')->skip($skip)->firstOrFail();
        $k->profile->update(['tanggal_masuk' => '2018-02-01', 'klasifikasi' => $klasifikasi, 'nik' => '63710'.str_pad((string) $k->id, 11, '0', STR_PAD_LEFT)]);

        return $k->fresh(['profile', 'workLocation']);
    }

    private function trip(Karyawan $k, array $data): void
    {
        $this->post('/attendance/trip-leave', [
            'karyawan_id' => $k->id, 'kota_asal' => 'Tanjung', 'kota_tujuan' => 'Banjarmasin',
            'lampiran' => [UploadedFile::fake()->create('form.pdf', 20, 'application/pdf')], ...$data,
        ])->assertSessionHasNoErrors();
    }

    public function test_cuti_lapangan_gets_the_lumpsum_of_its_site_poh_and_klasifikasi(): void
    {
        $k = $this->employee('Non Staff');
        $poh = LokasiPenerimaan::where('kode', substr($k->nrp, 1, 2))->firstOrFail();
        CutiLapangan::create(['work_location_id' => $k->work_location_id, 'hari_cuti' => 14, 'hari_berangkat' => 2, 'hari_kembali' => 2]);

        // Without a lumpsum set: the form says why.
        $this->getJson("/attendance/cuti-info/{$k->id}")->assertOk()
            ->assertJsonPath('lumpsum.nominal', null)
            ->assertJsonPath('lumpsum.poh', $poh->nama);

        $this->put('/comben/master-data/lumpsum-cuti', [
            'work_location_id' => $k->work_location_id,
            'nominal' => [$poh->id => ['Staff' => 3500000, 'Non Staff' => 2000000]],
        ])->assertSessionHasNoErrors();
        $this->get('/comben/master-data')->assertInertia(fn (Assert $page) => $page->has('lumpsum', 2)->where('klasifikasi', ['Staff', 'Non Staff']));
        $this->getJson("/attendance/cuti-info/{$k->id}")->assertJsonPath('lumpsum.nominal', 2000000)->assertJsonPath('lumpsum.klasifikasi', 'Non Staff');

        // Only a Cuti Lapangan gets it, at the amount of the day it is entered.
        $this->trip($k, ['jenis' => 'cuti', 'kategori_cuti' => 'lapangan', 'tanggal_awal' => '2026-10-10', 'tanggal_akhir' => '2026-10-27']);
        $this->trip($k, ['jenis' => 'cuti', 'kategori_cuti' => 'tahunan', 'tanggal_awal' => '2026-11-02', 'tanggal_akhir' => '2026-11-03']);
        $this->assertSame([2000000, null], Trip::orderBy('id')->pluck('lumpsum')->all());

        $k->profile->update(['klasifikasi' => 'Staff']);
        $this->trip($k, ['jenis' => 'cuti', 'kategori_cuti' => 'lapangan', 'tanggal_awal' => '2026-12-01', 'tanggal_akhir' => '2026-12-10']);
        $this->assertSame(3500000, Trip::latest('id')->value('lumpsum'));

        // Emptied: no lumpsum for the next ones; earlier ones keep theirs.
        $this->put('/comben/master-data/lumpsum-cuti', ['work_location_id' => $k->work_location_id, 'nominal' => [$poh->id => ['Staff' => null, 'Non Staff' => null]]])
            ->assertSessionHasNoErrors();
        $this->assertSame(null, Cuti::info($k->fresh())['lumpsum']['nominal']);
        $this->assertSame([2000000, null, 3500000], Trip::orderBy('id')->pluck('lumpsum')->all());
    }

    public function test_pengajuan_dinas_lists_nik_poh_and_whether_tickets_are_bought(): void
    {
        $k = $this->employee();
        $this->trip($k, [
            'jenis' => 'dinas', 'tanggal_awal' => '2026-10-12', 'tanggal_akhir' => '2026-10-14',
            'tiket_pesawat' => '1', 'bandara_asal' => 'BDJ', 'bandara_tujuan' => 'CGK',
            'pesawat_pp' => '1', 'pulang_tanggal' => '2026-10-14', 'pulang_bandara_asal' => 'CGK', 'pulang_bandara_tujuan' => 'BDJ',
        ]);
        $this->trip($k, ['jenis' => 'dinas', 'tanggal_awal' => '2026-10-20', 'tanggal_akhir' => '2026-10-20']);
        $trip = Trip::orderBy('id')->firstOrFail();

        $this->get('/attendance/pengajuan-dinas')->assertInertia(fn (Assert $page) => $page
            ->where('tiket_belum', 1)
            ->where('trips.data.1.nik', $k->profile->nik)
            ->where('trips.data.1.poh', LokasiPenerimaan::where('kode', substr($k->nrp, 1, 2))->value('nama'))
            ->where('trips.data.1.tiket_dibeli.pergi', null));

        $this->put("/attendance/trip-leave/{$trip->id}/tiket", ['arah' => 'pergi', 'dibeli' => true])->assertSessionHasNoErrors();
        $this->assertNotNull($trip->fresh()->tiket_pergi_dibeli_at);
        // Still to buy: the return flight.
        $this->get('/attendance/pengajuan-dinas?tiket=belum')->assertInertia(fn (Assert $page) => $page->has('trips.data', 1)->where('tiket_belum', 1));
        $this->put("/attendance/trip-leave/{$trip->id}/tiket", ['arah' => 'pulang', 'dibeli' => true])->assertSessionHasNoErrors();
        $this->get('/attendance/pengajuan-dinas?tiket=belum')->assertInertia(fn (Assert $page) => $page->has('trips.data', 0)->where('tiket_belum', 0));

        // A trip without a ticket has none to buy; search by NIK finds the employee.
        $other = Trip::latest('id')->firstOrFail();
        $this->put("/attendance/trip-leave/{$other->id}/tiket", ['arah' => 'pergi', 'dibeli' => true])->assertSessionHasErrors('arah');
        $this->get('/attendance/pengajuan-dinas?q='.$k->profile->nik)->assertInertia(fn (Assert $page) => $page->has('trips.data', 2));
        $this->get('/attendance/trip-leave/export')->assertOk();
    }

    public function test_import_checks_every_row_then_saves_the_valid_ones(): void
    {
        $a = $this->employee();
        $b = $this->employee('Staff', 1);
        $this->get('/attendance/pengajuan-dinas/template')->assertOk()->assertDownload('template-import-pengajuan-dinas.xlsx');

        $file = $this->xlsx([
            [$a->nrp, '', 'Dinas', '', '02.11.2026', '05.11.2026', 'Tanjung', 'Jakarta', 'Rapat', 'BDJ', 'CGK', '05.11.2026', 'CGK', 'BDJ', '', '1.500.000', 'Ya', ''],
            [$b->nrp, '', 'Cuti', 'Tahunan', '2026-11-10', '2026-11-11', 'Tanjung', 'Banjarmasin', '', '', '', '', '', '', '', '', '', ''],
            ['99999999', '', 'Dinas', '', '2026-11-10', '2026-11-11', 'Tanjung', 'Jakarta', '', '', '', '', '', '', '', '', '', ''],
            // Overlaps the first row of the same employee.
            [$a->nrp, '', 'Dinas', '', '2026-11-04', '2026-11-06', 'Tanjung', 'Balikpapan', '', '', '', '', '', '', '', '', '', ''],
        ]);

        $check = $this->post('/attendance/pengajuan-dinas/import', ['file' => $file])->assertOk()->assertJsonPath('ok', 2)->assertJsonPath('gagal', 2);
        $this->assertSame([5, 6, 7, 8], array_column($check->json('rows'), 'baris'));
        $this->assertStringContainsString('NRP 99999999 tidak ditemukan', $check->json('rows.2.pesan.0'));
        $this->assertSame(0, Trip::count()); // only checked

        $this->post('/attendance/pengajuan-dinas/import', ['file' => $this->xlsxAgain(), 'simpan' => 1])->assertSessionHasNoErrors()
            ->assertInertiaFlash('success', '2 pengajuan diimport dan Absensi ikut terisi; 2 baris dilewati.');
        $dinas = Trip::where('karyawan_id', $a->id)->firstOrFail();
        $this->assertSame(['dinas', 'disetujui', true, true, 1500000, 'BDJ'], [$dinas->jenis, $dinas->status, $dinas->pesawat_pp, $dinas->uang_muka, $dinas->uang_muka_nominal, $dinas->bandara_asal]);
        $this->assertNotNull($dinas->tiket_pergi_dibeli_at);
        $this->assertNull($dinas->tiket_pulang_dibeli_at);
        $this->assertSame(4, Absensi::where('trip_id', $dinas->id)->count());
        $this->assertSame(['tahunan', 2], [Trip::where('karyawan_id', $b->id)->value('kategori_cuti'), Trip::where('karyawan_id', $b->id)->value('potong_tahunan')]);
    }

    public function test_the_portal_card_lists_the_active_annual_periods(): void
    {
        $t = Cuti::tahunan(CarbonImmutable::parse('2023-01-10'), [2025 => 5, 2026 => 2], 0, CarbonImmutable::parse('2026-07-08'));
        // Quotas live two years: 2024 (paid the 5 days of 2025) is gone; 2025 paid the 2 days of 2026.
        $this->assertSame([
            ['tahun' => 2025, 'mulai' => '2025-01-10', 'berlaku_sampai' => '2026-12-31', 'jatah' => 12, 'terpakai' => 2, 'sisa' => 10],
            ['tahun' => 2026, 'mulai' => '2026-01-10', 'berlaku_sampai' => '2027-12-31', 'jatah' => 12, 'terpakai' => 0, 'sisa' => 12],
        ], $t['periode']);
        $this->assertSame(['2027-01-10', 12, 22], [$t['hak_berikutnya'], $t['jatah_berikutnya'], $t['saldo']]);
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
        $ws->fromArray(array_keys(TripImport::COLUMNS), null, 'A4');
        $ws->fromArray($this->rows, null, 'A5');
        foreach ($this->rows as $i => $row) {
            $ws->setCellValueExplicit('A'.($i + 5), $row[0], DataType::TYPE_STRING);
        }
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
