<?php

namespace App\Support\Karyawan;

use App\Models\MasterOption;
use App\Support\Org\JabatanComposer;
use Illuminate\Support\Facades\DB;

/**
 * Choice lists that HR maintains in Master Data Personalia. Each category knows
 * which columns hold its values, so a rename can follow through to existing
 * records and a value still in use cannot be removed.
 */
class MasterOptions
{
    public const CATEGORIES = [
        'klasifikasi' => [
            'label' => 'Klasifikasi',
            'columns' => [['karyawan_profiles', 'klasifikasi']],
            'default' => ['Staff', 'Non Staff'],
        ],
        'klasifikasi_teknis' => [
            'label' => 'Klasifikasi Teknis',
            'columns' => [['karyawan_profiles', 'klasifikasi_teknis']],
            'default' => ['Staff/Operator', 'Mekanik/Nonom'],
        ],
        'agama' => [
            'label' => 'Agama',
            'columns' => [['karyawan_profiles', 'agama']],
            'default' => ['Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha', 'Khonghucu', 'Lainnya'],
        ],
        'pendidikan' => [
            'label' => 'Pendidikan Terakhir',
            'columns' => [['karyawan_profiles', 'pendidikan_terakhir']],
            'default' => ['SD', 'SMP', 'SMA/SMK', 'D1', 'D2', 'D3', 'D4', 'S1', 'S2', 'S3'],
        ],
        'pendidikan_anak' => [
            'label' => 'Pendidikan Anak',
            'columns' => [['karyawan_anak', 'pendidikan']],
            'default' => ['Belum Sekolah', 'TK', 'SD', 'SMP', 'SMA/SMK', 'D3', 'S1', 'S2', 'S3'],
        ],
        'status_perkawinan' => [
            'label' => 'Status Perkawinan',
            'columns' => [['karyawan_profiles', 'status_perkawinan']],
            'default' => ['Belum Menikah', 'Menikah', 'Cerai Hidup', 'Cerai Mati'],
            // The completeness rules key off these two.
            'locked' => ['Belum Menikah', 'Menikah'],
        ],
        'ukuran_baju' => [
            'label' => 'Ukuran Baju',
            'columns' => [['karyawan_profiles', 'ukuran_baju']],
            'default' => ['S', 'M', 'L', 'XL', 'XXL', 'XXXL'],
        ],
        'ukuran_celana' => [
            'label' => 'Ukuran Celana',
            'columns' => [['karyawan_profiles', 'ukuran_celana']],
            'default' => ['26', '27', '28', '29', '30', '31', '32', '33', '34', '35', '36', '38', '40'],
        ],
        'ukuran_sepatu' => [
            'label' => 'Ukuran Sepatu',
            'columns' => [['karyawan_profiles', 'ukuran_sepatu']],
            'default' => ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46'],
        ],
        'ukuran_apd' => [
            'label' => 'Ukuran APD',
            'columns' => [['karyawan_profiles', 'ukuran_apd']],
            'default' => ['S', 'M', 'L', 'XL', 'XXL', 'XXXL'],
        ],
        // The "bidang" half of composed position names (SECTION HEAD + PRODUCTION); renames re-compose them.
        'bidang_jabatan' => [
            'label' => 'Nama Jabatan',
            'columns' => [['jabatans', 'bidang'], ['karyawans', 'bidang']],
            'default' => [],
            'refreshes_jabatan' => true,
        ],
        'alasan_keluar' => [
            'label' => 'Alasan Keluar',
            'columns' => [['karyawans', 'alasan_keluar']],
            'default' => ['Resign', 'Efficiency', 'PKWT Berakhir', 'Mangkir', 'Indisipliner', 'Meninggal', 'Pensiun', 'Mutasi'],
        ],
        // Attendance: where a trip's money request stands; the first value is a new trip's status.
        'status_uang' => [
            'label' => 'Status Pengajuan Uang',
            'columns' => [['trips', 'status_uang']],
            'default' => ['Belum diajukan', 'Diajukan', 'Disetujui', 'Dicairkan', 'Selesai', 'Ditolak'],
        ],
    ];

    /** @var array<string, list<string>>|null */
    private static ?array $cache = null;

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** @return list<string> the values of one category, in display order */
    public static function values(string $kategori): array
    {
        self::$cache ??= MasterOption::orderBy('urutan')->orderBy('id')->get()
            ->groupBy('kategori')
            ->map(fn ($options) => $options->pluck('nilai')->all())
            ->all();

        return self::$cache[$kategori] ?? [];
    }

    /** Case-insensitive lookup; returns the value as spelled in the master list. */
    public static function canonical(string $kategori, string $value): ?string
    {
        foreach (self::values($kategori) as $option) {
            if (mb_strtolower($option) === mb_strtolower(trim($value))) {
                return $option;
            }
        }

        return null;
    }

    /** How many records currently hold this value. */
    public static function usage(string $kategori, string $nilai): int
    {
        return array_sum(array_map(
            fn (array $column) => DB::table($column[0])->where($column[1], $nilai)->count(),
            self::CATEGORIES[$kategori]['columns'],
        ));
    }

    /** Carries a renamed value through to the records that use it. */
    public static function renameUsages(string $kategori, string $old, string $new): void
    {
        foreach (self::CATEGORIES[$kategori]['columns'] as [$table, $column]) {
            DB::table($table)->where($column, $old)->update([$column => $new]);
        }
        if (self::CATEGORIES[$kategori]['refreshes_jabatan'] ?? false) {
            self::flush();
            JabatanComposer::refreshNames();
        }
    }

    public static function isLocked(string $kategori, string $nilai): bool
    {
        return in_array($nilai, self::CATEGORIES[$kategori]['locked'] ?? [], true);
    }

    /** Creates the default lists; existing values are left alone. */
    public static function seedDefaults(): void
    {
        foreach (self::CATEGORIES as $kategori => $config) {
            foreach ($config['default'] as $urutan => $nilai) {
                MasterOption::firstOrCreate(
                    ['kategori' => $kategori, 'nilai' => $nilai],
                    ['urutan' => $urutan, 'sistem' => in_array($nilai, $config['locked'] ?? [], true)],
                );
            }
        }
    }
}
