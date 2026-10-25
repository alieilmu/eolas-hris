<?php

namespace App\Support\Employees;

use App\Models\LookupValue;
use App\Support\Organization\PositionComposer;
use Illuminate\Support\Facades\DB;

/**
 * Choice lists that HR maintains in Pengaturan Core HR. Each category knows
 * which columns hold its values, so a rename can follow through to existing
 * records and a value still in use cannot be removed.
 */
class LookupValues
{
    public const CATEGORIES = [
        'employee_class' => [
            'label' => 'Klasifikasi',
            'columns' => [['employee_profiles', 'employee_class']],
            'default' => ['Staff', 'Non Staff'],
        ],
        'technical_class' => [
            'label' => 'Klasifikasi Teknis',
            'columns' => [['employee_profiles', 'technical_class']],
            'default' => ['Staff/Operator', 'Mekanik/Nonom'],
        ],
        'religion' => [
            'label' => 'Agama',
            'columns' => [['employee_profiles', 'religion']],
            'default' => ['Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha', 'Khonghucu', 'Lainnya'],
        ],
        'education_level' => [
            'label' => 'Pendidikan Terakhir',
            'columns' => [['employee_profiles', 'education_level']],
            'default' => ['SD', 'SMP', 'SMA/SMK', 'D1', 'D2', 'D3', 'D4', 'S1', 'S2', 'S3'],
        ],
        'child_education_level' => [
            'label' => 'Pendidikan Anak',
            'columns' => [['employee_children', 'education_level']],
            'default' => ['Belum Sekolah', 'TK', 'SD', 'SMP', 'SMA/SMK', 'D3', 'S1', 'S2', 'S3'],
        ],
        'marital_status' => [
            'label' => 'Status Perkawinan',
            'columns' => [['employee_profiles', 'marital_status']],
            'default' => ['Belum Menikah', 'Menikah', 'Cerai Hidup', 'Cerai Mati'],
            // The completeness rules key off these two.
            'locked' => ['Belum Menikah', 'Menikah'],
        ],
        'shirt_size' => [
            'label' => 'Ukuran Baju',
            'columns' => [['employee_profiles', 'shirt_size']],
            'default' => ['S', 'M', 'L', 'XL', 'XXL', 'XXXL'],
        ],
        'pants_size' => [
            'label' => 'Ukuran Celana',
            'columns' => [['employee_profiles', 'pants_size']],
            'default' => ['26', '27', '28', '29', '30', '31', '32', '33', '34', '35', '36', '38', '40'],
        ],
        'shoe_size' => [
            'label' => 'Ukuran Sepatu',
            'columns' => [['employee_profiles', 'shoe_size']],
            'default' => ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45', '46'],
        ],
        'ppe_size' => [
            'label' => 'Ukuran APD',
            'columns' => [['employee_profiles', 'ppe_size']],
            'default' => ['S', 'M', 'L', 'XL', 'XXL', 'XXXL'],
        ],
        // The "bidang" half of composed position names (SECTION HEAD + PRODUCTION); renames re-compose them.
        'position_discipline' => [
            'label' => 'Nama Jabatan',
            'columns' => [['positions', 'discipline'], ['employees', 'discipline']],
            'default' => [],
            'refreshes_position' => true,
        ],
        'termination_reason' => [
            'label' => 'Alasan Keluar',
            'columns' => [['employees', 'termination_reason']],
            'default' => ['Resign', 'Efficiency', 'PKWT Berakhir', 'Mangkir', 'Indisipliner', 'Meninggal', 'Pensiun', 'Mutasi'],
        ],
        // Attendance: where a trip's money request stands; the first value is a new trip's status.
        'cash_advance_status' => [
            'label' => 'Status Pengajuan Uang',
            'columns' => [['leave_travel_requests', 'cash_advance_status']],
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
    public static function values(string $category): array
    {
        self::$cache ??= LookupValue::orderBy('sort_order')->orderBy('id')->get()
            ->groupBy('category')
            ->map(fn ($options) => $options->pluck('value')->all())
            ->all();

        return self::$cache[$category] ?? [];
    }

    /** Case-insensitive lookup; returns the value as spelled in the master list. */
    public static function canonical(string $category, string $value): ?string
    {
        foreach (self::values($category) as $option) {
            if (mb_strtolower($option) === mb_strtolower(trim($value))) {
                return $option;
            }
        }

        return null;
    }

    /** How many records currently hold this value. */
    public static function usage(string $category, string $nilai): int
    {
        return array_sum(array_map(
            fn (array $column) => DB::table($column[0])->where($column[1], $nilai)->count(),
            self::CATEGORIES[$category]['columns'],
        ));
    }

    /** Carries a renamed value through to the records that use it. */
    public static function renameUsages(string $category, string $old, string $new): void
    {
        foreach (self::CATEGORIES[$category]['columns'] as [$table, $column]) {
            DB::table($table)->where($column, $old)->update([$column => $new]);
        }
        if (self::CATEGORIES[$category]['refreshes_position'] ?? false) {
            self::flush();
            PositionComposer::refreshNames();
        }
    }

    public static function isLocked(string $category, string $nilai): bool
    {
        return in_array($nilai, self::CATEGORIES[$category]['locked'] ?? [], true);
    }

    /** Creates the default lists; existing values are left alone. */
    public static function seedDefaults(): void
    {
        foreach (self::CATEGORIES as $category => $config) {
            foreach ($config['default'] as $sort_order => $nilai) {
                LookupValue::firstOrCreate(
                    ['category' => $category, 'value' => $nilai],
                    ['sort_order' => $sort_order, 'is_system' => in_array($nilai, $config['locked'] ?? [], true)],
                );
            }
        }
    }
}
