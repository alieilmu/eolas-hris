<?php

namespace App\Support\Time;

use App\Models\Employee;
use App\Models\ShiftCode;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Worksheet\Table\TableStyle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Roster / Absensi in Excel: the table of days (export, and the template to fill in;
 * a quarter or a year of days side by side on one sheet), one row per employee-day
 * (export), and reading filled tables back.
 */
class SheetExcel
{
    public const DATE_FORMAT = 'd.m.Y';

    /** At most this many months in one file. */
    public const MAX_MONTHS = 12;

    private const IDENTITY = ['Employee Number', 'Name', 'Position', 'Department', 'Work Location'];

    private const HEADER_ROW = 2;

    private const FIRST_DATA_ROW = 4;

    private const WEEKDAY = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];

    private const INSTRUCTIONS_SHEET = 'Petunjuk';

    /** Cell fill per jenis, like the page. */
    private const FILL = [
        'work' => 'D1FAE5',
        'business_trip' => 'E0F2FE',
        'off' => 'F1F5F9',
        'leave' => 'EDE9FE',
        'sick' => 'FEF3C7',
        'permission' => 'FFEDD5',
        'absent' => 'FFE4E6',
    ];

    /**
     * Petunjuk, then one data sheet holding every day of the chosen months side by side
     * (a quarter or a year stays on the same sheet).
     *
     * @param  list<array{0: Period, 1: list<array>}>  $months  each month with its Sheet::rows() entries
     * @param  'kode'|'dws'  $format
     */
    public static function monthly(string $type, array $months, string $format): Spreadsheet
    {
        $codes = ShiftCode::all()->keyBy('id');
        $book = new Spreadsheet;
        self::instructions($book->getActiveSheet(), $type, $codes->sortBy('sort_order')->values()->all());
        self::dataSheet($book->createSheet()->setTitle($type === 'schedule' ? 'Roster' : 'Absensi'), $type, $months, $format, $codes);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * @param  list<array{0: Period, 1: list<array>}>  $months
     * @param  Collection<int, ShiftCode>  $codes
     */
    private static function dataSheet(Worksheet $sheet, string $type, array $months, string $format, Collection $codes): void
    {
        $label = fn (int $id) => $format === 'daily_work_schedule' ? ($codes[$id]->daily_work_schedule ?: $codes[$id]->code) : $codes[$id]->code;
        $withStats = $type === 'attendance';
        $jenis = $codes->map->type->all();
        $today = now()->toDateString();

        // Every date of the range in one run of columns; each month starts at its offset.
        $dates = [];
        $offset = [];
        foreach ($months as $m => [$period]) {
            $offset[$m] = count($dates);
            for ($d = 1; $d <= $period->dayCount(); $d++) {
                $dates[] = $period->start->setDay($d);
            }
        }
        // Days up to today count for the percentages.
        $lastIndex = count(array_filter($dates, fn ($date) => $date->toDateString() <= $today));
        // Y-m-d => 0-based position among the date columns.
        $dateIndex = array_flip(array_map(fn ($date) => $date->toDateString(), $dates));

        // Everyone on any of the months, in first-seen order, described as in the latest month.
        $people = [];
        foreach ($months as $m => [, $rows]) {
            foreach ($rows as $row) {
                $person = $people[$row['id']] ?? ['cells' => [], 'plan' => []];
                $person['row'] = $row;
                foreach ((array) $row['cells'] as $day => $id) {
                    $person['cells'][$offset[$m] + $day] = $id;
                }
                foreach ((array) ($row['plan'] ?? []) as $day => $id) {
                    $person['plan'][$offset[$m] + $day] = $id;
                }
                $people[$row['id']] = $person;
            }
        }
        $people = array_values($people);

        $firstDayCol = count(self::IDENTITY) + 1;
        $lastDayCol = $firstDayCol + count($dates) - 1;
        $lastCol = $lastDayCol + ($withStats ? 2 : 0);
        $lastLetter = Coordinate::stringFromColumnIndex($lastCol);
        $col = fn (int $index) => Coordinate::stringFromColumnIndex($firstDayCol + $index - 1);

        $span = mb_strtoupper($months[0][0]->label()).(count($months) > 1 ? ' – '.mb_strtoupper(end($months)[0]->label()) : '');
        $sheet->setCellValueExplicit('A1', ($type === 'schedule' ? 'ROSTER' : 'ABSENSI')." — {$span}".($format === 'daily_work_schedule' ? ' (DWS)' : ''), DataType::TYPE_STRING);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        foreach (self::IDENTITY as $i => $header) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).self::HEADER_ROW, $header, DataType::TYPE_STRING);
        }
        foreach ($dates as $i => $date) {
            $letter = $col($i + 1);
            $sheet->setCellValueExplicit($letter.self::HEADER_ROW, $date->format(self::DATE_FORMAT), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit($letter.(self::HEADER_ROW + 1), self::WEEKDAY[$date->dayOfWeek], DataType::TYPE_STRING);
            $sheet->getColumnDimension($letter)->setWidth(11);
            if ($date->dayOfWeek === 0) {
                $sheet->getStyle($letter.self::HEADER_ROW.':'.$letter.(self::HEADER_ROW + 1))->getFont()->getColor()->setRGB('E11D48');
            }
        }
        if ($withStats) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($lastCol - 1).self::HEADER_ROW, '% Kehadiran', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit($lastLetter.self::HEADER_ROW, '% Sesuai Roster', DataType::TYPE_STRING);
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($lastCol - 1))->setWidth(13);
            $sheet->getColumnDimension($lastLetter)->setWidth(15);
        }

        foreach ($people as $r => $person) {
            $line = self::FIRST_DATA_ROW + $r;
            $row = $person['row'];
            foreach ([$row['employee_number'], $row['name'], $row['position'], $row['department'], $row['work_location']] as $i => $value) {
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).$line, (string) $value, DataType::TYPE_STRING);
            }
            foreach ($person['cells'] as $index => $id) {
                $sheet->setCellValueExplicit($col($index).$line, $label($id), DataType::TYPE_STRING);
            }
            // From the exit date on: a black block carrying the reason (Resign, Mutasi, ...); not read back on import.
            if (($termination = $row['termination'] ?? null) && isset($dateIndex[$termination['date']])) {
                $start = $col($dateIndex[$termination['date']] + 1).$line;
                $block = $sheet->getStyle($start.':'.Coordinate::stringFromColumnIndex($lastDayCol).$line);
                $block->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1E293B');
                $block->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->setCellValueExplicit($start, mb_strtoupper($termination['reason']), DataType::TYPE_STRING);
            }
            if ($withStats) {
                $stats = Stats::of($person['cells'], $person['plan'], $jenis, $lastIndex);
                foreach ([$stats['time_clock'], $stats['matching']] as $i => $value) {
                    if ($value !== null) {
                        $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($lastCol - 1 + $i).$line, $value, DataType::TYPE_NUMERIC);
                    }
                }
            }
        }
        $lastLine = self::FIRST_DATA_ROW + max(count($people), 1) - 1;

        // Colours by code as conditional formats: fast for a year of cells, and they follow what is typed in Excel.
        $conditions = [];
        $seen = [];
        foreach ($codes->sortBy('sort_order') as $code) {
            $text = mb_strtoupper($label($code->id));
            $fill = self::FILL[$code->type] ?? null;
            if (! $fill || isset($seen[$text])) {
                continue;
            }
            $seen[$text] = true;
            $rule = (new Conditional)
                ->setConditionType(Conditional::CONDITION_CELLIS)
                ->setOperatorType(Conditional::OPERATOR_EQUAL)
                ->addCondition('"'.str_replace('"', '""', $text).'"');
            $rule->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
            $rule->getStyle()->getFill()->getEndColor()->setRGB($fill);
            $conditions[] = $rule;
        }
        $dayRange = $col(1).self::FIRST_DATA_ROW.':'.Coordinate::stringFromColumnIndex($lastDayCol).($lastLine + 200);
        $sheet->getStyle($dayRange)->setConditionalStyles($conditions);

        $header = $sheet->getStyle('A'.self::HEADER_ROW.":{$lastLetter}".(self::HEADER_ROW + 1));
        $header->getFont()->setBold(true);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');
        $header->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
        $sheet->getStyle($col(1).self::HEADER_ROW.":{$lastLetter}".$lastLine)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        // A line where each month starts, so the months stay apart on one sheet.
        foreach (array_slice($offset, 1) as $start) {
            $sheet->getStyle($col($start + 1).self::HEADER_ROW.':'.$col($start + 1).$lastLine)
                ->getBorders()->getLeft()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('586C9A');
        }
        foreach (['A' => 12, 'B' => 28, 'C' => 32, 'D' => 22, 'E' => 18] as $letter => $width) {
            $sheet->getColumnDimension($letter)->setWidth($width);
        }
        $sheet->freezePane($col(1).self::FIRST_DATA_ROW);
    }

    /**
     * One row per filled employee-day (e.g. for uploading DWS to payroll).
     *
     * @param  list<array{0: Period, 1: list<array>}>  $months
     * @param  'kode'|'dws'  $format
     */
    public static function daily(string $type, array $months, string $format): Spreadsheet
    {
        $codes = ShiftCode::all()->keyBy('id');
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle($type === 'schedule' ? 'Roster' : 'Absensi');
        $sheet->fromArray(['Employee Number', 'Name', 'Date', $format === 'daily_work_schedule' ? 'DWS' : 'Shift Code', 'Description'], null, 'A1');

        $line = 2;
        foreach ($months as [$period, $rows]) {
            foreach ($rows as $row) {
                foreach ((array) $row['cells'] as $day => $id) {
                    $code = $codes[$id];
                    $values = [
                        $row['employee_number'],
                        $row['name'],
                        $period->start->setDay($day)->format(self::DATE_FORMAT),
                        $format === 'daily_work_schedule' ? ($code->daily_work_schedule ?: $code->code) : $code->code,
                        $code->description,
                    ];
                    foreach ($values as $i => $value) {
                        $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).$line, (string) $value, DataType::TYPE_STRING);
                    }
                    $line++;
                }
            }
        }
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
        foreach (['A' => 12, 'B' => 28, 'C' => 13, 'D' => 12, 'E' => 30] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->freezePane('A2');

        return $book;
    }

    /**
     * Reads filled monthly tables from every sheet of the file (one month per
     * sheet, or many date columns on one). Codes may be written as Kode Shift or
     * DWS; an empty cell is left as it is, "-" empties the day.
     *
     * @return array{changes: list<array>, errors: list<array{sheet: string, row: int, nrp: string, message: string}>, rows: int, months: list<array{value: string, label: string, cells: int}>}
     */
    public static function read(UploadedFile $file): array
    {
        $reader = IOFactory::createReader(match (strtolower($file->getClientOriginalExtension())) {
            'xls' => 'Xls',
            'csv' => 'Csv',
            default => 'Xlsx',
        });
        $reader->setReadDataOnly(true);
        $book = $reader->load($file->getRealPath());

        $byCode = [];
        foreach (ShiftCode::orderBy('sort_order')->get() as $code) {
            $byCode[mb_strtoupper($code->code)] = $code->id;
        }
        foreach (ShiftCode::whereNotNull('daily_work_schedule')->orderBy('sort_order')->get() as $code) {
            $byCode[mb_strtoupper($code->daily_work_schedule)] ??= $code->id;
        }
        $people = Employee::pluck('id', 'employee_number');
        // Days before joining or from the exit date on are blocked (black in the file): their cells are passed over.
        $worked = Roll::workingDays($people->values());

        // karyawan id|date => change: a later sheet wins over an earlier one.
        $changes = [];
        $errors = [];
        $rowCount = 0;
        $sheetsRead = 0;
        foreach ($book->getAllSheets() as $sheet) {
            if ($sheet->getTitle() === self::INSTRUCTIONS_SHEET) {
                continue;
            }
            $name = $sheet->getTitle();
            $grid = $sheet->toArray(null, true, false, false);

            // The header row is the one starting with "Employee Number" (or "NRP"); day columns are those headed by a date.
            $headerIndex = null;
            foreach (array_slice($grid, 0, 6) as $i => $cells) {
                if (in_array(mb_strtoupper(trim((string) ($cells[0] ?? ''))), ['EMPLOYEE NUMBER', 'NRP'], true)) {
                    $headerIndex = $i;
                    break;
                }
            }
            $dayColumns = $headerIndex === null ? [] : array_filter(array_map(self::headerDate(...), $grid[$headerIndex]));
            if (! $dayColumns) {
                continue;
            }
            $sheetsRead++;

            foreach (array_slice($grid, $headerIndex + 1, preserve_keys: true) as $i => $cells) {
                $employee_number = trim((string) ($cells[0] ?? ''));
                $line = $i + 1;
                if ($employee_number === '' || ! preg_match('/\d/', $employee_number)) {
                    continue; // the weekday row, blank lines
                }
                $rowCount++;
                $employeeId = $people[$employee_number] ?? null;
                if (! $employeeId) {
                    $errors[] = ['sheet' => $name, 'row' => $line, 'employee_number' => $employee_number, 'message' => "NRP {$employee_number} tidak ada di data Man Power."];

                    continue;
                }
                $unknown = [];
                foreach ($dayColumns as $c => $date) {
                    $raw = mb_strtoupper(trim((string) ($cells[$c] ?? '')));
                    if ($raw === '' || ! $worked($employeeId, $date->toDateString())) {
                        continue;
                    }
                    if ($raw !== '-' && ! isset($byCode[$raw])) {
                        $unknown[] = $date->format(self::DATE_FORMAT).": \"{$raw}\"";

                        continue;
                    }
                    $tanggal = $date->toDateString();
                    $changes["{$employeeId}|{$tanggal}"] = ['employee_id' => $employeeId, 'date' => $tanggal, 'shift_code_id' => $raw === '-' ? null : $byCode[$raw]];
                }
                if ($unknown) {
                    $errors[] = [
                        'sheet' => $name,
                        'row' => $line,
                        'employee_number' => $employee_number,
                        'message' => 'Kode tidak dikenal (bukan Kode Shift / DWS) — '.implode(', ', array_slice($unknown, 0, 5))
                            .(count($unknown) > 5 ? ', ...' : '').'. Sel lain di baris ini tetap dipakai.',
                    ];
                }
            }
        }

        if (! $sheetsRead) {
            return self::fail('Kolom "Employee Number" dan kolom tanggal (dd.mm.yyyy) tidak ditemukan di sheet mana pun. Pakai template dari tombol "Unduh template".');
        }

        $months = collect($changes)->countBy(fn (array $c) => substr($c['date'], 0, 7))->sortKeys();
        if ($months->count() > self::MAX_MONTHS) {
            return self::fail('File berisi '.$months->count().' bulan; paling banyak '.self::MAX_MONTHS.' bulan (1 tahun) sekali import.');
        }

        return [
            'changes' => array_values($changes),
            'errors' => $errors,
            'rows' => $rowCount,
            'months' => $months->map(fn (int $cells, string $value) => ['value' => $value, 'label' => Period::from($value)->label(), 'cells' => $cells])->values()->all(),
        ];
    }

    private static function fail(string $message): array
    {
        return ['changes' => [], 'errors' => [['sheet' => '', 'row' => 0, 'employee_number' => '', 'message' => $message]], 'rows' => 0, 'months' => []];
    }

    private static function headerDate(mixed $header): ?CarbonImmutable
    {
        if (is_int($header) || is_float($header)) {
            // An Excel date cell (days since 1899-12-30).
            return $header > 30000 && $header < 80000 ? CarbonImmutable::create(1899, 12, 30)->addDays((int) $header) : null;
        }
        $s = trim((string) $header);
        if (! preg_match('/^\d{1,4}[.\/-]\d{1,2}[.\/-]\d{2,4}$/', $s)) {
            return null;
        }
        foreach (['d.m.Y', 'j.n.Y', 'Y-m-d', 'd/m/Y'] as $format) {
            try {
                $d = CarbonImmutable::createFromFormat("!{$format}", $s);
            } catch (\Throwable) {
                continue;
            }
            if ($d && $d->format($format) === $s) {
                return $d;
            }
        }

        return null;
    }

    /**
     * The "Petunjuk" sheet: the codes from Master Data as an Excel table on the left
     * (Kode Shift, DWS, Keterangan — filterable), the steps on the right.
     *
     * @param  list<ShiftCode>  $codes
     */
    private static function instructions(Worksheet $sheet, string $type, array $codes): void
    {
        $sheet->setTitle(self::INSTRUCTIONS_SHEET);
        $sheet->setShowGridlines(false);

        // Codes table.
        $sheet->fromArray(['Shift Code', 'DWS', 'Description'], null, 'A1');
        foreach ($codes as $i => $code) {
            $line = $i + 2;
            $sheet->setCellValueExplicit("A{$line}", $code->code, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("B{$line}", (string) ($code->daily_work_schedule ?: '-'), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$line}", $code->description ?: (ShiftCode::JENIS[$code->type] ?? $code->type), DataType::TYPE_STRING);
            if ($i % 2 === 1) {
                $sheet->getStyle("A{$line}:C{$line}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EDEDED');
            }
        }
        $last = max(count($codes) + 1, 2);
        $table = new Table("A1:C{$last}", 'KodeShift');
        // No built-in style (its colours follow the Excel theme): the colours below are set on the cells.
        $table->setStyle((new TableStyle)->setTheme(TableStyle::TABLE_STYLE_NONE));
        $sheet->addTable($table);
        $header = $sheet->getStyle('A1:C1');
        $header->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('9BBB59');
        $sheet->getStyle("A1:C{$last}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('9BBB59');
        $sheet->getStyle("A{$last}:C{$last}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('9BBB59');
        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(12);
        $sheet->getColumnDimension('C')->setWidth(max(24, min(60, collect($codes)->max(fn ($c) => mb_strlen((string) $c->description)) + 4)));
        $sheet->getColumnDimension('D')->setWidth(4);

        // Steps.
        $what = $type === 'schedule' ? 'Roster' : 'Absensi';
        $steps = [
            "Isi kolom tanggal di sheet {$what} dengan Kode Shift atau DWS dari tabel di samping (huruf besar/kecil sama saja).",
            'Sel kosong dibiarkan seperti data yang sudah ada. Tulis "-" untuk mengosongkan hari itu.',
            'Baris dibaca dari Employee Number (NRP); kolom Name, Position, Department dan Work Location hanya untuk dibaca.',
            'Tanggal diambil dari judul kolom (dd.mm.yyyy). Beberapa bulan tetap di sheet yang sama, bersambung ke kanan (paling banyak '.self::MAX_MONTHS.' bulan); garis tebal menandai awal bulan.',
            "Satu bulan yang sedang dibuka masuk ke tabel {$what} dulu (mode Edit) untuk diperiksa, lalu tekan Simpan; file beberapa bulan langsung disimpan setelah pratinjau.",
            'Daftar kode di samping diambil dari Pengaturan Waktu & Kehadiran saat file diunduh. Kode baru? Tambahkan di Master Data, lalu unduh template lagi.',
        ];
        $sheet->setCellValueExplicit('E1', "Petunjuk import {$what}", DataType::TYPE_STRING);
        $sheet->getStyle('E1')->getFont()->setBold(true)->setSize(13);
        foreach ($steps as $i => $step) {
            $sheet->setCellValueExplicit('E'.($i + 3), ($i + 1).'. '.$step, DataType::TYPE_STRING);
        }
        $sheet->getColumnDimension('E')->setWidth(130);
        $sheet->setSelectedCell('A1');
    }
}
