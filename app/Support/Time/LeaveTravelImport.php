<?php

namespace App\Support\Time;

use App\Http\Controllers\Time\LeaveTravelController;
use App\Models\Employee;
use App\Models\LeaveTravelRequest;
use App\Models\User;
use App\Support\Leave\LeaveLedger;
use App\Support\Excel\TableExcel;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Pengajuan Dinas / cuti in bulk from Excel: each row is checked as if HR typed it in
 * Cuti & Dinas (dates free, cuti balance, airports …), then saved as approved and its
 * days filled in Absensi. No signed form is needed for an imported row.
 */
class LeaveTravelImport
{
    public const MAX_ROWS = 1000;

    /** Header => field. A * marks a required column. */
    public const COLUMNS = [
        'Employee Number*' => 'employee_number',
        'Name' => 'name',
        'Type* (Business Trip / Leave)' => 'type',
        'Leave Category (Field / Annual / Long Service / Other)' => 'leave_category',
        'Start Date*' => 'start_date',
        'End Date*' => 'end_date',
        'Origin City*' => 'origin_city',
        'Destination City*' => 'destination_city',
        'Description' => 'description',
        'Departure Airport (code)' => 'departure_airport',
        'Arrival Airport (code)' => 'arrival_airport',
        'Return Date (round trip)' => 'return_date',
        'Return Departure Airport (code)' => 'return_departure_airport',
        'Return Arrival Airport (code)' => 'return_arrival_airport',
        'Train Ticket (Yes)' => 'needs_train',
        'Cash Advance (Rp)' => 'cash_advance_amount',
        'Outbound Ticket Issued (Yes)' => 'outbound_issued',
        'Return Ticket Issued (Yes)' => 'return_issued',
    ];

    /** Cell value of the Type column => type (English or Indonesian). */
    private const TYPES = ['business trip' => 'business_trip', 'business_trip' => 'business_trip', 'dinas' => 'business_trip', 'leave' => 'leave', 'cuti' => 'leave'];

    /** Leave categories in English as the template writes them. */
    private const CATEGORIES = ['field' => 'field', 'annual' => 'annual', 'long service' => 'long_service', 'long_service' => 'long_service', 'other' => 'other'];

    private const DATES = ['start_date', 'end_date', 'return_date'];

    public static function template(): StreamedResponse
    {
        $widths = [12, 26, 14, 22, 13, 13, 16, 16, 30, 12, 12, 13, 12, 12, 10, 14, 12, 12];
        $columns = array_map(fn (string $h, int $w) => [$h, $w, in_array(self::COLUMNS[$h], self::DATES, true) ? 'date' : 'text'], array_keys(self::COLUMNS), $widths);
        $contoh = [
            ['11524001', 'Contoh Dinas PP', 'Business Trip', null, '2026-11-02', '2026-11-05', 'Tanjung', 'Jakarta', 'Rapat vendor', 'TJG', 'CGK', '2026-11-05', 'CGK', 'TJG', null, '1500000', 'Ya', null],
            ['11524001', 'Contoh Cuti Lapangan', 'Leave', 'Field', '2026-12-01', '2026-12-17', 'Murung Raya', 'Banjarmasin', null, null, null, null, null, null, null, null, null, null],
            ['11524001', 'Contoh Dinas kereta', 'Business Trip', null, '2026-11-10', '2026-11-11', 'Jakarta', 'Bandung', 'Gambir → Bandung', null, null, null, null, null, 'Ya', null, null, null],
        ];

        return TableExcel::download('template-import-travel-requests.xlsx', [
            [
                'title' => 'Import Pengajuan Dinas',
                'info' => 'Isi mulai baris 5, satu baris satu pengajuan (maks. '.self::MAX_ROWS.'). Kolom bertanda * wajib. Lihat sheet Petunjuk.',
                'freeze' => 2,
                'columns' => $columns,
                'rows' => [],
            ],
            [
                'title' => 'Petunjuk',
                'info' => 'Contoh pengisian (jangan disalin ke sheet import tanpa mengganti NRP).',
                'columns' => $columns,
                'rows' => $contoh,
            ],
            [
                'title' => 'Aturan',
                'info' => 'Cara membaca tiap kolom',
                'columns' => [['Column', 34], ['Description', 110]],
                'rows' => [
                    ['Employee Number*', 'NRP karyawan aktif di lokasi yang boleh Anda kelola. Kolom Nama hanya pengingat, tidak dibaca.'],
                    ['Type*', 'Business Trip (dinas) atau Leave (cuti). "Dinas" / "Cuti" juga diterima.'],
                    ['Leave Category', 'Wajib untuk cuti: Field (lapangan), Annual (tahunan), Long Service (besar), atau Other (lainnya); nama Indonesia juga diterima. Saldo cuti dicek dan dipotong seperti input manual; Cuti Lapangan mendapat lumpsum sesuai Pengaturan Kompensasi.'],
                    ['Start / End / Return Date', 'Format tanggal Excel, atau teks 31.12.2026 / 31/12/2026 / 2026-12-31. Tanggal tidak boleh bentrok dengan dinas / cuti lain karyawan itu.'],
                    ['Departure / Arrival Airport', 'Kode bandara 3 huruf (mis. CGK, BDJ, TJG). Diisi = minta tiket pesawat pergi.'],
                    ['Return Date (round trip)', 'Diisi bersama Return Departure / Arrival Airport = tiket pesawat pulang-pergi.'],
                    ['Train Ticket', 'Yes = minta tiket kereta (khusus dinas); tulis stasiun di Description.'],
                    ['Cash Advance', 'Nominal uang muka dinas, angka saja.'],
                    ['… Ticket Issued', 'Yes = tiket itu sudah dibelikan.'],
                    ['Hasil', 'Semua baris disimpan sebagai disetujui dan langsung mengisi Absensi. Baris yang salah dilewati; perbaiki lalu import ulang baris itu saja.'],
                ],
            ],
        ]);
    }

    /**
     * Checks every row (and saves the valid ones when $simpan).
     *
     * @return array{rows: list<array>, ok: int, gagal: int}
     */
    public static function run(string $path, User $user, bool $save): array
    {
        $rows = self::read($path);
        $result = [];
        DB::beginTransaction();
        try {
            foreach ($rows as $baris => $row) {
                $employee = filled($row['employee_number']) ? Employee::where('employee_number', $row['employee_number'])->first() : null;
                $view = [
                    'rows' => $baris,
                    'employee_number' => $row['employee_number'],
                    'name' => $employee?->name ?? $row['name'],
                    'type' => $row['type'],
                    'start_date' => $row['start_date'],
                    'end_date' => $row['end_date'],
                    'destination' => $row['destination_city'],
                ];
                try {
                    if (! $employee) {
                        throw ValidationException::withMessages(['employee_number' => filled($row['employee_number']) ? "NRP {$row['employee_number']} tidak ditemukan di karyawan yang bisa Anda kelola." : 'NRP kosong.']);
                    }
                    DB::transaction(fn () => self::save($employee, $row, $user));
                    $result[] = $view + ['ok' => true, 'message' => []];
                } catch (ValidationException $e) {
                    $result[] = $view + ['ok' => false, 'message' => array_values(array_unique(array_merge(...array_values($e->errors()))))];
                }
            }
            $save ? DB::commit() : DB::rollBack();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $ok = count(array_filter($result, fn ($r) => $r['ok']));

        return ['rows' => $result, 'ok' => $ok, 'failed' => count($result) - $ok];
    }

    private static function save(Employee $employee, array $row, User $user): void
    {
        $category = self::category($row['leave_category']);
        $pesawat = filled($row['departure_airport']) || filled($row['arrival_airport']);
        $pp = $pesawat && (filled($row['return_date']) || filled($row['return_departure_airport']) || filled($row['return_arrival_airport']));
        $type = self::TYPES[Str::lower(trim((string) $row['type']))] ?? Str::lower(trim((string) $row['type']));
        $request = Request::create('/', 'POST', array_filter([
            'employee_id' => $employee->id,
            'type' => $type,
            'leave_category' => $type === 'leave' ? ($category ?? $row['leave_category']) : null,
            'start_date' => $row['start_date'],
            'end_date' => $row['end_date'],
            'origin_city' => $row['origin_city'],
            'destination_city' => $row['destination_city'],
            'description' => $row['description'],
            'needs_flight' => $pesawat ? '1' : '0',
            'departure_airport' => $pesawat ? Str::upper((string) $row['departure_airport']) : null,
            'arrival_airport' => $pesawat ? Str::upper((string) $row['arrival_airport']) : null,
            'is_round_trip' => $pp ? '1' : '0',
            'return_date' => $pp ? $row['return_date'] : null,
            'return_departure_airport' => $pp ? Str::upper((string) $row['return_departure_airport']) : null,
            'return_arrival_airport' => $pp ? Str::upper((string) $row['return_arrival_airport']) : null,
            'needs_train' => self::yes($row['needs_train']) ? '1' : '0',
            'needs_cash_advance' => filled($row['cash_advance_amount']) ? '1' : '0',
            'cash_advance_amount' => filled($row['cash_advance_amount']) ? preg_replace('/\D/', '', (string) $row['cash_advance_amount']) : null,
        ], fn ($v) => $v !== null && $v !== ''));

        $data = LeaveTravelController::validateTrip($request, null, attachment: false);
        LeaveTravelWriter::assertFree($data['employee_id'], $data['start_date'], $data['end_date']);
        LeaveTravelWriter::codeFor($data['type']);
        $now = now();
        $leave_travel = LeaveTravelRequest::create([
            ...$data,
            'cash_advance_status' => LeaveTravelController::initialCashAdvanceStatus($data['needs_cash_advance']),
            'created_by' => $user->id,
            'status' => 'approved',
            'source' => 'hr',
            'verified_by' => $user->id,
            'verified_at' => $now,
        ]);
        foreach (['outbound', 'return'] as $direction) {
            if (self::yes($row["{$direction}_issued"]) && $leave_travel->hasTicket($direction)) {
                $leave_travel->update(["{$direction}_ticket_issued_at" => $now, "{$direction}_ticket_issued_by" => $user->id]);
            }
        }
        LeaveTravelWriter::fill($leave_travel);
    }

    /**
     * The filled rows, by their row number in the sheet.
     *
     * @return array<int, array<string, ?string>>
     */
    private static function read(string $path): array
    {
        try {
            $ws = IOFactory::load($path)->getSheet(0);
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'File tidak bisa dibaca; pakai template Excel dari tombol "Unduh template".']);
        }
        // The header row: the one starting with NRP, in the first rows.
        $norm = fn ($v) => preg_replace('/[^a-z]/', '', Str::lower(preg_replace('/\(.*?\)|\*/', '', (string) $v)));
        $wanted = [];
        foreach (self::COLUMNS as $header => $field) {
            $wanted[$norm($header)] = $field;
        }
        $head = null;
        $map = [];
        $lastCol = Coordinate::columnIndexFromString($ws->getHighestColumn());
        for ($r = 1; $r <= 10 && ! $head; $r++) {
            if (($wanted[$norm($ws->getCell([1, $r])->getValue())] ?? null) !== 'employee_number') {
                continue;
            }
            $head = $r;
            for ($c = 1; $c <= $lastCol; $c++) {
                if ($field = $wanted[$norm($ws->getCell([$c, $r])->getValue())] ?? null) {
                    $map[$field] = $c;
                }
            }
        }
        $missing = array_diff(['employee_number', 'type', 'start_date', 'end_date', 'origin_city', 'destination_city'], array_keys($map));
        if (! $head || $missing) {
            throw ValidationException::withMessages(['file' => 'Kolom wajib tidak ditemukan (Employee Number, Type, Start Date, End Date, Origin City, Destination City); pakai template dari tombol "Unduh template".']);
        }

        $rows = [];
        for ($r = $head + 1, $last = $ws->getHighestDataRow(); $r <= $last; $r++) {
            $row = [];
            foreach (self::COLUMNS as $field) {
                $value = isset($map[$field]) ? $ws->getCell([$map[$field], $r])->getValue() : null;
                $row[$field] = in_array($field, self::DATES, true) ? self::date($value) : (is_null($value) ? null : trim((string) $value));
                if ($row[$field] === '') {
                    $row[$field] = null;
                }
            }
            if (! array_filter($row)) {
                continue;
            }
            $rows[$r] = $row;
            if (count($rows) > self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => 'Maksimal '.self::MAX_ROWS.' baris per import.']);
            }
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'Tidak ada baris data di bawah judul kolom.']);
        }

        return $rows;
    }

    /** An Excel date, or 31.12.2026 / 31/12/2026 / 2026-12-31; anything else is passed on (and refused by the check). */
    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
        }
        $value = trim((string) $value);
        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $value, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return $value;
    }

    /** "Field", "Lapangan", "Cuti Lapangan" → field. */
    private static function category(?string $value): ?string
    {
        $v = Str::lower(trim(Str::after(Str::lower((string) $value), 'cuti ')));
        if (isset(self::CATEGORIES[$v])) {
            return self::CATEGORIES[$v];
        }
        foreach (LeaveLedger::KATEGORI as $key => $label) {
            if ($v === $key || $v === Str::lower(Str::after($label, 'Cuti '))) {
                return $key;
            }
        }

        return null;
    }

    private static function yes(?string $value): bool
    {
        return in_array(Str::lower(trim((string) $value)), ['ya', 'y', 'yes', '1', 'true', 'sudah', 'done', 'v', '✓'], true);
    }
}
