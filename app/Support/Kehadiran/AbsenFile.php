<?php

namespace App\Support\Kehadiran;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reads clock-ins from a file downloaded from a machine or its software: the machine's own
 * attlog (.dat / .txt, tab separated), CSV, or Excel. Per row: PIN/NRP, then either a
 * date-time, or a date and a time in two columns. A header row is skipped.
 */
class AbsenFile
{
    /** @return array{rows: list<array{pin: string, waktu: string, status: ?int}>, salah: list<int>} */
    public static function read(string $path, string $extension): array
    {
        $lines = in_array(strtolower($extension), ['xlsx', 'xls'], true) ? self::sheet($path) : self::text($path);
        $rows = [];
        $salah = [];
        foreach ($lines as $n => $cells) {
            $cells = array_values(array_map(fn ($c) => is_string($c) ? trim($c) : $c, $cells));
            if (count(array_filter($cells, fn ($c) => $c !== null && $c !== '')) === 0) {
                continue;
            }
            $pin = (string) ($cells[0] ?? '');
            $waktu = self::waktu($cells[1] ?? null) ?? self::waktu(self::join($cells[1] ?? null, $cells[2] ?? null));
            if ($pin === '' || ! $waktu) {
                if ($n > 0) {
                    $salah[] = $n + 1;
                }

                continue;
            }
            $rows[] = ['pin' => $pin, 'waktu' => $waktu, 'status' => null];
        }

        return ['rows' => $rows, 'salah' => $salah];
    }

    private static function text(string $path): array
    {
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $out[] = str_contains($line, "\t") ? explode("\t", $line) : str_getcsv($line, str_contains($line, ';') ? ';' : ',');
        }

        return $out;
    }

    private static function sheet(string $path): array
    {
        return IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
    }

    private static function waktu(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v) && (float) $v > 20000) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $v))->format('Y-m-d H:i:s');
        }

        return Adms::waktu((string) $v);
    }

    private static function join(mixed $date, mixed $time): ?string
    {
        if ($date === null || $time === null || $date === '' || $time === '') {
            return null;
        }
        if (is_numeric($date) && (float) $date > 20000) {
            $date = CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $date))->format('Y-m-d');
        }
        if (is_numeric($time) && (float) $time < 1) {
            $s = (int) round((float) $time * 86400);
            $time = sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
        }

        return trim($date.' '.$time);
    }
}
