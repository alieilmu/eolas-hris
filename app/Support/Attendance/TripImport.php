<?php

namespace App\Support\Attendance;

use App\Http\Controllers\Attendance\TripController;
use App\Models\Karyawan;
use App\Models\Trip;
use App\Models\User;
use App\Support\Cuti\Cuti;
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
 * Trip & Leave (dates free, cuti balance, airports …), then saved as approved and its
 * days filled in Absensi. No signed form is needed for an imported row.
 */
class TripImport
{
    public const MAX_ROWS = 1000;

    /** Header => field. A * marks a required column. */
    public const COLUMNS = [
        'NRP*' => 'nrp',
        'Nama' => 'nama',
        'Jenis* (Dinas / Cuti)' => 'jenis',
        'Jenis Cuti (Lapangan / Tahunan / Besar / Lainnya)' => 'kategori_cuti',
        'Tanggal Awal*' => 'tanggal_awal',
        'Tanggal Akhir*' => 'tanggal_akhir',
        'Kota Asal*' => 'kota_asal',
        'Kota Tujuan*' => 'kota_tujuan',
        'Keterangan' => 'keterangan',
        'Bandara Asal (kode)' => 'bandara_asal',
        'Bandara Tujuan (kode)' => 'bandara_tujuan',
        'Tgl Pulang (PP)' => 'pulang_tanggal',
        'Bandara Pulang Asal (kode)' => 'pulang_bandara_asal',
        'Bandara Pulang Tujuan (kode)' => 'pulang_bandara_tujuan',
        'Tiket Kereta (Ya)' => 'tiket_kereta',
        'Uang Muka (Rp)' => 'uang_muka_nominal',
        'Tiket Pergi Sudah Dibeli (Ya)' => 'pergi_dibeli',
        'Tiket Pulang Sudah Dibeli (Ya)' => 'pulang_dibeli',
    ];

    private const DATES = ['tanggal_awal', 'tanggal_akhir', 'pulang_tanggal'];

    public static function template(): StreamedResponse
    {
        $widths = [12, 26, 14, 22, 13, 13, 16, 16, 30, 12, 12, 13, 12, 12, 10, 14, 12, 12];
        $columns = array_map(fn (string $h, int $w) => [$h, $w, in_array(self::COLUMNS[$h], self::DATES, true) ? 'date' : 'text'], array_keys(self::COLUMNS), $widths);
        $contoh = [
            ['11524001', 'Contoh Dinas PP', 'Dinas', null, '2026-11-02', '2026-11-05', 'Tanjung', 'Jakarta', 'Rapat vendor', 'TJG', 'CGK', '2026-11-05', 'CGK', 'TJG', null, '1500000', 'Ya', null],
            ['11524001', 'Contoh Cuti Lapangan', 'Cuti', 'Lapangan', '2026-12-01', '2026-12-17', 'Murung Raya', 'Banjarmasin', null, null, null, null, null, null, null, null, null, null],
            ['11524001', 'Contoh Dinas kereta', 'Dinas', null, '2026-11-10', '2026-11-11', 'Jakarta', 'Bandung', 'Gambir → Bandung', null, null, null, null, null, 'Ya', null, null, null],
        ];

        return TableExcel::download('template-import-pengajuan-dinas.xlsx', [
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
                'columns' => [['Kolom', 34], ['Keterangan', 110]],
                'rows' => [
                    ['NRP*', 'NRP karyawan aktif di lokasi yang boleh Anda kelola. Kolom Nama hanya pengingat, tidak dibaca.'],
                    ['Jenis*', 'Dinas atau Cuti.'],
                    ['Jenis Cuti', 'Wajib untuk Cuti: Lapangan, Tahunan, Besar, atau Lainnya. Saldo cuti dicek dan dipotong seperti input manual; Cuti Lapangan mendapat lumpsum sesuai Master Data Comben.'],
                    ['Tanggal', 'Format tanggal Excel, atau teks 31.12.2026 / 31/12/2026 / 2026-12-31. Tanggal tidak boleh bentrok dengan dinas / cuti lain karyawan itu.'],
                    ['Bandara Asal / Tujuan', 'Kode bandara 3 huruf (mis. CGK, BDJ, TJG). Diisi = minta tiket pesawat pergi.'],
                    ['Tgl Pulang (PP)', 'Diisi bersama Bandara Pulang Asal / Tujuan = tiket pesawat pulang-pergi.'],
                    ['Tiket Kereta', 'Ya = minta tiket kereta (khusus dinas); tulis stasiun di Keterangan.'],
                    ['Uang Muka', 'Nominal uang muka dinas, angka saja.'],
                    ['Tiket … Sudah Dibeli', 'Ya = tiket itu sudah dibelikan.'],
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
    public static function run(string $path, User $user, bool $simpan): array
    {
        $rows = self::read($path);
        $result = [];
        DB::beginTransaction();
        try {
            foreach ($rows as $baris => $row) {
                $karyawan = filled($row['nrp']) ? Karyawan::where('nrp', $row['nrp'])->first() : null;
                $view = [
                    'baris' => $baris,
                    'nrp' => $row['nrp'],
                    'nama' => $karyawan?->name ?? $row['nama'],
                    'jenis' => $row['jenis'],
                    'tanggal_awal' => $row['tanggal_awal'],
                    'tanggal_akhir' => $row['tanggal_akhir'],
                    'tujuan' => $row['kota_tujuan'],
                ];
                try {
                    if (! $karyawan) {
                        throw ValidationException::withMessages(['nrp' => filled($row['nrp']) ? "NRP {$row['nrp']} tidak ditemukan di karyawan yang bisa Anda kelola." : 'NRP kosong.']);
                    }
                    DB::transaction(fn () => self::save($karyawan, $row, $user));
                    $result[] = $view + ['ok' => true, 'pesan' => []];
                } catch (ValidationException $e) {
                    $result[] = $view + ['ok' => false, 'pesan' => array_values(array_unique(array_merge(...array_values($e->errors()))))];
                }
            }
            $simpan ? DB::commit() : DB::rollBack();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $ok = count(array_filter($result, fn ($r) => $r['ok']));

        return ['rows' => $result, 'ok' => $ok, 'gagal' => count($result) - $ok];
    }

    private static function save(Karyawan $karyawan, array $row, User $user): void
    {
        $kategori = self::kategori($row['kategori_cuti']);
        $pesawat = filled($row['bandara_asal']) || filled($row['bandara_tujuan']);
        $pp = $pesawat && (filled($row['pulang_tanggal']) || filled($row['pulang_bandara_asal']) || filled($row['pulang_bandara_tujuan']));
        $jenis = Str::lower(trim((string) $row['jenis']));
        $request = Request::create('/', 'POST', array_filter([
            'karyawan_id' => $karyawan->id,
            'jenis' => $jenis,
            'kategori_cuti' => $jenis === 'cuti' ? ($kategori ?? $row['kategori_cuti']) : null,
            'tanggal_awal' => $row['tanggal_awal'],
            'tanggal_akhir' => $row['tanggal_akhir'],
            'kota_asal' => $row['kota_asal'],
            'kota_tujuan' => $row['kota_tujuan'],
            'keterangan' => $row['keterangan'],
            'tiket_pesawat' => $pesawat ? '1' : '0',
            'bandara_asal' => $pesawat ? Str::upper((string) $row['bandara_asal']) : null,
            'bandara_tujuan' => $pesawat ? Str::upper((string) $row['bandara_tujuan']) : null,
            'pesawat_pp' => $pp ? '1' : '0',
            'pulang_tanggal' => $pp ? $row['pulang_tanggal'] : null,
            'pulang_bandara_asal' => $pp ? Str::upper((string) $row['pulang_bandara_asal']) : null,
            'pulang_bandara_tujuan' => $pp ? Str::upper((string) $row['pulang_bandara_tujuan']) : null,
            'tiket_kereta' => self::ya($row['tiket_kereta']) ? '1' : '0',
            'uang_muka' => filled($row['uang_muka_nominal']) ? '1' : '0',
            'uang_muka_nominal' => filled($row['uang_muka_nominal']) ? preg_replace('/\D/', '', (string) $row['uang_muka_nominal']) : null,
        ], fn ($v) => $v !== null && $v !== ''));

        $data = TripController::validateTrip($request, null, lampiran: false);
        TripWriter::assertFree($data['karyawan_id'], $data['tanggal_awal'], $data['tanggal_akhir']);
        TripWriter::codeFor($data['jenis']);
        $now = now();
        $trip = Trip::create([
            ...$data,
            'status_uang' => TripController::statusUangAwal($data['uang_muka']),
            'created_by' => $user->id,
            'status' => 'disetujui',
            'sumber' => 'hr',
            'verified_by' => $user->id,
            'verified_at' => $now,
        ]);
        foreach (['pergi', 'pulang'] as $arah) {
            if (self::ya($row["{$arah}_dibeli"]) && $trip->adaTiket($arah)) {
                $trip->update(["tiket_{$arah}_dibeli_at" => $now, "tiket_{$arah}_dibeli_by" => $user->id]);
            }
        }
        TripWriter::fill($trip);
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
            if ($norm($ws->getCell([1, $r])->getValue()) !== 'nrp') {
                continue;
            }
            $head = $r;
            for ($c = 1; $c <= $lastCol; $c++) {
                if ($field = $wanted[$norm($ws->getCell([$c, $r])->getValue())] ?? null) {
                    $map[$field] = $c;
                }
            }
        }
        $missing = array_diff(['nrp', 'jenis', 'tanggal_awal', 'tanggal_akhir', 'kota_asal', 'kota_tujuan'], array_keys($map));
        if (! $head || $missing) {
            throw ValidationException::withMessages(['file' => 'Kolom wajib tidak ditemukan (NRP, Jenis, Tanggal Awal, Tanggal Akhir, Kota Asal, Kota Tujuan); pakai template dari tombol "Unduh template".']);
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

    /** "Lapangan", "Cuti Lapangan", "lapangan" → lapangan. */
    private static function kategori(?string $value): ?string
    {
        $v = Str::lower(trim(Str::after(Str::lower((string) $value), 'cuti ')));
        foreach (Cuti::KATEGORI as $key => $label) {
            if ($v === $key || $v === Str::lower(Str::after($label, 'Cuti '))) {
                return $key;
            }
        }

        return null;
    }

    private static function ya(?string $value): bool
    {
        return in_array(Str::lower(trim((string) $value)), ['ya', 'y', 'yes', '1', 'true', 'sudah', 'v', '✓'], true);
    }
}
