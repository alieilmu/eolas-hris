<?php

namespace App\Support\Comben;

use App\Models\Golongan;
use App\Models\Karyawan;
use App\Models\PlafonKacamata;
use App\Models\PlafonPengobatan;
use Illuminate\Support\Collection;

/**
 * What an employee is entitled to, from Master Data Comben:
 *  - the plafon row is the one whose SubGroups include the employee's SubGroup;
 *    their golongan is only checked against the row's range;
 *  - Plafon Pengobatan is per family: "keluarga" when married or with children
 *    (status K/n, or TK/n with n > 0), "lajang" otherwise (TK/0);
 *  - Plafon Kacamata is per family member: the employee, the spouse when
 *    married, and each registered child.
 * Expects karyawans with subGroup and profile.anak loaded.
 */
class Entitlement
{
    private static ?self $shared = null;

    /**
     * @param  Collection<int, PlafonPengobatan>  $pengobatan
     * @param  Collection<int, PlafonKacamata>  $kacamata
     * @param  array<string, int>  $golonganId  kode => id
     */
    private function __construct(
        private readonly Collection $pengobatan,
        private readonly Collection $kacamata,
        private readonly array $golonganId,
    ) {}

    /** Loaded once and reused until Master Data Comben changes (see flush()). */
    public static function shared(): self
    {
        return self::$shared ??= new self(
            PlafonPengobatan::orderBy('urutan')->orderBy('id')->get(),
            PlafonKacamata::orderBy('urutan')->orderBy('id')->get(),
            Golongan::pluck('id', 'kode')->all(),
        );
    }

    public static function flush(): void
    {
        self::$shared = null;
    }

    public static function kawin(Karyawan $k): bool
    {
        return $k->profile?->status_perkawinan === 'Menikah';
    }

    public static function jumlahAnak(Karyawan $k): int
    {
        return $k->profile?->anak->count() ?? 0;
    }

    /** "K/2": Kawin / Tidak Kawin, and the number of registered children. */
    public static function statusPtkp(Karyawan $k): string
    {
        return (self::kawin($k) ? 'K' : 'TK').'/'.self::jumlahAnak($k);
    }

    public static function keluarga(Karyawan $k): bool
    {
        return self::kawin($k) || self::jumlahAnak($k) > 0;
    }

    public function pengobatanRule(Karyawan $k): ?PlafonPengobatan
    {
        return $this->pengobatan->first(fn (PlafonPengobatan $p) => in_array($k->sub_group_id, $p->sub_group_ids, true));
    }

    public function kacamataRule(Karyawan $k): ?PlafonKacamata
    {
        return $this->kacamata->first(fn (PlafonKacamata $p) => in_array($k->sub_group_id, $p->sub_group_ids, true));
    }

    /** The yearly Plafon Pengobatan of the family; null when the SubGroup has no plafon row. */
    public function plafonPengobatan(Karyawan $k): ?int
    {
        $rule = $this->pengobatanRule($k);

        return $rule ? (self::keluarga($k) ? $rule->keluarga : $rule->lajang) : null;
    }

    /** Why the plafon may be wrong: no row for the SubGroup, or a golongan outside the row's range. */
    public function warning(Karyawan $k): ?string
    {
        $rule = $this->pengobatanRule($k) ?? $this->kacamataRule($k);
        if (! $rule) {
            return 'SubGroup '.($k->subGroup?->nama ?? '-').' belum punya plafon di Master Data Comben.';
        }
        $kode = $k->profile?->golongan;
        if (! $kode) {
            return 'Golongan belum diisi di profil karyawan.';
        }
        if (! in_array($this->golonganId[$kode] ?? null, $rule->golongan_ids, true)) {
            return "Golongan {$kode} di luar rentang plafon {$rule->layer} untuk SubGroup ".($k->subGroup?->nama ?? '-').'.';
        }

        return null;
    }

    /**
     * The family members who can claim Kacamata.
     *
     * @return list<array{key: string, penerima: string, anak_id: ?int, nama: string, hubungan: string}>
     */
    public static function members(Karyawan $k): array
    {
        $members = [['key' => 'karyawan', 'penerima' => 'karyawan', 'anak_id' => null, 'nama' => $k->name, 'hubungan' => 'Karyawan']];
        if (self::kawin($k)) {
            $members[] = ['key' => 'pasangan', 'penerima' => 'pasangan', 'anak_id' => null, 'nama' => $k->profile?->nama_pasangan ?: '(nama belum diisi)', 'hubungan' => 'Istri / Suami'];
        }
        foreach ($k->profile?->anak ?? [] as $anak) {
            $members[] = ['key' => "anak:{$anak->id}", 'penerima' => 'anak', 'anak_id' => $anak->id, 'nama' => $anak->nama ?: "Anak {$anak->urutan}", 'hubungan' => "Anak {$anak->urutan}"];
        }

        return $members;
    }
}
