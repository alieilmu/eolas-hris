<?php

namespace App\Support\Leave;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * "SISA CUTI TAHUNAN" as HR kept it in Excel: one row per employee with the
 * active balance and every leave taken from the annual quota as Hari | Tgl.
 */
class LeaveExcel
{
    private const FIXED = ['NO', 'FULL NAME', 'EMPLOYEE NUMBER', 'DEPARTMENT', 'HIRE DATE', 'YEARS OF SERVICE', 'ACTIVE ANNUAL LEAVE BALANCE', 'TOTAL LEAVE TAKEN', 'LEAVE DEBT', 'PENDING APPROVAL'];

    public static function build(array $rows, CarbonImmutable $today): Spreadsheet
    {
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet();
        $ws->setTitle('ANNUAL LEAVE');

        // Only what takes from the annual quota; per employee, oldest first.
        $taken = array_map(fn (array $r) => array_values(array_filter($r['transaction'], fn ($i) => $i['deduct'] > 0 && $i['status'] !== 'rejected')), $rows);
        $pairs = max(17, ...array_map('count', $taken ?: [[]]));
        $fixed = count(self::FIXED);
        $last = $fixed + $pairs * 2;

        foreach (self::FIXED as $i => $label) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $ws->setCellValue("{$col}1", $label);
            $ws->mergeCells("{$col}1:{$col}2");
        }
        $from = Coordinate::stringFromColumnIndex($fixed + 1);
        $to = Coordinate::stringFromColumnIndex($last);
        $ws->setCellValue("{$from}1", 'ANNUAL LEAVE TAKEN (as of '.$today->format('d.m.Y').')');
        $ws->mergeCells("{$from}1:{$to}1");
        for ($p = 0; $p < $pairs; $p++) {
            $ws->setCellValue([$fixed + 1 + $p * 2, 2], $p + 1);
            $ws->setCellValue([$fixed + 2 + $p * 2, 2], 'Date');
        }

        $r = 3;
        foreach ($rows as $i => $row) {
            $t = $row['annual'];
            $ws->fromArray([
                $i + 1,
                $row['name'],
                $row['employee_number'],
                $row['department'],
                $row['hired'] ? CarbonImmutable::parse($row['hired'])->format('d.m.Y') : '',
                $row['years_of_service'],
                ($t['present'] ?? false) ? $t['balance'] : '',
                $t['taken_total'] ?? 0,
                $t['debt'] ?? 0,
                $t['pending'] ?? 0,
            ], null, "A{$r}");
            $ws->getCell("C{$r}")->setValueExplicit($row['employee_number'], DataType::TYPE_STRING);
            foreach ($taken[$i] as $p => $item) {
                $ws->setCellValue([$fixed + 1 + $p * 2, $r], $item['deduct']);
                $ws->setCellValue([$fixed + 2 + $p * 2, $r], self::when($item).($item['status'] === 'pending' ? ' (pending)' : ''));
            }
            $r++;
        }

        $head = $ws->getStyle("A1:{$to}2");
        $head->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF586C9A');
        $head->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $ws->getStyle('G3:G'.max(3, $r - 1))->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8F5E9');
        $ws->getStyle("A1:{$to}".max(2, $r - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFCBD5E1');
        $ws->getRowDimension(1)->setRowHeight(30);
        $ws->getRowDimension(2)->setRowHeight(30);
        foreach ([5, 28, 11, 24, 13, 18, 12, 12, 10, 12] as $i => $w) {
            $ws->getColumnDimensionByColumn($i + 1)->setWidth($w);
        }
        for ($p = 0; $p < $pairs; $p++) {
            $ws->getColumnDimensionByColumn($fixed + 1 + $p * 2)->setWidth(5);
            $ws->getColumnDimensionByColumn($fixed + 2 + $p * 2)->setWidth(24);
        }
        $ws->freezePane('D3');

        return $book;
    }

    /** "07.06.2023 – 09.06.2023", or the text kept from the old sheet. */
    private static function when(array $item): string
    {
        if (! $item['start_date']) {
            return (string) ($item['description'] ?? $item['year']);
        }
        $a = CarbonImmutable::parse($item['start_date'])->format('d.m.Y');
        $b = $item['end_date'] ? CarbonImmutable::parse($item['end_date'])->format('d.m.Y') : null;

        return $b && $b !== $a ? "{$a} – {$b}" : $a;
    }
}
