<?php

namespace App\Support\Compensation;

use App\Models\Grade;
use App\Models\Employee;
use App\Models\OpticalBenefitLimit;
use App\Models\MedicalBenefitLimit;
use Illuminate\Support\Collection;

/**
 * What an employee is entitled to, from Pengaturan Kompensasi:
 *  - the plafon row is the one whose SubGroups include the employee's SubGroup;
 *    their golongan is only checked against the row's range;
 *  - Plafon Pengobatan is per family: "keluarga" when married or with children
 *    (status K/n, or TK/n with n > 0), "lajang" otherwise (TK/0);
 *  - Plafon Kacamata is per family member: the employee, the spouse when
 *    married, and each registered child.
 * Expects karyawans with subGroup and profile.anak loaded.
 */
class BenefitEntitlement
{
    private static ?self $shared = null;

    /**
     * @param  Collection<int, PlafonPengobatan>  $pengobatan
     * @param  Collection<int, PlafonKacamata>  $kacamata
     * @param  array<string, int>  $golonganId  kode => id
     */
    private function __construct(
        private readonly Collection $medical,
        private readonly Collection $optical,
        private readonly array $gradeId,
    ) {}

    /** Loaded once and reused until Pengaturan Kompensasi changes (see flush()). */
    public static function shared(): self
    {
        return self::$shared ??= new self(
            MedicalBenefitLimit::orderBy('sort_order')->orderBy('id')->get(),
            OpticalBenefitLimit::orderBy('sort_order')->orderBy('id')->get(),
            Grade::pluck('id', 'code')->all(),
        );
    }

    public static function flush(): void
    {
        self::$shared = null;
    }

    public static function kawin(Employee $k): bool
    {
        return $k->profile?->marital_status === 'Menikah';
    }

    public static function childrenCount(Employee $k): int
    {
        return $k->profile?->children->count() ?? 0;
    }

    /** "K/2": Kawin / Tidak Kawin, and the number of registered children. */
    public static function statusPtkp(Employee $k): string
    {
        return (self::kawin($k) ? 'K' : 'TK').'/'.self::childrenCount($k);
    }

    public static function family(Employee $k): bool
    {
        return self::kawin($k) || self::childrenCount($k) > 0;
    }

    public function medicalRule(Employee $k): ?MedicalBenefitLimit
    {
        return $this->medical->first(fn (MedicalBenefitLimit $p) => in_array($k->job_level_id, $p->job_level_ids, true));
    }

    public function opticalRule(Employee $k): ?OpticalBenefitLimit
    {
        return $this->optical->first(fn (OpticalBenefitLimit $p) => in_array($k->job_level_id, $p->job_level_ids, true));
    }

    /** The yearly Plafon Pengobatan of the family; null when the SubGroup has no plafon row. */
    public function medicalBenefitLimit(Employee $k): ?int
    {
        $rule = $this->medicalRule($k);

        return $rule ? (self::family($k) ? $rule->family : $rule->single) : null;
    }

    /** Why the plafon may be wrong: no row for the SubGroup, or a golongan outside the row's range. */
    public function warning(Employee $k): ?string
    {
        $rule = $this->medicalRule($k) ?? $this->opticalRule($k);
        if (! $rule) {
            return 'SubGroup '.($k->jobLevel?->name ?? '-').' belum punya plafon di Pengaturan Kompensasi.';
        }
        $code = $k->profile?->grade;
        if (! $code) {
            return 'Golongan belum diisi di profil karyawan.';
        }
        if (! in_array($this->gradeId[$code] ?? null, $rule->grade_ids, true)) {
            return "Golongan {$code} di luar rentang plafon {$rule->layer} untuk SubGroup ".($k->jobLevel?->name ?? '-').'.';
        }

        return null;
    }

    /**
     * The family members who can claim Kacamata.
     *
     * @return list<array{key: string, penerima: string, anak_id: ?int, nama: string, hubungan: string}>
     */
    public static function members(Employee $k): array
    {
        $members = [['key' => 'employee', 'beneficiary' => 'employee', 'child_id' => null, 'name' => $k->name, 'relationship' => 'Karyawan']];
        if (self::kawin($k)) {
            $members[] = ['key' => 'spouse', 'beneficiary' => 'spouse', 'child_id' => null, 'name' => $k->profile?->spouse_name ?: '(nama belum diisi)', 'relationship' => 'Istri / Suami'];
        }
        foreach ($k->profile?->children ?? [] as $children) {
            $members[] = ['key' => "children:{$children->id}", 'beneficiary' => 'children', 'child_id' => $children->id, 'name' => $children->name ?: "Anak {$children->sort_order}", 'relationship' => "Anak {$children->sort_order}"];
        }

        return $members;
    }
}
