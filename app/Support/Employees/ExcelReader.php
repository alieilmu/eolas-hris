<?php

namespace App\Support\Employees;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

/** Reads the "Employee Data" sheet (or the first sheet) into rows keyed by English column header. */
class ExcelReader
{
    /**
     * Cells come back raw: dates as Excel serial numbers, never formatted
     * strings, so no timezone or locale can shift them.
     *
     * @return list<array<string, mixed>> each row also carries "__row", its sheet row number
     */
    public static function read(UploadedFile $file): array
    {
        $type = match (strtolower($file->getClientOriginalExtension())) {
            'xls' => 'Xls',
            'csv' => 'Csv',
            default => 'Xlsx',
        };

        $reader = IOFactory::createReader($type);
        $reader->setReadDataOnly(true);
        $book = $reader->load($file->getRealPath());

        $sheet = $book->getSheetByName(ExcelSchema::DATA_SHEET) ?? $book->getSheetByName(ExcelSchema::LEGACY_DATA_SHEET) ?? $book->getSheet(0);
        $grid = $sheet->toArray(null, true, false, false);

        // The column-title row is the one holding "Employee Number" (or "NRP" in an older
        // file): row 2 in our files (under the group bands), row 1 in a plain single-header sheet.
        $legacy = ExcelSchema::legacyHeaders();
        $header = fn (mixed $h) => ExcelSchema::readHeader($h, $legacy);
        $headerIndex = 0;
        foreach (array_slice($grid, 0, 5) as $i => $cells) {
            if (in_array(ExcelSchema::KEY_LABEL, array_map($header, $cells), true)) {
                $headerIndex = $i;
                break;
            }
        }

        $headers = array_map($header, $grid[$headerIndex] ?? []);
        $grid = array_slice($grid, $headerIndex + 1, preserve_keys: true);

        $rows = [];
        foreach ($grid as $i => $cells) {
            $row = [];
            foreach ($headers as $c => $header) {
                if ($header !== '') {
                    $row[$header] = $cells[$c] ?? null;
                }
            }

            $hasData = array_filter($row, fn ($v) => $v !== null && (! is_string($v) || trim($v) !== ''));
            if ($hasData) {
                $rows[] = $row + ['__row' => $i + 1]; // grid index is 0-based, sheet rows 1-based
            }
        }

        return $rows;
    }
}
