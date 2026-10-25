<?php

namespace App\Support\Employees;

use App\Models\Position;
use App\Models\PointOfHire;
use App\Models\Section;
use App\Models\JobLevel;
use App\Models\WorkLocation;
use App\Support\Organization\PositionComposer;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Builds the template, export and per-group update workbooks. */
class ExcelWorkbook
{
    /** Row 1: group bands, row 2: column titles. */
    public const FIRST_DATA_ROW = 3;

    private const AUTO_BAND = 'Auto — ignored on import';

    /** The website's palette (Tailwind slate / brand) so the file reads like the Data Lengkap table. */
    private const COLOR = [
        'band' => '334155',          // slate-700
        'band_alt' => '475569',      // slate-600
        'auto_band' => '94A3B8',     // slate-400
        'header_fill' => 'F1F5F9',   // slate-100
        'header_text' => '64748B',   // slate-500
        'required_fill' => 'E0E7FF', // brand-100
        'required_text' => '4338CA', // brand-700
        'auto_fill' => 'E2E8F0',     // slate-200
        'auto_text' => '94A3B8',     // slate-400
    ];

    /**
     * Blank template: headers plus two illustrative rows, with guidance sheets.
     * Columns the system fills in itself are left out — nothing to type there.
     */
    public static function template(): Spreadsheet
    {
        $columns = ExcelSchema::writable();
        // A placement whose position can take several people (Nama Jabatan), so the examples import cleanly.
        $sampleSubGroup = JobLevel::where('source', 'discipline')->where('layer', '<', JobLevel::SINGLE_HOLDER_MIN_LAYER)->orderBy('layer')->first();
        $sampleSection = Section::with('department')->orderBy('department_id')->orderBy('sort_order')->first();
        $sample = [
            'job_level' => $sampleSubGroup?->name,
            'department_name' => $sampleSection?->department->name,
            'section_name' => $sampleSection?->name,
            'discipline' => LookupValues::values(PositionComposer::BIDANG)[0] ?? null,
        ];

        // Example NRPs follow the J LL YY NNN pattern with the first registered codes,
        // the year of their Tanggal Masuk, and the next free number.
        $type = array_key_first(EmployeeNumber::categoryMap()) ?? '1';
        $location = array_key_first(EmployeeNumber::pointOfHireMap()) ?? '01';
        $employee_number = fn (int $year, string $fallback) => EmployeeNumber::next((string) $type, (string) $location, $year) ?? $fallback;
        $option = fn (string $category, int $index) => LookupValues::values($category)[$index] ?? null;
        $sampleLokasi = WorkLocation::orderBy('sort_order')->value('name');

        // Both examples fill every required column (groups 1 and 2).
        $examples = [
            [
                'sequence_no' => 1, 'employee_number' => $employee_number(2020, '10120001'), 'name' => 'Contoh Karyawan Baru', 'sap_employee_number' => '70000001',
                ...$sample, 'work_location_name' => $sampleLokasi, 'employee_class' => $option('employee_class', 1), 'technical_class' => $option('technical_class', 0),
                'status' => 'Aktif', 'birth_place' => 'Bandung', 'birth_date' => '1992-04-10', 'hire_date' => '2020-02-01',
                'gender' => 'Laki-laki', 'religion' => $option('religion', 0), 'national_id_number' => '3273011004920001', 'education_level' => 'S1',
                'contract_1_start_date' => '2020-02-01', 'contract_1_end_date' => '2021-01-31',
                'child_1_name' => 'Nama Anak Pertama', 'child_1_birth_date' => '2018-07-12', 'child_1_education_level' => 'TK',
            ],
            [
                'sequence_no' => 2, 'employee_number' => $employee_number(2024, '10124001'), 'name' => 'Contoh Isi Wajib Saja', 'sap_employee_number' => '70000002',
                ...$sample, 'work_location_name' => $sampleLokasi, 'employee_class' => $option('employee_class', 0), 'technical_class' => $option('technical_class', 0),
                'birth_place' => 'Balikpapan', 'birth_date' => '1998-11-23', 'hire_date' => '2024-06-03',
            ],
        ];

        return self::book($columns, $examples, ExcelSchema::importInstructions());
    }

    /**
     * Current data. The fillable columns use the template's headers so the file
     * can be edited and imported straight back; the system-calculated columns
     * follow at the far right, marked "(otomatis)", as information only.
     *
     * @param  Collection<int, array>  $rows  KaryawanRow::flatten() rows
     */
    public static function export(Collection $rows, int $anakCount): Spreadsheet
    {
        $all = ExcelSchema::columns($anakCount);
        $columns = [
            ...array_filter($all, fn (array $c) => ! $c['read_only']),
            ...array_map(
                fn (array $c) => [...$c, 'label' => $c['label'].ExcelSchema::AUTO_SUFFIX],
                array_filter($all, fn (array $c) => $c['read_only']),
            ),
        ];

        return self::book(array_values($columns), $rows->all(), ExcelSchema::importInstructions());
    }

    /**
     * Only NRP, Nama and the chosen groups — for updating one part of the data,
     * e.g. family details. With $rows it starts pre-filled with current values.
     *
     * @param  list<string>  $groupIds
     */
    public static function groupTemplate(array $groupIds, int $anakCount, ?Collection $rows): Spreadsheet
    {
        $all = ExcelSchema::writable($anakCount);
        $identity = array_filter($all, fn (array $c) => in_array($c['key'], ['employee_number', 'name'], true));
        $chosen = array_filter($all, fn (array $c) => in_array($c['group_id'], $groupIds, true) && ! in_array($c['key'], ['employee_number', 'name'], true));

        return self::book([...$identity, ...$chosen], $rows?->all() ?? [], ExcelSchema::groupUpdateInstructions());
    }

    public static function download(Spreadsheet $book, string $filename): StreamedResponse
    {
        return response()->streamDownload(
            fn () => (new Xlsx($book))->save('php://output'),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    // --- sheets ------------------------------------------------------------------

    /** @param  list<array>  $columns */
    private static function book(array $columns, array $rows, array $instructions): Spreadsheet
    {
        $book = new Spreadsheet;
        self::dataSheet($book->getActiveSheet(), array_values($columns), $rows);
        self::instructionsSheet($book->createSheet(), $instructions);
        self::referenceSheet($book->createSheet());
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * Laid out like the Data Lengkap table on the website: row 1 carries the
     * group bands, row 2 the column titles, data starts on row 3, and the columns
     * up to Nama stay frozen like the sticky NRP / Nama columns.
     */
    private static function dataSheet(Worksheet $sheet, array $columns, array $rows): void
    {
        $sheet->setTitle(ExcelSchema::DATA_SHEET);
        $groupTitle = ExcelSchema::GROUP_TITLES;

        // --- row 1: group bands, merged across each group's columns ------------
        $bands = [];
        foreach ($columns as $i => $col) {
            $title = $col['read_only'] ? self::AUTO_BAND : $groupTitle[$col['group_id']];
            if ($bands && end($bands)['title'] === $title) {
                $bands[array_key_last($bands)]['to'] = $i;
            } else {
                $bands[] = ['title' => $title, 'from' => $i, 'to' => $i];
            }
        }
        foreach ($bands as $b => $band) {
            $from = Coordinate::stringFromColumnIndex($band['from'] + 1);
            $to = Coordinate::stringFromColumnIndex($band['to'] + 1);
            $sheet->setCellValueExplicit("{$from}1", mb_strtoupper($band['title']), DataType::TYPE_STRING);
            if ($from !== $to) {
                $sheet->mergeCells("{$from}1:{$to}1");
            }
            $style = $sheet->getStyle("{$from}1:{$to}1");
            $style->getFont()->setBold(true)->setSize(9)->getColor()->setRGB('FFFFFF');
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(
                $band['title'] === self::AUTO_BAND ? self::COLOR['auto_band'] : ($b % 2 === 0 ? self::COLOR['band'] : self::COLOR['band_alt'])
            );
            $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $style->getBorders()->getRight()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('FFFFFF');
        }

        // --- row 2: column titles, row 3+: data ------------------------------------
        foreach ($columns as $i => $col) {
            $letter = Coordinate::stringFromColumnIndex($i + 1);
            $text = ExcelSchema::headerText($col);
            $required = $text !== $col['label'];

            $sheet->setCellValueExplicit("{$letter}2", $text, DataType::TYPE_STRING);
            $sheet->getColumnDimension($letter)->setWidth(min(40, max(12, mb_strlen($text) * 1.1 + 2)));

            [$fill, $font] = match (true) {
                $required => [self::COLOR['required_fill'], self::COLOR['required_text']],
                $col['read_only'] => [self::COLOR['auto_fill'], self::COLOR['auto_text']],
                default => [self::COLOR['header_fill'], self::COLOR['header_text']],
            };
            $header = $sheet->getStyle("{$letter}2");
            $header->getFont()->setBold(true)->setItalic($col['read_only'])->getColor()->setRGB($font);
            $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
            $header->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');

            if (in_array($col['key'], ExcelSchema::TEXT_NUMBER_KEYS, true)) {
                // Whole-column format: new rows typed in Excel keep leading zeros too.
                $sheet->getStyle("{$letter}:{$letter}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }

            foreach ($rows as $r => $row) {
                $value = $row[$col['key']] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $cell = $letter.($r + self::FIRST_DATA_ROW);

                match (true) {
                    $col['type'] === 'boolean' => $sheet->setCellValueExplicit($cell, $value ? 'Ya' : 'Tidak', DataType::TYPE_STRING),
                    $col['type'] === 'number' && is_numeric($value) => $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_NUMERIC),
                    // Dates as text in the app's format (31.12.2026), so no locale can reinterpret them.
                    $col['type'] === 'date' && is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) => $sheet->setCellValueExplicit($cell, "{$m[3]}.{$m[2]}.{$m[1]}", DataType::TYPE_STRING),
                    // Everything else as text: numbers keep leading zeros.
                    default => $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING),
                };
            }
        }

        $sheet->getRowDimension(1)->setRowHeight(22);
        $sheet->getRowDimension(2)->setRowHeight(20);

        // Freeze the header rows and every column up to and including Nama.
        $nameIndex = array_search('name', array_column($columns, 'key'), true);
        $sheet->freezePane(Coordinate::stringFromColumnIndex(($nameIndex === false ? 0 : $nameIndex + 1) + 1).self::FIRST_DATA_ROW);
    }

    private static function instructionsSheet(Worksheet $sheet, array $lines): void
    {
        $sheet->setTitle('Petunjuk');
        $sheet->getColumnDimension('A')->setWidth(110);
        foreach ($lines as $i => $line) {
            $sheet->setCellValueExplicit('A'.($i + 1), $line, DataType::TYPE_STRING);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
    }

    private static function referenceSheet(Worksheet $sheet): void
    {
        $sheet->setTitle('Referensi');
        foreach (['A' => 34, 'B' => 36, 'C' => 26, 'D' => 26, 'E' => 22, 'F' => 22] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $rows = [
            ['JOB LEVEL — menentukan kolom penempatan yang diisi dan susunan nama Jabatan'],
            [],
            ['Job Level', 'Layer', 'Isi kolom', 'Gelar', 'Gelar di Head Office'],
        ];
        $headerRows = [1, 3];
        foreach (JobLevel::orderByDesc('layer')->orderBy('name')->get() as $g) {
            $rows[] = [$g->name, (string) $g->layer, JobLevel::SUMBER[$g->source] ?? $g->source, $g->title_prefix ?? '-', $g->title_prefix_head_office ?? '-'];
        }

        $rows[] = [];
        $rows[] = ['SECTION — dan departemennya'];
        $headerRows[] = count($rows);
        foreach (Section::with('department.division')->orderBy('department_id')->orderBy('sort_order')->get() as $s) {
            $rows[] = [$s->name, $s->department->name, $s->department->division->name];
        }

        $rows[] = [];
        $rows[] = ['NAMA JABATAN (BIDANG) — untuk kolom "Nama Jabatan (Bidang)"'];
        $headerRows[] = count($rows);
        foreach (LookupValues::values(PositionComposer::BIDANG) as $discipline) {
            $rows[] = [$discipline];
        }

        $rows[] = [];
        $rows[] = ['JABATAN YANG SUDAH ADA'];
        $headerRows[] = count($rows);
        foreach (Position::with(['jobLevel', 'workLocation', ...Position::UNIT_RELATIONS])->orderBy('sort_order')->get() as $j) {
            $rows[] = [$j->name, $j->workLocation?->name ?? '-', $j->jobLevel?->name ?? '-', $j->unitLabel()];
        }

        $rows[] = [];
        $rows[] = ['KODE JENIS KARYAWAN (bagian NRP)'];
        $headerRows[] = count($rows);
        foreach (EmployeeNumber::categoryMap() as $code => $name) {
            $rows[] = [(string) $code, $name];
        }

        $rows[] = [];
        $rows[] = ['KODE LOKASI PENERIMAAN (bagian NRP)'];
        $headerRows[] = count($rows);
        foreach (PointOfHire::with('workLocation')->orderBy('code')->get() as $location) {
            $rows[] = [$location->code, $location->name, $location->workLocation?->name ?? '(belum dipetakan ke lokasi kerja)'];
        }

        $rows[] = [];
        $rows[] = ['PILIHAN NILAI YANG VALID'];
        $headerRows[] = count($rows);
        $rows[] = [];
        foreach (ExcelSchema::referenceEnums() as $title => $values) {
            $rows[] = [$title, ...$values];
        }

        foreach ($rows as $r => $values) {
            foreach ($values as $c => $value) {
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($c + 1).($r + 1), $value, DataType::TYPE_STRING);
            }
        }
        foreach ($headerRows as $r) {
            $sheet->getStyle("A{$r}:E{$r}")->getFont()->setBold(true);
        }
    }
}
