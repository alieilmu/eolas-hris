<?php

namespace App\Support\Excel;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A plain report workbook: per sheet a title, a line saying what was exported,
 * one header row (with filters) and the rows under it. Columns are
 * [label, width, type] where type is text (kept as text, e.g. NRP), int, rupiah or date
 * (Y-m-d in, a real Excel date out); a column with 'sum' => true gets a total row.
 */
class TableExcel
{
    private const HEAD = 4;

    /**
     * @param  list<array{title: string, info?: string, freeze?: int, columns: list<array{0: string, 1: int, 2?: string, sum?: bool}>, rows: iterable<list<mixed>>}>  $sheets
     */
    public static function build(array $sheets): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        foreach ($sheets as $sheet) {
            self::addSheet($book, $sheet);
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    public static function download(string $filename, array $sheets): StreamedResponse
    {
        $book = self::build($sheets);

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** "Diunduh 07.10.2026 14:05" plus the filters that were on. */
    public static function info(array $parts): string
    {
        return implode(' · ', array_filter([...$parts, 'Diunduh '.now()->format('d.m.Y H:i')]));
    }

    /** One more sheet at the end of $book (e.g. next to a sheet in an older layout). */
    public static function addSheet(Spreadsheet $book, array $sheet): void
    {
        $ws = $book->createSheet();
        $columns = $sheet['columns'];
        $last = Coordinate::stringFromColumnIndex(count($columns));
        // Sheet names: max 31 characters, none of []:*?/\
        $ws->setTitle(mb_substr(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $sheet['title']), 0, 31));

        $ws->setCellValue('A1', $sheet['title']);
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ws->setCellValue('A2', $sheet['info'] ?? self::info([]));
        $ws->getStyle('A2')->getFont()->setItalic(true)->getColor()->setARGB('FF64748B');

        foreach ($columns as $i => $col) {
            $ws->setCellValue([$i + 1, self::HEAD], $col[0]);
            $ws->getColumnDimensionByColumn($i + 1)->setWidth($col[1]);
        }

        $r = self::HEAD + 1;
        foreach ($sheet['rows'] as $row) {
            foreach (array_values($row) as $i => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $type = $columns[$i][2] ?? 'text';
                $cell = $ws->getCell([$i + 1, $r]);
                match ($type) {
                    'date' => $cell->setValue(ExcelDate::PHPToExcel(CarbonImmutable::parse($value)->startOfDay())),
                    'int', 'rupiah' => $cell->setValueExplicit(is_bool($value) ? (int) $value : $value, DataType::TYPE_NUMERIC),
                    default => $cell->setValueExplicit((string) $value, DataType::TYPE_STRING),
                };
            }
            $r++;
        }
        $lastRow = max(self::HEAD, $r - 1);

        foreach ($columns as $i => $col) {
            $letter = Coordinate::stringFromColumnIndex($i + 1);
            $range = $letter.(self::HEAD + 1).":{$letter}".max(self::HEAD + 1, $lastRow);
            match ($col[2] ?? 'text') {
                'date' => $ws->getStyle($range)->getNumberFormat()->setFormatCode('dd.mm.yyyy'),
                'rupiah' => $ws->getStyle($range)->getNumberFormat()->setFormatCode('#,##0'),
                default => null,
            };
            if (in_array($col[2] ?? 'text', ['date', 'int'], true)) {
                $ws->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
        }

        // The total row, under the data.
        $sums = array_keys(array_filter($columns, fn ($c) => $c['sum'] ?? false));
        if ($sums && $lastRow > self::HEAD) {
            $ws->setCellValue([1, $r], 'TOTAL');
            foreach ($sums as $i) {
                $letter = Coordinate::stringFromColumnIndex($i + 1);
                $ws->setCellValue([$i + 1, $r], "=SUM({$letter}".(self::HEAD + 1).":{$letter}{$lastRow})");
                $ws->getStyle("{$letter}{$r}")->getNumberFormat()->setFormatCode('#,##0');
            }
            $total = $ws->getStyle("A{$r}:{$last}{$r}");
            $total->getFont()->setBold(true);
            $total->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
        }
        $bottom = $sums && $lastRow > self::HEAD ? $r : $lastRow;

        $head = $ws->getStyle('A'.self::HEAD.":{$last}".self::HEAD);
        $head->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF586C9A');
        $head->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $ws->getRowDimension(self::HEAD)->setRowHeight(32);
        $ws->getStyle('A'.self::HEAD.":{$last}{$bottom}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');
        $ws->setAutoFilter('A'.self::HEAD.":{$last}{$lastRow}");
        // 'freeze' => 3 keeps No / NRP / Nama in view when scrolling sideways.
        $ws->freezePane(Coordinate::stringFromColumnIndex(($sheet['freeze'] ?? 0) + 1).(self::HEAD + 1));
    }
}
