<?php

namespace App\Support\Cuti;

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
class CutiExcel
{
    private const FIXED = ['NO', 'NAMA LENGKAP', 'N R P', 'DEPARTEMEN', 'TGL. MASUK PT. RML', 'MASA KERJA', 'SISA CUTI TAHUNAN AKTIF', 'TOTAL CUTI DI AJUKAN', 'HUTANG CUTI', 'MENUNGGU PERSETUJUAN'];

    public static function build(array $rows, CarbonImmutable $today): Spreadsheet
    {
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet();
        $ws->setTitle('CUTI TAHUNAN');

        // Only what takes from the annual quota; per employee, oldest first.
        $taken = array_map(fn (array $r) => array_values(array_filter($r['pengambilan'], fn ($i) => $i['potong'] > 0 && $i['status'] !== 'ditolak')), $rows);
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
        $ws->setCellValue("{$from}1", 'PENGAMBILAN CUTI TAHUNAN (per '.$today->format('d.m.Y').')');
        $ws->mergeCells("{$from}1:{$to}1");
        for ($p = 0; $p < $pairs; $p++) {
            $ws->setCellValue([$fixed + 1 + $p * 2, 2], $p + 1);
            $ws->setCellValue([$fixed + 2 + $p * 2, 2], 'Tgl');
        }

        $r = 3;
        foreach ($rows as $i => $row) {
            $t = $row['tahunan'];
            $ws->fromArray([
                $i + 1,
                $row['name'],
                $row['nrp'],
                $row['departemen'],
                $row['masuk'] ? CarbonImmutable::parse($row['masuk'])->format('d.m.Y') : '',
                $row['masa_kerja'],
                ($t['ada'] ?? false) ? $t['saldo'] : '',
                $t['diambil_total'] ?? 0,
                $t['hutang'] ?? 0,
                $t['menunggu'] ?? 0,
            ], null, "A{$r}");
            $ws->getCell("C{$r}")->setValueExplicit($row['nrp'], DataType::TYPE_STRING);
            foreach ($taken[$i] as $p => $item) {
                $ws->setCellValue([$fixed + 1 + $p * 2, $r], $item['potong']);
                $ws->setCellValue([$fixed + 2 + $p * 2, $r], self::when($item).($item['status'] === 'diajukan' ? ' (menunggu)' : ''));
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
        if (! $item['tanggal_awal']) {
            return (string) ($item['keterangan'] ?? $item['tahun']);
        }
        $a = CarbonImmutable::parse($item['tanggal_awal'])->format('d.m.Y');
        $b = $item['tanggal_akhir'] ? CarbonImmutable::parse($item['tanggal_akhir'])->format('d.m.Y') : null;

        return $b && $b !== $a ? "{$a} – {$b}" : $a;
    }
}
