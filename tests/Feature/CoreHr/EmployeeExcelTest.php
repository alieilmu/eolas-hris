<?php

namespace Tests\Feature\CoreHr;

use App\Models\Employee;
use App\Models\User;
use App\Support\Employees\ExcelSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class EmployeeExcelTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/core-hr/employees/excel';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::where('role', User::ROLE_HR)->firstOrFail());
    }

    // --- helpers -------------------------------------------------------------------

    /** Build an .xlsx upload. Strings are stored as text cells, PHP numbers as numbers (e.g. date serials). */
    private function xlsx(array $headers, array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle(ExcelSchema::DATA_SHEET);

        foreach ([$headers, ...$rows] as $r => $values) {
            foreach ($values as $c => $value) {
                if ($value !== null) {
                    $sheet->setCellValueExplicit(
                        Coordinate::stringFromColumnIndex($c + 1).($r + 1),
                        $value,
                        is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING,
                    );
                }
            }
        }

        return $this->upload($book);
    }

    private function upload(Spreadsheet $book): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'import.xlsx', null, null, true);
    }

    private function import(UploadedFile $file, bool $dryRun = false, bool $updateOnly = false): TestResponse
    {
        return $this->post(self::BASE.'/import', [
            'file' => $file,
            'dry_run' => $dryRun ? '1' : '0',
            'update_only' => $updateOnly ? '1' : '0',
        ], ['Accept' => 'application/json']);
    }

    private function downloaded(TestResponse $response): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'dl').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        return IOFactory::load($path);
    }

    /** Column titles: row 2, under the group bands. */
    private function titles(Worksheet $sheet): array
    {
        return $sheet->rangeToArray('A2:'.$sheet->getHighestColumn().'2')[0];
    }

    /** The placement of an operator at the Murung Raya site: PRODUCTION OPERATOR in Section OPERASI TAMBANG. */
    private const OPERATOR = [
        'Work Location' => 'Site Murung Raya', 'SubGroup' => 'Operator', 'Section' => 'OPERASI TAMBANG', 'Nama Jabatan (Bidang)' => 'PRODUCTION OPERATOR',
    ];

    /** A new-employee row with every required column filled; $extra adds or overrides by header. */
    private function new(string $employee_number, string $name, array $extra = []): array
    {
        return [
            'No. Urut' => 99, 'NRP' => $employee_number, 'Nama' => $name, 'NRP SAP' => '7'.substr($employee_number, 1), ...self::OPERATOR,
            'Klasifikasi' => 'Non Staff', 'Klasifikasi Teknis' => 'Staff/Operator',
            'Tempat Lahir' => 'Balikpapan', 'Tanggal Lahir' => '1995-01-31', 'Tanggal Masuk' => '2026-01-05',
            ...$extra,
        ];
    }

    /** Rows keyed by header; the header row is the union of keys in first-seen order. */
    private function xlsxRows(array $rows): UploadedFile
    {
        $headers = array_values(array_unique(array_merge(...array_map('array_keys', $rows))));

        return $this->xlsx($headers, array_map(fn (array $r) => array_map(fn ($h) => $r[$h] ?? null, $headers), $rows));
    }

    private function rowFor(TestResponse $response, string $employee_number): array
    {
        return collect($response->json('rows'))->firstWhere('employee_number', $employee_number) ?? $this->fail("No result row for NRP {$employee_number}");
    }

    // --- downloads -----------------------------------------------------------------

    public function test_template_has_only_fillable_columns_and_guidance_sheets(): void
    {
        $book = $this->downloaded($this->get(self::BASE.'/template')->assertOk());

        $this->assertSame([ExcelSchema::DATA_SHEET, 'Petunjuk', 'Referensi'], $book->getSheetNames());

        $sheet = $book->getSheet(0);
        $headers = $this->titles($sheet);
        // Every fillable field; the position title is composed from the placement, so it is not one of them.
        $this->assertCount(120, $headers); // Plafon Pengobatan is computed (Modul Comben), not filled in.
        $this->assertNotContains('Origin Status', $headers);
        $this->assertNotContains(ExcelSchema::POSITION_LABEL, $headers);
        $this->assertContains('Job Level *', $headers);
        $this->assertContains('Section', $headers);
        $this->assertContains('Discipline (Position Name)', $headers);
        $this->assertNotContains('Kode Jabatan', $headers);
        $this->assertNotContains('Age', $headers);
        $this->assertContains('Residential RT', $headers);
        $this->assertContains('Birth Date *', $headers); // the employee's own (required)
        $this->assertContains('Spouse Birth Date', $headers);
        $this->assertCount(count($headers), array_unique($headers), 'headers must be unique');

        // Required columns are exactly groups 1 and 2, minus Status Karyawan (defaults to Aktif).
        $this->assertSame(
            ['Sequence No. *', 'Employee Number *', 'Name *', 'SAP Employee Number *', 'Work Location *', 'Job Level *', 'Employee Class *', 'Technical Class *', 'Birth Place *', 'Birth Date *', 'Hire Date *'],
            array_values(array_filter($headers, fn ($h) => str_ends_with($h, ' *'))),
        );

        // Laid out like the website table: group bands on row 1, NRP/Nama frozen.
        $this->assertSame('1. IDENTITY & JOB', $sheet->getCell('A1')->getValue());
        $this->assertContains('A1:N1', array_keys($sheet->getMergeCells()), 'group 1 spans its 14 fillable columns');
        $this->assertSame('2. BIRTH & SERVICE', $sheet->getCell('O1')->getValue());
        $this->assertSame('D3', $sheet->getFreezePane());

        // The Referensi sheet explains the SubGroups' naming rules and lists sections, bidang and positions.
        $reference = implode(' ', array_merge(...$book->getSheetByName('Referensi')->toArray()));
        $this->assertStringContainsString('Nama Jabatan (Section opsional)', $reference);
        $this->assertStringContainsString('PRODUCTION OPERATOR', $reference);
        $this->assertStringContainsString('MANAGER HRGA', $reference);
    }

    public function test_export_can_be_imported_back_without_changes(): void
    {
        $file = $this->downloaded($this->get(self::BASE.'/export')->assertOk());

        $sheet = $file->getSheet(0);
        $this->assertSame(16, $sheet->getHighestDataRow(), '2 header rows + 14 active employees');
        $this->assertContains('Age'.ExcelSchema::AUTO_SUFFIX, $this->titles($sheet));
        $this->assertSame(Employee::active()->orderBy('employee_number')->value('employee_number'), $sheet->getCell('B3')->getValue(), 'data starts on row 3');
        $this->assertContains('Origin Status'.ExcelSchema::AUTO_SUFFIX, $this->titles($sheet));

        $response = $this->import($this->upload($file), dryRun: true)->assertOk();

        $this->assertSame(0, $response->json('created'));
        $this->assertSame(0, $response->json('updated'), json_encode(collect($response->json('rows'))->where('action', 'update')->pluck('changes')->all()));
        $this->assertSame(14, $response->json('unchanged'));
        $this->assertSame([], $response->json('skipped'));
        $this->assertSame([], $response->json('unknown_columns'));
    }

    public function test_export_respects_the_search_filter(): void
    {
        $sheet = $this->downloaded($this->get(self::BASE.'/export?q=operator')->assertOk())->getSheet(0);

        $this->assertSame(6, $sheet->getHighestDataRow(), '2 header rows + 4 active PRODUCTION OPERATOR');
    }

    public function test_group_template_contains_only_the_chosen_groups_and_can_be_prefilled(): void
    {
        $sheet = $this->downloaded(
            $this->get(self::BASE.'/group-template?groups[]=spouse&prefill=1')->assertOk()
        )->getSheet(0);

        $this->assertSame(
            ['Employee Number *', 'Name *', 'Spouse Name', 'Spouse Birth Date', 'Spouse Occupation'],
            $this->titles($sheet),
        );
        $this->assertSame(['1. IDENTITY & JOB', null, '16. SPOUSE'], $sheet->rangeToArray('A1:C1')[0]);
        $this->assertSame(16, $sheet->getHighestDataRow());
        $this->assertSame('E', $sheet->getHighestDataColumn(), 'no columns from other groups');
        $this->assertSame('C3', $sheet->getFreezePane());

        $this->get(self::BASE.'/group-template?groups[]=bukan-kelompok')->assertSessionHasErrors('groups.0');
    }

    // --- import --------------------------------------------------------------------

    public function test_preview_saves_nothing_and_confirm_saves_the_same_result(): void
    {
        $file = fn () => $this->xlsxRows([$this->new('10124200', 'Karyawan Import', ['NIK (KTP)' => '6471013101950099'])]);

        $preview = $this->import($file(), dryRun: true)->assertOk();
        $this->assertTrue($preview->json('dry_run'));
        $this->assertSame(1, $preview->json('created'));
        $this->assertDatabaseMissing('employees', ['employee_number' => '10124200']);

        $saved = $this->import($file())->assertOk();
        $this->assertSame(1, $saved->json('created'));
        $this->assertSame($preview->json('rows.0.completeness_after'), $saved->json('rows.0.completeness_after'));

        $k = Employee::where('employee_number', '10124200')->with('profile')->firstOrFail();
        $this->assertSame(Employee::STATUS_ACTIVE, $k->status);
        $this->assertSame('1995-01-31', $k->profile->birth_date->toDateString());
        $this->assertSame('6471013101950099', $k->profile->national_id_number);
    }

    public function test_partial_update_changes_only_the_columns_in_the_file(): void
    {
        $lina = Employee::where('name', 'Lina Agustina')->with('profile')->firstOrFail();
        $before = $lina->profile->only(['religion', 'phone', 'grade']);

        $response = $this->import($this->xlsx(
            ['NRP', 'Nama', 'NIK (KTP)', 'Agama'],
            // Agama blank: must not wipe the stored value.
            [[$lina->employee_number, 'Lina Agustina', '6471015708020013', null]],
        ))->assertOk();

        $row = $this->rowFor($response, $lina->employee_number);
        $this->assertSame('update', $row['action']);
        $this->assertSame([['National ID Number', null, '6471015708020013']], array_map(
            fn ($c) => [$c['label'], $c['before'], $c['after']], $row['changes'],
        ));
        $this->assertGreaterThan($row['completeness_before'], $row['completeness_after']);

        $lina->profile->refresh();
        $this->assertSame('6471015708020013', $lina->profile->national_id_number);
        $this->assertSame($before, $lina->profile->only(['religion', 'phone', 'grade']));
    }

    public function test_dates_are_read_from_excel_serials_and_day_first_text(): void
    {
        $maya = Employee::where('name', 'Maya Sari')->firstOrFail();
        // 33239 is 1991-01-01 as an Excel date serial.
        $this->import($this->xlsx(
            ['NRP', 'Tanggal Masuk', 'Keluarga Inti — Tanggal Lahir'],
            [[$maya->employee_number, 33239, '5/3/1999']],
        ))->assertOk();

        $profile = $maya->profile()->first();
        $this->assertSame('1991-01-01', $profile->hire_date->toDateString());
        $this->assertSame('1999-03-05', $profile->spouse_birth_date->toDateString());
    }

    public function test_pkwt_and_children_blocks_replace_the_stored_list(): void
    {
        $rudi = Employee::where('name', 'Rudi Hartono')->firstOrFail(); // 3 PKWT periods, 2 children

        $response = $this->import($this->xlsx(
            ['NRP', 'PKWT I — Tgl Kontrak', 'PKWT I — Tgl Berakhir', 'Anak 1 — Nama', 'Anak 2 — Nama', 'Anak 3 — Nama'],
            [[$rudi->employee_number, '2024-01-01', '2024-12-31', 'Rafi Hartono', 'Salsa Hartono', 'Anak Ketiga']],
        ))->assertOk();

        $row = $this->rowFor($response, $rudi->employee_number);
        $this->assertContains('Jumlah periode PKWT berkurang dari 3 menjadi 1 — pastikan file memuat SEMUA periode.', $row['warnings']);

        $profile = $rudi->profile()->with('contracts', 'children')->first();
        $this->assertCount(1, $profile->contracts);
        $this->assertSame(['Rafi Hartono', 'Salsa Hartono', 'Anak Ketiga'], $profile->children->pluck('name')->all());
    }

    public function test_invalid_rows_are_skipped_with_a_reason_while_others_go_through(): void
    {
        $siti = Employee::where('name', 'Siti Rahmawati')->with('profile')->firstOrFail();

        $response = $this->import($this->xlsxRows([
            $this->new('', 'Tanpa NRP'),
            $this->new('10124201', 'Jabatan Salah', ['Nama Jabatan (Bidang)' => 'BIDANG TIDAK ADA']),
            $this->new('10124202', 'NIK Kembar', ['NIK (KTP)' => $siti->profile->national_id_number]),
            $this->new('10124203', 'NIK Pendek', ['NIK (KTP)' => '12345']),
            $this->new('10124204', 'Tanggal Salah', ['Tanggal Lahir' => '31-31-2020']),
            $this->new('10124205', 'Tanpa Nama Jabatan', ['Nama Jabatan (Bidang)' => null]),
            $this->new('10124206', 'Lokasi Salah', ['Work Location' => 'Site Barabai']),
            $this->new('10124207', 'Baris Benar'),
            $this->new('10124207', 'NRP Dobel'),
        ]))->assertOk();

        $reasons = collect($response->json('skipped'))->pluck('reason', 'row');
        $this->assertStringContainsString('wajib diisi', $reasons[2]);
        $this->assertStringContainsString('tidak ada di master Nama Jabatan', $reasons[3]);
        $this->assertStringContainsString('sudah dipakai karyawan "Siti Rahmawati"', $reasons[4]);
        $this->assertSame('NIK harus 16 digit angka.', $reasons[5]);
        $this->assertStringContainsString('bukan tanggal yang valid', $reasons[6]);
        $this->assertStringContainsString('Pilih Nama Jabatan', $reasons[7]);
        $this->assertStringContainsString('Work Location "Site Barabai" tidak ada', $reasons[8]);
        $this->assertStringContainsString('lebih dari sekali', $reasons[10]);

        $this->assertSame(1, $response->json('created'));
        $this->assertDatabaseHas('employees', ['employee_number' => '10124207']);
    }

    public function test_an_import_can_put_several_people_on_the_same_position(): void
    {
        $manager = ['Work Location' => 'Head Office', 'SubGroup' => 'Manager', 'Departemen' => 'HRGA', 'Section' => null, 'Nama Jabatan (Bidang)' => null];

        // MANAGER HRGA (SubGroup layer 4, normally 1 person) is held by Siti; two more join it from one file.
        $response = $this->import($this->xlsxRows([
            $this->new('10124211', 'Manajer Dua', $manager),
            $this->new('10124212', 'Manajer Tiga', $manager),
            // Shared positions (layer 1–2) never needed a note.
            $this->new('10124213', 'Staf Satu'),
            $this->new('10124214', 'Staf Dua'),
        ]))->assertOk();

        $this->assertSame(4, $response->json('created'));
        $this->assertSame([], $response->json('skipped'));
        $this->assertSame(3, Employee::active()->where('position_id', $this->seededPosition('KDP-HRG')->id)->count());
        $this->assertContains('Jabatan "MANAGER HRGA" sudah diisi Siti Rahmawati — karyawan ini tetap diimpor, jadi jabatan ini diisi 2 orang.', $this->rowFor($response, '10124211')['warnings']);
        $this->assertContains('Jabatan "MANAGER HRGA" sudah diisi Manajer Dua dan Siti Rahmawati — karyawan ini tetap diimpor, jadi jabatan ini diisi 3 orang.', $this->rowFor($response, '10124212')['warnings']);
        $this->assertSame([], array_filter($this->rowFor($response, '10124214')['warnings'], fn ($w) => str_contains($w, 'sudah diisi')));
    }

    public function test_new_employees_must_fill_every_required_column_of_groups_1_and_2(): void
    {
        $response = $this->import($this->xlsxRows([
            ['NRP' => '10124210', 'Nama' => 'Data Minim', ...self::OPERATOR, 'Tanggal Lahir' => '1990-01-01'],
        ]))->assertOk();

        $this->assertSame(
            'Kolom wajib untuk karyawan baru belum diisi: Sequence No., SAP Employee Number, Employee Class, Technical Class, Birth Place, Hire Date.',
            $response->json('skipped.0.reason'),
        );
        $this->assertDatabaseMissing('employees', ['employee_number' => '10124210']);
    }

    public function test_required_columns_of_existing_employees_may_come_from_stored_data(): void
    {
        $lina = Employee::where('name', 'Lina Agustina')->with('profile')->firstOrFail();
        $partial = fn (array $extra = []) => $this->xlsxRows([['NRP' => $lina->employee_number, 'Nomor HP' => '081311112222', ...$extra]]);

        // Everything required is already stored: a one-column update is fine.
        $this->assertSame(1, $this->import($partial(), dryRun: true)->json('updated'));

        // Once a required value is missing from the record, the file has to supply it.
        $lina->profile->update(['sap_employee_number' => null, 'hire_date' => null]);
        $this->assertSame(
            'Kolom wajib belum terisi (di file maupun di data tersimpan): SAP Employee Number, Hire Date.',
            $this->import($partial(), dryRun: true)->json('skipped.0.reason'),
        );
        $this->assertSame(1, $this->import($partial(['NRP SAP' => '70124135', 'Tanggal Masuk' => '2026-07-27']), dryRun: true)->json('updated'));
    }

    public function test_the_downloaded_template_imports_as_is(): void
    {
        $template = $this->downloaded($this->get(self::BASE.'/template')->assertOk());

        $response = $this->import($this->upload($template), dryRun: true)->assertOk();

        $this->assertSame([], $response->json('skipped'));
        $this->assertSame([], $response->json('unknown_columns'));
        $this->assertSame(2, $response->json('created'), 'both example rows are complete');
        $this->assertSame([3, 4], array_column($response->json('rows'), 'row'), 'rows are numbered as in Excel');
    }

    public function test_exited_employees_are_updated_but_never_reactivated(): void
    {
        $wahyu = Employee::where('name', 'Wahyu Kurniawan')->firstOrFail();

        $response = $this->import($this->xlsx(
            ['NRP', 'Status Karyawan', 'Nomor HP'],
            [[$wahyu->employee_number, 'Aktif', '081299990000']],
        ))->assertOk();

        $this->assertStringContainsString('tidak diaktifkan kembali', $this->rowFor($response, $wahyu->employee_number)['warnings'][0]);
        $this->assertSame(Employee::STATUS_TERMINATED, $wahyu->fresh()->status);
        // The old Status Karyawan column (now SubGroup) is reported and ignored.
        $this->assertSame(['Status Karyawan'], $response->json('unknown_columns'));
        $this->assertSame('081299990000', $wahyu->profile()->value('phone'));
    }

    public function test_update_only_mode_skips_unknown_nrp(): void
    {
        $response = $this->import($this->xlsx(
            ['NRP', 'Nama', 'SubGroup'],
            [['10124299', 'Salah Ketik', 'Operator']],
        ), updateOnly: true)->assertOk();

        $this->assertStringContainsString('hanya perbarui', $response->json('skipped.0.reason'));
        $this->assertDatabaseMissing('employees', ['employee_number' => '10124299']);
    }

    public function test_unknown_headers_are_reported(): void
    {
        $response = $this->import($this->xlsx(
            ['NRP', 'Agamaa', 'RT'],
            [[Employee::value('employee_number'), 'Islam', '001']],
        ), dryRun: true)->assertOk();

        $this->assertSame(['Agamaa', 'RT'], $response->json('unknown_columns'));
    }

    public function test_bad_uploads_get_a_readable_message(): void
    {
        $this->import(UploadedFile::fake()->create('data.pdf', 10))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'File harus berformat .xlsx, .xls, atau .csv.']);

        $this->import($this->xlsx(['NRP', 'Nama'], []))
            ->assertUnprocessable()
            ->assertJson(['message' => 'File tidak berisi baris data.']);
    }

    public function test_viewers_cannot_use_any_excel_endpoint(): void
    {
        $this->actingAs(User::where('email', 'viewer@eolas.local')->firstOrFail());

        $this->get(self::BASE.'/template')->assertForbidden();
        $this->get(self::BASE.'/export')->assertForbidden();
        $this->get(self::BASE.'/group-template?groups[]=children')->assertForbidden();
        $this->import($this->xlsx(['NRP'], [['1']]))->assertForbidden();
    }
}
