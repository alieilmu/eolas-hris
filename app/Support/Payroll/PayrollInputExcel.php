<?php

namespace App\Support\Payroll;

use App\Models\PayrollComponent;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Input Variabel in Excel: a template (one row per amount: Employee Number, Component Code,
 * Amount, Notes, with a sheet listing the component codes) and reading it back.
 */
class PayrollInputExcel
{
    public const HEADERS = ['Employee Number', 'Component Code', 'Amount', 'Notes'];

    public static function template(string $period): StreamedResponse
    {
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet()->setTitle('Payroll Inputs');
        $ws->fromArray(self::HEADERS, null, 'A1');
        $ws->fromArray(['10103101', 'bonus', 1500000, 'Contoh: hapus baris ini'], null, 'A2');
        $ws->getStyle('A1:D1')->getFont()->setBold(true);
        $ws->getStyle('A1:D1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E3F3E8');
        $ws->getStyle('A:A')->getNumberFormat()->setFormatCode('@');
        foreach (['A' => 18, 'B' => 22, 'C' => 16, 'D' => 40] as $col => $w) {
            $ws->getColumnDimension($col)->setWidth($w);
        }
        $codes = $book->createSheet()->setTitle('Components');
        $codes->fromArray(['Code', 'Name', 'Kind', 'Taxable'], null, 'A1');
        $codes->getStyle('A1:D1')->getFont()->setBold(true);
        $r = 2;
        foreach (PayrollComponent::where('is_active', true)->orderBy('sort_order')->get() as $c) {
            $codes->fromArray([$c->code, $c->name, $c->kind, $c->taxable ? 'yes' : 'no'], null, "A{$r}");
            $r++;
        }
        foreach (['A' => 22, 'B' => 28, 'C' => 12, 'D' => 10] as $col => $w) {
            $codes->getColumnDimension($col)->setWidth($w);
        }
        $book->setActiveSheetIndex(0);

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), "payroll-inputs-{$period}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @return list<array{row: int, employee_number: string, code: string, amount: mixed, notes: ?string}> */
    public static function read(UploadedFile $file): array
    {
        $reader = IOFactory::createReaderForFile($file->getRealPath());
        $reader->setReadDataOnly(true);
        $grid = $reader->load($file->getRealPath())->getSheet(0)->toArray(null, true, false, false);
        $head = array_map(fn ($h) => strtolower(trim((string) $h)), $grid[0] ?? []);
        $col = fn (string $name) => array_search(strtolower($name), $head, true);
        [$nrp, $code, $amount, $notes] = array_map($col, self::HEADERS);
        if ($nrp === false || $code === false || $amount === false) {
            throw new \InvalidArgumentException('Kolom wajib tidak ditemukan: '.implode(', ', array_slice(self::HEADERS, 0, 3)).'. Pakai template.');
        }
        $rows = [];
        foreach (array_slice($grid, 1) as $i => $cells) {
            if (trim((string) ($cells[$nrp] ?? '')) === '' && trim((string) ($cells[$code] ?? '')) === '' && ($cells[$amount] ?? null) === null) {
                continue;
            }
            $rows[] = [
                'row' => $i + 2,
                'employee_number' => trim((string) ($cells[$nrp] ?? '')),
                'code' => strtolower(trim((string) ($cells[$code] ?? ''))),
                'amount' => $cells[$amount] ?? null,
                'notes' => $notes !== false && filled($cells[$notes] ?? null) ? mb_substr(trim((string) $cells[$notes]), 0, 255) : null,
            ];
        }

        return $rows;
    }
}
