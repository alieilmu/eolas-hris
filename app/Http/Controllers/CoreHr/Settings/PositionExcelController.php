<?php

namespace App\Http\Controllers\CoreHr\Settings;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Models\Section;
use App\Models\JobLevel;
use App\Models\WorkLocation;
use App\Support\Employees\ExcelWorkbook;
use App\Support\Employees\LookupValues;
use App\Support\Organization\PositionComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk-creates positions (e.g. vacancies before anyone is hired) from Excel.
 * Each row is a placement — Work Location + SubGroup + the unit or bidang the
 * SubGroup names its positions after — and the name is composed from it, just
 * like when an employee is placed. A file is previewed first (nothing saved),
 * then only its ready rows are created; failed rows can be downloaded, laid out
 * like the template with their reason, to fix and import again.
 */
class PositionExcelController extends Controller
{
    /** Header => JabatanComposer::fromNames() key. */
    private const COLUMNS = [
        'Work Location *' => 'work_location',
        'Job Level *' => 'job_level',
        'Directorate' => 'directorate',
        'Division' => 'division',
        'Department' => 'department',
        'Section' => 'section',
        'Discipline (Position Name)' => 'discipline',
    ];

    /** Headers of files made before the headers were in English. */
    private const LEGACY_COLUMNS = [
        'SubGroup *' => 'job_level', 'BOD' => 'directorate', 'Divisi' => 'division', 'Departemen' => 'department', 'Nama Jabatan (Bidang)' => 'discipline',
    ];

    private const MAX_ROWS = 2000;

    /** Extra columns of the file of failed rows; not read back on import. */
    private const REASON_HEADER = 'Error Reason';

    private const SOURCE_ROW_HEADER = 'Source Row';

    public function template(): StreamedResponse
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Jabatan');
        $sheet->fromArray(array_keys(self::COLUMNS), null, 'A1');

        $location = WorkLocation::orderBy('sort_order')->value('name');
        $section = Section::with('department')->orderBy('department_id')->orderBy('sort_order')->first();
        $examples = array_values(array_filter([
            ($g = JobLevel::where('source', 'section')->first()) && $section
                ? [$location, $g->name, '', '', $section->department->name, $section->name, ''] : null,
            ($g = JobLevel::where('source', 'discipline')->orderBy('layer')->first()) && $section
                ? [$location, $g->name, '', '', $section->department->name, $section->name, LookupValues::values(PositionComposer::BIDANG)[0] ?? ''] : null,
        ]));
        if ($examples) {
            $sheet->fromArray($examples, null, 'A2');
        }
        $this->style($sheet, count(self::COLUMNS));

        $this->instructionSheet($book->createSheet()->setTitle('Petunjuk'));
        $this->referenceSheet($book->createSheet()->setTitle('Referensi'));
        $book->setActiveSheetIndex(0);

        return ExcelWorkbook::download($book, 'template-import-position.xlsx');
    }

    /** Reads the file and shows, row by row, what an import would do. Nothing is saved. */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:xlsx,xls,csv'],
        ], [
            'file.required' => 'Pilih file Excel terlebih dahulu.',
            'file.extensions' => 'File harus berformat .xlsx, .xls, atau .csv.',
            'file.max' => 'Ukuran file maksimal 5 MB.',
        ]);

        try {
            $rows = $this->read($request->file('file')->getRealPath(), $request->file('file')->getClientOriginalExtension());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'File tidak bisa dibaca. Pastikan formatnya .xlsx dan tidak rusak.'], 422);
        }

        if ($rows === []) {
            return response()->json(['message' => 'File tidak berisi baris data.'], 422);
        }
        if (count($rows) > self::MAX_ROWS) {
            return response()->json(['message' => 'File berisi lebih dari '.self::MAX_ROWS.' baris. Bagi menjadi beberapa file.'], 422);
        }

        $rows = array_map(fn (array $row) => ['row' => $row['__row'], 'values' => $this->values($row)], $rows);

        // Run it for real — duplicates within the file included — then undo everything.
        DB::beginTransaction();
        try {
            $result = $this->process($rows);
        } finally {
            DB::rollBack();
        }

        return response()->json(['rows' => $result]);
    }

    /** Creates the rows the preview marked as ready, checking each again. */
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'max:'.self::MAX_ROWS],
            'rows.*.row' => ['required', 'integer'],
            'rows.*.values' => ['required', 'array'],
        ], ['rows.required' => 'Tidak ada baris yang siap diinput.']);

        $rows = array_map(fn (array $row) => ['row' => (int) $row['row'], 'values' => $this->values($row['values'])], $data['rows']);
        $result = DB::transaction(fn () => $this->process($rows));

        return response()->json([
            'created' => count(array_filter($result, fn ($r) => $r['status'] === 'new')),
            'rows' => $result,
        ]);
    }

    /** The rows that failed, laid out like the template with the reason next to each, to fix and import again. */
    public function fix(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'max:'.self::MAX_ROWS],
            'rows.*.row' => ['nullable', 'integer'],
            'rows.*.values' => ['required', 'array'],
            'rows.*.reason' => ['nullable', 'string', 'max:500'],
        ]);

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Jabatan');
        $headers = [...array_keys(self::COLUMNS), self::REASON_HEADER, self::SOURCE_ROW_HEADER];
        $sheet->fromArray($headers, null, 'A1');
        foreach (array_values($data['rows']) as $i => $row) {
            $values = $this->values($row['values']);
            $sheet->fromArray([
                ...array_map(fn (string $key) => $values[$key] ?? '', array_values(self::COLUMNS)),
                $row['reason'] ?? '',
                $row['row'] ?? '',
            ], null, 'A'.($i + 2));
        }
        $this->style($sheet, count($headers));

        // The reason stands out; it is ignored when the file is imported again.
        $reason = Coordinate::stringFromColumnIndex(count(self::COLUMNS) + 1);
        $last = count($data['rows']) + 1;
        $sheet->getStyle("{$reason}1")->getFill()->getStartColor()->setRGB('E11D48');
        $sheet->getStyle("{$reason}2:{$reason}{$last}")->applyFromArray([
            'font' => ['color' => ['rgb' => 'BE123C']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FFF1F2']],
        ]);
        $sheet->getColumnDimension($reason)->setAutoSize(false)->setWidth(70);
        $sheet->getStyle("{$reason}2:{$reason}{$last}")->getAlignment()->setWrapText(true);

        $this->instructionSheet($book->createSheet()->setTitle('Petunjuk'));
        $this->referenceSheet($book->createSheet()->setTitle('Referensi'));
        $book->setActiveSheetIndex(0);

        return ExcelWorkbook::download($book, 'fix-import-position.xlsx');
    }

    /**
     * Places each row: "baru" (created), "dilewati" (exists already, or repeats
     * an earlier row) or "error" (with the reason).
     *
     * @param  list<array{row: int, values: array<string, string|null>}>  $rows
     * @return list<array<string, mixed>>
     */
    private function process(array $rows): array
    {
        $createdBy = []; // jabatan id => row that created it
        $result = [];
        foreach ($rows as ['row' => $number, 'values' => $values]) {
            [$placement, $error] = PositionComposer::fromNames($values);
            [$position, $error] = $placement ? PositionComposer::findOrCreate($placement) : [null, $error];
            $base = ['row' => $number, 'values' => $values];

            if (! $position) {
                $result[] = $base + ['status' => 'error', 'name' => null, 'unit' => null, 'reason' => $error];
            } elseif ($position->wasRecentlyCreated) {
                $createdBy[$position->id] = $number;
                $result[] = $base + ['status' => 'new', 'name' => $position->name, 'unit' => $position->unitLabel(), 'reason' => null];
            } else {
                $result[] = $base + [
                    'status' => 'skipped',
                    'name' => $position->name,
                    'unit' => $position->unitLabel(),
                    'reason' => isset($createdBy[$position->id])
                        ? "Sama dengan baris {$createdBy[$position->id]} di file ini."
                        : "Sudah ada di {$position->workLocation?->name}.",
                ];
            }
        }

        return $result;
    }

    /** @return array<string, string|null> the template's values of a row, by JabatanComposer::fromNames() key */
    private function values(array $row): array
    {
        $values = [];
        $headerOf = fn (string $header) => mb_strtolower(trim(str_replace('*', '', $header)));
        $legacy = array_flip(self::LEGACY_COLUMNS);
        foreach (self::COLUMNS as $header => $key) {
            // A read file row is keyed by lower-case header; a row sent back by the preview by key.
            $value = $row[$key] ?? $row[$headerOf($header)] ?? (isset($legacy[$key]) ? $row[$headerOf($legacy[$key])] ?? null : null);
            $values[$key] = $value === null || trim((string) $value) === '' ? null : trim((string) $value);
        }

        return $values;
    }

    /** @return list<array<string, mixed>> keyed by lower-case header without "*", plus "__row" */
    private function read(string $path, string $extension): array
    {
        $reader = IOFactory::createReader(match (strtolower($extension)) {
            'xls' => 'Xls',
            'csv' => 'Csv',
            default => 'Xlsx',
        });
        $reader->setReadDataOnly(true);
        $grid = $reader->load($path)->getSheet(0)->toArray(null, true, false, false);

        $headers = array_map(fn ($h) => mb_strtolower(trim(str_replace('*', '', (string) $h))), array_shift($grid) ?? []);
        $rows = [];
        foreach ($grid as $i => $cells) {
            $row = [];
            foreach ($headers as $c => $h) {
                if ($h !== '') {
                    $row[$h] = is_string($cells[$c] ?? null) ? trim($cells[$c]) : ($cells[$c] ?? null);
                }
            }
            if (array_filter($row, fn ($v) => $v !== null && $v !== '')) {
                $rows[] = $row + ['__row' => $i + 2];
            }
        }

        return $rows;
    }

    private function instructionSheet(Worksheet $sheet): void
    {
        $lines = [
            'PETUNJUK IMPORT JABATAN',
            '',
            'Nama jabatan tidak diketik: tersusun otomatis dari penempatan, sama seperti saat menempatkan karyawan.',
            '',
            '• Work Location dan SubGroup wajib diisi. SubGroup menentukan kolom lain yang diisi (lihat sheet Referensi):',
            '    - dinamai dari BOD / Divisi / Departemen / Section → isi kolom itu,',
            '      mis. Section Head + Section PRODUKSI → SECTION HEAD PRODUKSI',
            '    - dinamai dari Work Location (mis. Project Manager) → tidak perlu kolom lain → PROJECT MANAGER RMTU,',
            '      atasannya disusun di Struktur Organisasi',
            '    - Nama Jabatan → isi Nama Jabatan (Bidang); Section opsional (bisa diatur di Struktur Organisasi),',
            '      mis. Officer/Group Leader + HSE → OFFICER HSE di Head Office, GROUP LEADER HSE di site',
            '• Departemen hanya perlu diisi untuk Section yang namanya ada di beberapa departemen.',
            '• Jabatan yang sudah ada di Work Location itu dilewati.',
            '• Sebelum disimpan, hasil import ditampilkan dulu. Baris error bisa diunduh (kolom "Alasan Error"), diperbaiki,',
            '  lalu diimpor lagi — kolom Alasan Error dan Baris Asal tidak perlu dihapus.',
        ];
        foreach ($lines as $i => $line) {
            $sheet->setCellValue([1, $i + 1], $line);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getColumnDimension('A')->setWidth(110);
    }

    /** Valid values to copy from, so names match the masters exactly. */
    private function referenceSheet(Worksheet $sheet): void
    {
        $columns = [
            'Work Location (nama / kode)' => WorkLocation::orderBy('sort_order')->get()->map(fn ($w) => $w->code ? "{$w->name} / {$w->code}" : $w->name)->all(),
            'SubGroup → isi kolom' => JobLevel::orderByDesc('layer')->get()->map(fn ($g) => "{$g->name} → ".(JobLevel::SUMBER[$g->source] ?? $g->source))->all(),
            'Section (Departemen)' => Section::with('department')->orderBy('department_id')->orderBy('sort_order')->get()->map(fn ($s) => "{$s->name} ({$s->department->name})")->all(),
            'Nama Jabatan (Bidang)' => LookupValues::values(PositionComposer::BIDANG),
            'Jabatan yang sudah ada' => Position::with('workLocation')->orderBy('sort_order')->get()->map(fn ($j) => "{$j->name} — {$j->workLocation?->name}")->all(),
        ];

        $col = 1;
        foreach ($columns as $title => $values) {
            $sheet->setCellValue([$col, 1], $title);
            foreach (array_values($values) as $i => $value) {
                $sheet->setCellValue([$col, $i + 2], $value);
            }
            $col++;
        }
        $this->style($sheet, count($columns));
    }

    private function style(Worksheet $sheet, int $columns): void
    {
        $last = Coordinate::stringFromColumnIndex($columns);
        $sheet->getStyle("A1:{$last}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '586C9A']],
        ]);
        foreach (range(1, $columns) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }
        $sheet->freezePane('A2');
    }
}
