<?php

namespace App\Support\Comben;

use App\Models\DeklarasiItem;
use App\Models\Karyawan;
use App\Models\PlafonKacamata;
use Illuminate\Support\Facades\DB;

/**
 * How much of each plafon has been claimed. Pengobatan counts perawatan and
 * pengobatan of the plafon year; Kacamata counts lensa of the year and bingkai of
 * the year and the one before (a frame is once in two years), per family member.
 * Approved deklarasi are "terpakai"; those still waiting for HR ("diajukan", from
 * the employee portal) are "menunggu" and hold their amount; rejected ones count
 * for nothing.
 */
class Usage
{
    public const COUNTED = ['disetujui', 'diajukan'];

    /**
     * @param  iterable<int>  $karyawanIds
     * @return array<int, array{bulan: array<string, int>, total: int, menunggu: int}>
     */
    public static function pengobatan(iterable $karyawanIds, int $tahun, ?int $exceptDeklarasi = null): array
    {
        $usage = [];
        foreach (self::items($karyawanIds, [$tahun], DeklarasiItem::PENGOBATAN, $exceptDeklarasi) as $i) {
            $u = $usage[$i->karyawan_id] ?? ['bulan' => [], 'total' => 0, 'menunggu' => 0];
            if ($i->status === 'diajukan') {
                $u['menunggu'] += $i->nominal;
            } else {
                $u['bulan'][$i->bulan_buku] = ($u['bulan'][$i->bulan_buku] ?? 0) + $i->nominal;
                $u['total'] += $i->nominal;
            }
            $usage[$i->karyawan_id] = $u;
        }

        return $usage;
    }

    /**
     * @param  iterable<int>  $karyawanIds
     * @return array<int, array<string, array{bingkai: int, lensa: int, bingkai_menunggu: int, lensa_menunggu: int, bulan: array<string, int>, bingkai_terakhir: ?string}>> karyawan id => member key => usage
     */
    public static function kacamata(iterable $karyawanIds, int $tahun, ?int $exceptDeklarasi = null): array
    {
        $years = range($tahun - PlafonKacamata::BINGKAI_TAHUN + 1, $tahun);
        $usage = [];
        foreach (self::items($karyawanIds, $years, DeklarasiItem::KACAMATA, $exceptDeklarasi) as $i) {
            $key = $i->penerima === 'anak' ? "anak:{$i->karyawan_anak_id}" : $i->penerima;
            $u = $usage[$i->karyawan_id][$key] ?? self::emptyKacamata();
            $pending = $i->status === 'diajukan';
            $thisYear = (int) $i->tahun_plafon === $tahun;
            if ($i->jenis === 'bingkai') {
                $u[$pending ? 'bingkai_menunggu' : 'bingkai'] += $i->nominal;
                $u['bingkai_terakhir'] = max($u['bingkai_terakhir'] ?? '', $i->bulan_buku);
            } elseif ($thisYear) {
                $u[$pending ? 'lensa_menunggu' : 'lensa'] += $i->nominal;
            }
            if ($thisYear && ! $pending) {
                $u['bulan'][$i->bulan_buku] = ($u['bulan'][$i->bulan_buku] ?? 0) + $i->nominal;
            }
            $usage[$i->karyawan_id][$key] = $u;
        }

        return $usage;
    }

    public static function emptyKacamata(): array
    {
        return ['bingkai' => 0, 'lensa' => 0, 'bingkai_menunggu' => 0, 'lensa_menunggu' => 0, 'bulan' => [], 'bingkai_terakhir' => null];
    }

    /** Pengobatan and Kacamata of one employee, with plafon and what is left (for the forms and the checks). */
    public static function summary(Karyawan $k, int $tahun, ?int $exceptDeklarasi = null): array
    {
        $e = Entitlement::shared();
        $plafon = $e->plafonPengobatan($k);
        $used = self::pengobatan([$k->id], $tahun, $exceptDeklarasi)[$k->id] ?? ['total' => 0, 'menunggu' => 0];
        $rule = $e->kacamataRule($k);
        $kacamata = self::kacamata([$k->id], $tahun, $exceptDeklarasi)[$k->id] ?? [];
        $allowance = fn (?int $plafon, int $terpakai, int $menunggu, array $extra = []) => [
            'plafon' => $plafon,
            'terpakai' => $terpakai,
            'menunggu' => $menunggu,
            'sisa' => $plafon === null ? null : $plafon - $terpakai - $menunggu,
        ] + $extra;

        return [
            'tahun' => $tahun,
            'status_ptkp' => Entitlement::statusPtkp($k),
            'keluarga' => Entitlement::keluarga($k),
            'warning' => $e->warning($k),
            'pengobatan' => ['layer' => $e->pengobatanRule($k)?->layer] + $allowance($plafon, $used['total'], $used['menunggu']),
            'kacamata' => array_map(function (array $m) use ($rule, $kacamata, $allowance) {
                $u = $kacamata[$m['key']] ?? self::emptyKacamata();

                return $m + [
                    'bingkai' => $allowance($rule?->bingkai, $u['bingkai'], $u['bingkai_menunggu'], ['terakhir' => $u['bingkai_terakhir']]),
                    'lensa' => $allowance($rule?->lensa, $u['lensa'], $u['lensa_menunggu']),
                ];
            }, Entitlement::members($k)),
        ];
    }

    /** Items of the employees' counted deklarasi in the plafon years, with the deklarasi's month, year and status. */
    private static function items(iterable $karyawanIds, array $years, array $jenis, ?int $exceptDeklarasi)
    {
        return DB::table('deklarasi_items')
            ->join('deklarasis', 'deklarasis.id', '=', 'deklarasi_items.deklarasi_id')
            ->whereIn('deklarasis.karyawan_id', collect($karyawanIds)->all())
            ->whereIn('deklarasis.tahun_plafon', $years)
            ->whereIn('deklarasis.status', self::COUNTED)
            ->whereIn('deklarasi_items.jenis', $jenis)
            ->when($exceptDeklarasi, fn ($q) => $q->where('deklarasis.id', '!=', $exceptDeklarasi))
            ->get(['deklarasis.karyawan_id', 'deklarasis.bulan_buku', 'deklarasis.tahun_plafon', 'deklarasis.status', 'deklarasi_items.jenis', 'deklarasi_items.penerima', 'deklarasi_items.karyawan_anak_id', 'deklarasi_items.nominal']);
    }
}
