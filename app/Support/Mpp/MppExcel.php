<?php

namespace App\Support\Mpp;

use App\Models\Jabatan;
use App\Models\MppPlan;
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
class MppExcel
{
    /** Column letters of the export (the import finds its columns by their headers). */
    private const COL = [
        'kategori' => 'A', 'jabatan' => 'B', 'plan' => 'C', 'actual' => 'D', 'promosi' => 'E', 'rotasi' => 'F', 'demosi' => 'G',
        'keluar' => 'H', 'pensiun' => 'I', 'sakit' => 'J', 'gap' => 'K',
    ];

    private const PINDAH = 12; // L..W: month 1–12 of the movement

    private const PENUHI = 24; // X..AI: month 1–12 the need is filled

    public static function download(MppPlan $plan): StreamedResponse
    {
        $plan->load('workLocation');
        $book = self::build($plan);
        $name = 'mpp-'.str($plan->workLocation->name)->slug().'-'.strtolower($plan->kode()).'.xlsx';

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public static function build(MppPlan $plan): Spreadsheet
    {
        $t = Mpp::table($plan);
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet();
        $ws->setTitle(mb_substr('MPP '.$plan->workLocation->name, 0, 31));
        $col = fn (int $n) => Coordinate::stringFromColumnIndex($n);

        $ws->setCellValue('A1', 'MAN POWER PLANNING — '.strtoupper($plan->workLocation->name).' · '.Mpp::label(['jenis' => $plan->jenis, 'tahun' => $plan->tahun, 'ke' => $plan->ke, 'mulai' => $plan->mulai, 'selesai' => $plan->selesai]));
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        // Headers in rows 2–4, as in the template.
        $ws->setCellValue('A2', 'Kategori');
        $ws->mergeCells('A2:A4');
        $ws->setCellValue('B3', 'Jabatan');
        $ws->mergeCells('B2:B4');
        $ws->setCellValue('B2', 'Jabatan');
        $ws->setCellValue('C3', 'Plan');
        $ws->mergeCells('C3:C4');
        $ws->setCellValue('D3', 'Actual');
        $ws->setCellValue('D4', 'Tgl '.CarbonImmutable::today()->format('d.m.Y'));
        $ws->setCellValue('E2', 'Diperkirakan akan:');
        $ws->mergeCells('E2:J2');
        foreach (['E' => 'Promosi', 'F' => "Rotasi/\nMutasi", 'G' => 'Demosi', 'H' => 'Keluar', 'I' => 'Pensiun', 'J' => "Sakit\nBerkepanjangan"] as $c => $label) {
            $ws->setCellValue("{$c}3", $label);
            $ws->mergeCells("{$c}3:{$c}4");
        }
        $ws->setCellValue('K2', "Gap\n(+/-)");
        $ws->mergeCells('K2:K4');
        foreach ([[self::PINDAH, 'Waktu Promosi/Rotasi/Mutasi/Demosi/Keluar'], [self::PENUHI, 'Waktu Pemenuhan Kebutuhan']] as [$start, $label]) {
            $from = $col($start);
            $to = $col($start + 11);
            $ws->setCellValue("{$from}2", $label);
            $ws->mergeCells("{$from}2:{$to}2");
            $ws->setCellValue("{$from}3", 'Bulan');
            $ws->mergeCells("{$from}3:{$to}3");
            for ($m = 1; $m <= 12; $m++) {
                $ws->setCellValue([$start + $m - 1, 4], $m);
            }
        }
        $ws->setCellValue('AJ2', 'Pemenuhan dari');
        $ws->mergeCells('AJ2:AK2');
        $ws->setCellValue('AJ3', 'Internal');
        $ws->setCellValue('AJ4', '(Oleh LA)');
        $ws->setCellValue('AK3', 'Eksternal');
        $ws->setCellValue('AK4', '(Oleh ODTA)');
        foreach (['AL' => 'Keterangan', 'AM' => 'Golongan', 'AN' => 'Upah / Bulan', 'AO' => "Estimasi Biaya\n/ Bulan"] as $c => $label) {
            $ws->setCellValue("{$c}2", $label);
            $ws->mergeCells("{$c}2:{$c}4");
        }

        $r = 6;
        $subtotals = [];
        foreach ($t['groups'] as $g) {
            $ws->setCellValue("B{$r}", strtoupper($g['departemen']));
            $ws->getStyle("B{$r}")->getFont()->setBold(true);
            $first = ++$r;
            foreach ($g['rows'] as $row) {
                $ws->fromArray([$row['kategori'], $row['jabatan'], $row['plan'], $row['actual'], $row['promosi'], $row['rotasi'], $row['demosi'], $row['keluar'], $row['pensiun'], $row['sakit']], null, "A{$r}");
                $ws->setCellValue("K{$r}", "=D{$r}-(C{$r}+E{$r}+F{$r}+G{$r}+H{$r}+I{$r}+J{$r})");
                $pindah = $row['promosi'] + $row['rotasi'] + $row['demosi'] + $row['keluar'] + $row['pensiun'] + $row['sakit'];
                if ($pindah && $row['bulan_pindah']) {
                    $ws->setCellValue([self::PINDAH + $row['bulan_pindah'] - 1, $r], $pindah);
                }
                $kurang = max(0, -$row['gap']);
                if ($kurang && $row['bulan_penuhi']) {
                    $ws->setCellValue([self::PENUHI + $row['bulan_penuhi'] - 1, $r], $kurang);
                }
                if ($kurang && $row['sumber']) {
                    $ws->setCellValue(($row['sumber'] === 'internal' ? 'AJ' : 'AK').$r, $kurang);
                }
                $ws->setCellValue("AL{$r}", $row['keterangan']);
                $ws->setCellValue("AM{$r}", $row['golongan']);
                if ($row['upah']) {
                    $ws->setCellValue("AN{$r}", $row['upah']);
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
        $ws->fromArray(['KATEGORI', 'PLAN', 'ACTUAL', 'DEV'], null, "B{$r}");
        $ws->getStyle("B{$r}:E{$r}")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $ws->getStyle("B{$r}:E{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF2F75B5');
        $head = $r;
        foreach ($t['kategori'] as $k) {
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
    public static function read(string $path, MppPlan $plan): array
    {
        $ws = IOFactory::load($path)->getActiveSheet();
        $cols = self::columns($ws);
        $norm = fn (?string $s) => preg_replace('/\s+/', ' ', strtoupper(trim((string) $s)));
        $jabatans = Jabatan::where('work_location_id', $plan->work_location_id)->get(['id', 'name'])->groupBy(fn (Jabatan $j) => $norm($j->name));
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
        $departemen = null;
        for ($r = $cols['first_row']; $r <= $ws->getHighestDataRow(); $r++) {
            $kategori = trim((string) $ws->getCell([1, $r])->getValue());
            $name = trim((string) $ws->getCell([$cols['jabatan'], $r])->getValue());
            if ($name === '') {
                continue;
            }
            if (in_array($norm($name), ['JUMLAH', 'TOTAL'], true)) {
                break; // what follows (other plans, summaries) is not this table
            }
            if ($kategori === '') {
                if ($norm($name) !== 'SUB TOTAL') {
                    $departemen = $name;
                }

                continue;
            }
            $match = $jabatans[$norm($name)] ?? null;
            if (! $match) {
                $missing[] = ['baris' => $r, 'jabatan' => $name, 'departemen' => $departemen];

                continue;
            }
            $v = fn (?int $c) => $c ? $ws->getCell([$c, $r])->getCalculatedValue() : null;
            $internal = $int($v($cols['internal']));
            $eksternal = $int($v($cols['eksternal']));
            $fields = [
                'plan' => $int($v($cols['plan'])),
                'promosi' => $int($v($cols['promosi'])),
                'rotasi' => $int($v($cols['rotasi'])),
                'demosi' => $int($v($cols['demosi'])),
                'keluar' => $int($v($cols['keluar'])),
                'bulan_pindah' => $firstMonth($r, $cols['pindah']),
                'bulan_penuhi' => $firstMonth($r, $cols['penuhi']),
                'sumber' => $internal ? 'internal' : ($eksternal ? 'eksternal' : null),
                'keterangan' => ($k = trim((string) $v($cols['keterangan']))) !== '' ? mb_substr($k, 0, 255) : null,
            ];
            foreach ($match as $j) {
                $items[$j->id] = $fields;
            }
        }

        return ['items' => $items, 'cocok' => count($items), 'tidak_cocok' => $missing];
    }

    /** Where each column is, from the header rows (and the first data row under them). */
    private static function columns(Worksheet $ws): array
    {
        $found = ['jabatan' => 2, 'plan' => null, 'promosi' => null, 'rotasi' => null, 'demosi' => null, 'keluar' => null, 'internal' => null, 'eksternal' => null, 'keterangan' => null, 'pindah' => null, 'penuhi' => null];
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
                    $text === 'JABATAN' => $found['jabatan'] = $c,
                    $text === 'PLAN' => $set('plan'),
                    str_starts_with($text, 'PROMOSI') => $set('promosi'),
                    str_starts_with($text, 'ROTASI') => $set('rotasi'),
                    str_starts_with($text, 'DEMOSI') => $set('demosi'),
                    $text === 'KELUAR' => $set('keluar'),
                    $text === 'INTERNAL' => $set('internal'),
                    $text === 'EKSTERNAL' => $set('eksternal'),
                    $text === 'KETERANGAN' => $set('keterangan'),
                    str_starts_with($text, 'WAKTU PROMOSI') => $set('pindah'),
                    str_starts_with($text, 'WAKTU PEMENUHAN') => $set('penuhi'),
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
