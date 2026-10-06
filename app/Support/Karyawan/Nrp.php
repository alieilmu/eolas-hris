<?php

namespace App\Support\Karyawan;

use App\Models\JenisKaryawan;
use App\Models\Karyawan;
use App\Models\LokasiPenerimaan;
use Carbon\CarbonInterface;

/**
 * NRP = J LL YY NNN (8 digits):
 *   J   jenis karyawan      (1 Karyawan Normal, 7 Tenaga Ahli, 8 Mekanik Magang, 9 Operator Magang)
 *   LL  lokasi penerimaan   (e.g. 15 Murung Raya, 03 Rantau, 01/11 Head Office)
 *   YY  tahun masuk         (2 digits)
 *   NNN nomor urut
 * Jenis karyawan, tempat penerimaan and status asal are read from it.
 */
class Nrp
{
    public const LENGTH = 8;

    /** @var array<string, string>|null kode => nama */
    private static ?array $jenis = null;

    /** @var array<string, array{nama: string, work_location_id: ?int}>|null */
    private static ?array $lokasi = null;

    /** Called when a code is added, changed or removed. */
    public static function flush(): void
    {
        self::$jenis = null;
        self::$lokasi = null;
    }

    /** @return array<string, string> */
    public static function jenisMap(): array
    {
        return self::$jenis ??= JenisKaryawan::orderBy('kode')->pluck('nama', 'kode')->all();
    }

    /** @return array<string, array{nama: string, work_location_id: ?int}> */
    public static function lokasiMap(): array
    {
        return self::$lokasi ??= LokasiPenerimaan::orderBy('kode')->get()
            ->mapWithKeys(fn (LokasiPenerimaan $l) => [$l->kode => ['nama' => $l->nama, 'work_location_id' => $l->work_location_id]])
            ->all();
    }

    /**
     * Why this NRP does not follow the pattern, or null when it does. Used to
     * reject new employees; the message names the offending digits.
     */
    public static function error(string $nrp): ?string
    {
        $nrp = trim($nrp);

        if (! preg_match('/^\d{'.self::LENGTH.'}$/', $nrp)) {
            return 'NRP harus '.self::LENGTH.' digit angka: jenis karyawan (1) + lokasi penerimaan (2) + tahun masuk (2) + nomor urut (3), contoh 11524001.';
        }

        $jenis = substr($nrp, 0, 1);
        if (! isset(self::jenisMap()[$jenis])) {
            $codes = implode(', ', array_keys(self::jenisMap()));

            return "Digit ke-1 NRP \"{$nrp}\" adalah \"{$jenis}\", bukan kode jenis karyawan yang terdaftar ({$codes}). Periksa NRP atau tambahkan kodenya di Master Data Personalia.";
        }

        $lokasi = substr($nrp, 1, 2);
        if (! isset(self::lokasiMap()[$lokasi])) {
            $codes = implode(', ', array_keys(self::lokasiMap()));

            return "Digit ke-2–3 NRP \"{$nrp}\" adalah \"{$lokasi}\", bukan kode lokasi penerimaan yang terdaftar ({$codes}). Periksa NRP atau tambahkan kodenya di Master Data Personalia.";
        }

        return null;
    }

    /**
     * Digits 4–5 should be the year of Tanggal Masuk. Only a warning: an NRP
     * issued late in December for a January start is legitimate.
     */
    public static function yearWarning(string $nrp, ?CarbonInterface $tanggalMasuk): ?string
    {
        if (! $tanggalMasuk || ! preg_match('/^\d{8}$/', $nrp)) {
            return null;
        }

        $yy = substr($nrp, 3, 2);

        return $yy === $tanggalMasuk->format('y')
            ? null
            : "Digit ke-4–5 NRP {$nrp} (\"{$yy}\") tidak sama dengan tahun Tanggal Masuk ({$tanggalMasuk->year}). Pastikan NRP atau tanggal masuknya benar.";
    }

    /** "Lokal" when hired at the location the employee works at, "Kiriman" otherwise; null when unknown. */
    public static function statusAsal(string $nrp, ?int $workLocationId): ?string
    {
        $lokasi = self::lokasiMap()[substr($nrp, 1, 2)] ?? null;

        if (! $lokasi || ! $lokasi['work_location_id'] || ! $workLocationId) {
            return null;
        }

        return $lokasi['work_location_id'] === $workLocationId ? 'Lokal' : 'Kiriman';
    }

    /**
     * Values read from the NRP, for display and the dashboard.
     *
     * @return array{jenis_karyawan: ?string, tempat_penerimaan: ?string, status_asal: ?string}
     */
    public static function derived(Karyawan $karyawan): array
    {
        return [
            'jenis_karyawan' => self::jenisMap()[substr($karyawan->nrp, 0, 1)] ?? null,
            'tempat_penerimaan' => self::lokasiMap()[substr($karyawan->nrp, 1, 2)]['nama'] ?? null,
            'status_asal' => self::statusAsal($karyawan->nrp, $karyawan->work_location_id),
        ];
    }

    /** Next free NRP for a jenis, lokasi and year, or null once all 999 numbers are used. */
    public static function next(string $jenis, string $lokasi, int $year): ?string
    {
        $prefix = $jenis.$lokasi.sprintf('%02d', $year % 100);
        $max = (int) Karyawan::withoutGlobalScope('akses')->where('nrp', 'like', "{$prefix}___")->max('nrp');
        $urut = $max ? (int) substr((string) $max, -3) + 1 : 1;

        return $urut > 999 ? null : $prefix.sprintf('%03d', $urut);
    }

    /**
     * Highest nomor urut used per 5-digit prefix (J LL YY), so a form can suggest
     * the next NRP without a round trip.
     *
     * @return array<string, int>
     */
    public static function maxUrutPerPrefix(): array
    {
        return Karyawan::withoutGlobalScope('akses')->pluck('nrp')
            ->filter(fn (string $nrp) => preg_match('/^\d{8}$/', $nrp))
            ->groupBy(fn (string $nrp) => substr($nrp, 0, 5))
            ->map(fn ($group) => $group->map(fn (string $nrp) => (int) substr($nrp, 5))->max())
            ->all();
    }
}
