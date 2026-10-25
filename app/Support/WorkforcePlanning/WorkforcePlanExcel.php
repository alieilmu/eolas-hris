<?php

namespace App\Support\WorkforcePlanning;

use App\Models\Position;
use App\Models\WorkforcePlan;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The MPP sheet as HR keeps it in Excel: per departemen the positions with Plan, Actual,
 * who is expected to leave (and when), the Gap, when the need is filled and from where —
 * written from a plan, and read back into one (matched by position name).
 */
class WorkforcePlanExcel
{
    /** Column letters of the export (the import finds its columns by their headers). */
    private const COL = [
        'category' => 'A', 'position' => 'B', 'plan' => 'C', 'actual' => 'D', 'promotions' => 'E', 'rotations' => 'F', 'demotions' => 'G',
        'termination' => 'H', 'retirements' => 'I', 'sick' => 'J', 'gap' => 'K',
    ];

    private const PINDAH = 12; // L..W: month 1–12 of the movement

    private const PENUHI = 24; // X..AI: month 1–12 the need is filled

    public static function download(WorkforcePlan $plan): StreamedResponse
    {
        $plan->load('workLocation');
        $book = self::build($plan);
        $name = 'workforce_planning-'.str($plan->workLocation->name)->slug().'-'.strtolower($plan->code()).'.xlsx';

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public static function build(WorkforcePlan $plan): Spreadsheet
    {
        $t = WorkforcePlanning::table($plan);
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet();
        $ws->setTitle(mb_substr('MPP '.$plan->workLocation->name, 0, 31));
        $col = fn (int $n) => Coordinate::stringFromColumnIndex($n);

        $ws->setCellValue('A1', 'MAN POWER PLANNING — '.strtoupper($plan->workLocation->name).' · '.WorkforcePlanning::label(['type' => $plan->type, 'year' => $plan->year, 'period_number' => $plan->period_number, 'start_date' => $plan->start_date, 'end_date' => $plan->end_date]));
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        // Headers in rows 2–4, as in the template.
        $ws->setCellValue('A2', 'Category');
        $ws->mergeCells('A2:A4');
        $ws->setCellValue('B3', 'Position');
        $ws->mergeCells('B2:B4');
        $ws->setCellValue('B2', 'Position');
        $ws->setCellValue('C3', 'Plan');
        $ws->mergeCells('C3:C4');
        $ws->setCellValue('D3', 'Actual');
        $ws->setCellValue('D4', 'As of '.CarbonImmutable::today()->format('d.m.Y'));
        $ws->setCellValue('E2', 'Expected:');
        $ws->mergeCells('E2:J2');
        foreach (['E' => 'Promotion', 'F' => "Rotation/\nTransfer", 'G' => 'Demotion', 'H' => 'Termination', 'I' => 'Retirement', 'J' => "Long-term\nSick Leave"] as $c => $label) {
            $ws->setCellValue("{$c}3", $label);
            $ws->mergeCells("{$c}3:{$c}4");
        }
        $ws->setCellValue('K2', "Gap\n(+/-)");
        $ws->mergeCells('K2:K4');
        foreach ([[self::PINDAH, 'Promotion/Rotation/Transfer/Demotion/Termination Timing'], [self::PENUHI, 'Fulfilment Timing']] as [$start, $label]) {
            $from = $col($start);
            $to = $col($start + 11);
            $ws->setCellValue("{$from}2", $label);
            $ws->mergeCells("{$from}2:{$to}2");
            $ws->setCellValue("{$from}3", 'Month');
            $ws->mergeCells("{$from}3:{$to}3");
            for ($m = 1; $m <= 12; $m++) {
                $ws->setCellValue([$start + $m - 1, 4], $m);
            }
        }
        $ws->setCellValue('AJ2', 'Fulfilled by');
        $ws->mergeCells('AJ2:AK2');
        $ws->setCellValue('AJ3', 'Internal');
        $ws->setCellValue('AJ4', '(by LA)');
        $ws->setCellValue('AK3', 'External');
        $ws->setCellValue('AK4', '(by ODTA)');
        foreach (['AL' => 'Description', 'AM' => 'Grade', 'AN' => 'Wage / Month', 'AO' => "Estimated Cost\n/ Month"] as $c => $label) {
            $ws->setCellValue("{$c}2", $label);
            $ws->mergeCells("{$c}2:{$c}4");
        }

        $r = 6;
        $subtotals = [];
        foreach ($t['groups'] as $g) {
            $ws->setCellValue("B{$r}", strtoupper($g['department']));
            $ws->getStyle("B{$r}")->getFont()->setBold(true);
            $first = ++$r;
            foreach ($g['rows'] as $row) {
                $ws->fromArray([$row['category'], $row['position'], $row['plan'], $row['actual'], $row['promotions'], $row['rotations'], $row['demotions'], $row['termination'], $row['retirements'], $row['sick']], null, "A{$r}");
                $ws->setCellValue("K{$r}", "=D{$r}-(C{$r}+E{$r}+F{$r}+G{$r}+H{$r}+I{$r}+J{$r})");
                $move = $row['promotions'] + $row['rotations'] + $row['demotions'] + $row['termination'] + $row['retirements'] + $row['sick'];
                if ($move && $row['movement_month']) {
                    $ws->setCellValue([self::PINDAH + $row['movement_month'] - 1, $r], $move);
                }
                $short = max(0, -$row['gap']);
                if ($short && $row['fulfilment_month']) {
                    $ws->setCellValue([self::PENUHI + $row['fulfilment_month'] - 1, $r], $short);
                }
                if ($short && $row['source']) {
                    $ws->setCellValue(($row['source'] === 'internal' ? 'AJ' : 'AK').$r, $short);
                }
                $ws->setCellValue("AL{$r}", $row['description']);
                $ws->setCellValue("AM{$r}", $row['grade']);
                if ($row['wage']) {
                    $ws->setCellValue("AN{$r}", $row['wage']);
                    $ws->setCellValue("AO{$r}", "=MAX(0,-K{$r})*AN{$r}");
                }
                $r++;
            }
            $ws->setCellValue("B{$r}", 'Sub Total');
            foreach (array_merge(range(3, 11), range(self::PINDAH, self::PENUHI + 11), [36, 37, 41]) as $c) {
                $letter = $col($c);
                $ws->setCellValue("{$letter}{$r}", "=SUM({$letter}{$first}:{$letter}".($r - 1).')');
            }
            $ws->getStyle("A{$r}:AO{$r}")->getFont()->setBold(true);
            $ws->getStyle("A{$r}:AO{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9EAF7');
            $subtotals[] = $r;
            $r += 2;
        }
        $ws->setCellValue("B{$r}", 'JUMLAH');
        foreach (array_merge(range(3, 11), range(self::PINDAH, self::PENUHI + 11), [36, 37, 41]) as $c) {
            $letter = $col($c);
            $ws->setCellValue("{$letter}{$r}", $subtotals ? '='.implode('+', array_map(fn ($s) => "{$letter}{$s}", $subtotals)) : 0);
        }
        $ws->getStyle("A{$r}:AO{$r}")->getFont()->setBold(true);
        $ws->getStyle("A{$r}:AO{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD0CECE');
        $last = $r;

        // The per-category summary under it.
        $r += 3;
        $ws->fromArray(['CATEGORY', 'PLAN', 'ACTUAL', 'DEV'], null, "B{$r}");
        $ws->getStyle("B{$r}:E{$r}")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $ws->getStyle("B{$r}:E{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF2F75B5');
        $head = $r;
        foreach ($t['category'] as $k) {
            $r++;
            $ws->fromArray([strtoupper($k['label']), $k['plan'], $k['actual']], null, "B{$r}");
            $ws->setCellValue("E{$r}", "=D{$r}-C{$r}");
        }
        $r++;
        $ws->setCellValue("B{$r}", 'TOTAL');
        foreach (['C', 'D', 'E'] as $c) {
            $ws->setCellValue("{$c}{$r}", '=SUM('.$c.($head + 1).":{$c}".($r - 1).')');
        }
        $ws->getStyle("B{$r}:E{$r}")->getFont()->setBold(true);
        $ws->getStyle("B{$r}:E{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');
        $ws->getStyle('B'.($head).":E{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $headStyle = $ws->getStyle('A2:AO4');
        $headStyle->getFont()->setBold(true);
        $headStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $headStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD0CECE');
        $ws->getStyle("A2:AO{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFBFBFBF');
        $ws->getStyle("C6:AK{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle("AN6:AO{$last}")->getNumberFormat()->setFormatCode('#,##0');
        $ws->getColumnDimension('A')->setWidth(11);
        $ws->getColumnDimension('B')->setWidth(48);
        foreach (range(3, 11) as $c) {
            $ws->getColumnDimension($col($c))->setWidth(9);
        }
        foreach (range(self::PINDAH, self::PENUHI + 11) as $c) {
            $ws->getColumnDimension($col($c))->setWidth(4);
        }
        foreach (['AJ' => 11, 'AK' => 11, 'AL' => 30, 'AM' => 9, 'AN' => 13, 'AO' => 15] as $c => $w) {
            $ws->getColumnDimension($c)->setWidth($w);
        }
        $ws->freezePane('C6');

        return $book;
    }

    /**
     * Reads an MPP sheet (this export or HR's template) into the fields of the plan's positions.
     *
     * @return array{items: array<int, array<string, mixed>>, cocok: int, tidak_cocok: list<array{baris: int, jabatan: string, departemen: ?string}>}
     */
    public static function read(string $path, WorkforcePlan $plan): array
    {
        $ws = IOFactory::load($path)->getActiveSheet();
        $cols = self::columns($ws);
        $norm = fn (?string $s) => preg_replace('/\s+/', ' ', strtoupper(trim((string) $s)));
        $positions = Position::where('work_location_id', $plan->work_location_id)->get(['id', 'name'])->groupBy(fn (Position $j) => $norm($j->name));
        $int = fn ($v) => is_numeric($v) ? max(0, (int) round((float) $v)) : 0;
        $firstMonth = function (int $row, ?int $start) use ($ws): ?int {
            if (! $start) {
                return null;
            }
            for ($m = 1; $m <= 12; $m++) {
                $v = $ws->getCell([$start + $m - 1, $row])->getCalculatedValue();
                if (is_numeric($v) && $v > 0 || (is_string($v) && trim($v) !== '')) {
                    return $m;
                }
            }

            return null;
        };

        $items = [];
        $missing = [];
        $department = null;
        for ($r = $cols['first_row']; $r <= $ws->getHighestDataRow(); $r++) {
            $category = trim((string) $ws->getCell([1, $r])->getValue());
            $name = trim((string) $ws->getCell([$cols['position'], $r])->getValue());
            if ($name === '') {
                continue;
            }
            if (in_array($norm($name), ['JUMLAH', 'TOTAL'], true)) {
                break; // what follows (other plans, summaries) is not this table
            }
            if ($category === '') {
                if ($norm($name) !== 'SUB TOTAL') {
                    $department = $name;
                }

                continue;
            }
            $match = $positions[$norm($name)] ?? null;
            if (! $match) {
                $missing[] = ['rows' => $r, 'position' => $name, 'department' => $department];

                continue;
            }
            $v = fn (?int $c) => $c ? $ws->getCell([$c, $r])->getCalculatedValue() : null;
            $internal = $int($v($cols['internal']));
            $external = $int($v($cols['external']));
            $fields = [
                'plan' => $int($v($cols['plan'])),
                'promotions' => $int($v($cols['promotions'])),
                'rotations' => $int($v($cols['rotations'])),
                'demotions' => $int($v($cols['demotions'])),
                'termination' => $int($v($cols['termination'])),
                'movement_month' => $firstMonth($r, $cols['move']),
                'fulfilment_month' => $firstMonth($r, $cols['fulfil']),
                'source' => $internal ? 'internal' : ($external ? 'external' : null),
                'description' => ($k = trim((string) $v($cols['description']))) !== '' ? mb_substr($k, 0, 255) : null,
            ];
            foreach ($match as $j) {
                $items[$j->id] = $fields;
            }
        }

        return ['items' => $items, 'match' => count($items), 'not_matching' => $missing];
    }

    /** Where each column is, from the header rows (and the first data row under them). */
    private static function columns(Worksheet $ws): array
    {
        $found = ['position' => 2, 'plan' => null, 'promotions' => null, 'rotations' => null, 'demotions' => null, 'termination' => null, 'internal' => null, 'external' => null, 'description' => null, 'move' => null, 'fulfil' => null];
        $headerEnd = 4;
        $maxCol = Coordinate::columnIndexFromString($ws->getHighestDataColumn());
        for ($r = 1; $r <= 8; $r++) {
            for ($c = 1; $c <= $maxCol; $c++) {
                $text = strtoupper(preg_replace('/\s+/', ' ', (string) $ws->getCell([$c, $r])->getValue()));
                if ($text === '') {
                    continue;
                }
                $set = function (string $key) use (&$found, $c, $r, &$headerEnd) {
                    $found[$key] ??= $c;
                    $headerEnd = max($headerEnd, $r);
                };
                match (true) {
                    // English headers, or the Indonesian ones of the original MPP template.
                    in_array($text, ['POSITION', 'JABATAN'], true) => $found['position'] = $c,
                    $text === 'PLAN' => $set('plan'),
                    str_starts_with($text, 'PROMOTION/') || str_starts_with($text, 'WAKTU PROMOSI') => $set('move'),
                    str_starts_with($text, 'PROMOTION') || str_starts_with($text, 'PROMOSI') => $set('promotions'),
                    str_starts_with($text, 'ROTATION') || str_starts_with($text, 'ROTASI') => $set('rotations'),
                    str_starts_with($text, 'DEMOTION') || str_starts_with($text, 'DEMOSI') => $set('demotions'),
                    in_array($text, ['TERMINATION', 'KELUAR'], true) => $set('termination'),
                    $text === 'INTERNAL' => $set('internal'),
                    in_array($text, ['EXTERNAL', 'EKSTERNAL'], true) => $set('external'),
                    in_array($text, ['DESCRIPTION', 'KETERANGAN'], true) => $set('description'),
                    str_starts_with($text, 'FULFILMENT TIMING') || str_starts_with($text, 'WAKTU PEMENUHAN') => $set('fulfil'),
                    default => null,
                };
            }
        }
        if (! $found['plan']) {
            throw ValidationException::withMessages(['file' => 'Kolom "Plan" tidak ditemukan; pakai template MPP (baris judul di baris 2–4).']);
        }

        return $found + ['first_row' => $headerEnd + 1];
    }
}
