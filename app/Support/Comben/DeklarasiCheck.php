<?php

namespace App\Support\Comben;

use App\Models\DeklarasiItem;
use App\Models\Karyawan;
use App\Models\PlafonKacamata;

/**
 * Whether a deklarasi fits the plafon: Pengobatan for the family in the plafon
 * year, Kacamata per family member (lensa per year, bingkai per two years).
 */
class DeklarasiCheck
{
    /**
     * @param  list<array{jenis: string, penerima: string, karyawan_anak_id: ?int, nominal: int}>  $items
     * @return list<string> what is wrong; empty when it fits
     */
    public static function errors(Karyawan $k, array $items, int $tahun, ?int $exceptDeklarasi = null): array
    {
        $errors = [];
        $members = collect(Entitlement::members($k))->keyBy('key');
        $rp = fn (int $n) => 'Rp '.number_format($n, 0, ',', '.');

        foreach ($items as $item) {
            $key = $item['penerima'] === 'anak' ? 'anak:'.($item['karyawan_anak_id'] ?? '') : $item['penerima'];
            if (! $members->has($key)) {
                $errors[] = match ($item['penerima']) {
                    'pasangan' => 'Status perkawinan karyawan bukan Menikah, jadi tidak ada klaim untuk Istri / Suami.',
                    'anak' => 'Pilih anak yang terdaftar di profil karyawan.',
                    default => 'Penerima tidak dikenal.',
                };
            }
        }
        if ($errors) {
            return array_values(array_unique($errors));
        }

        $summary = Usage::summary($k, $tahun, $exceptDeklarasi);

        $pengobatan = array_sum(array_map(fn ($i) => $i['nominal'], array_filter($items, fn ($i) => in_array($i['jenis'], DeklarasiItem::PENGOBATAN, true))));
        if ($pengobatan > 0) {
            $p = $summary['pengobatan'];
            if ($p['plafon'] === null) {
                $errors[] = 'SubGroup karyawan belum punya Plafon Pengobatan di Master Data Comben.';
            } elseif ($pengobatan > $p['sisa']) {
                $errors[] = "Plafon Pengobatan {$tahun} ({$summary['status_ptkp']}) tersisa {$rp(max($p['sisa'], 0))} dari {$rp($p['plafon'])}; deklarasi ini {$rp($pengobatan)}.";
            }
        }

        $kacamata = collect($summary['kacamata'])->keyBy('key');
        foreach (DeklarasiItem::KACAMATA as $jenis) {
            $byMember = [];
            foreach ($items as $i) {
                if ($i['jenis'] === $jenis) {
                    $key = $i['penerima'] === 'anak' ? "anak:{$i['karyawan_anak_id']}" : $i['penerima'];
                    $byMember[$key] = ($byMember[$key] ?? 0) + $i['nominal'];
                }
            }
            foreach ($byMember as $key => $amount) {
                $m = $kacamata[$key];
                $plafon = $m[$jenis];
                if ($plafon['plafon'] === null) {
                    $errors[] = 'SubGroup karyawan belum punya Plafon Kacamata di Master Data Comben.';

                    continue;
                }
                if ($amount > $plafon['sisa']) {
                    $what = $jenis === 'bingkai' ? 'Bingkai' : 'Lensa';
                    $note = $jenis === 'bingkai' && $plafon['terakhir']
                        ? ' Bingkai terakhir diklaim '.BukuBulan::label($plafon['terakhir']).'; bingkai bisa diklaim lagi mulai tahun '.(BukuBulan::tahun($plafon['terakhir']) + PlafonKacamata::BINGKAI_TAHUN).'.'
                        : '';
                    $errors[] = "Kacamata – {$what} {$m['nama']} ({$m['hubungan']}) tersisa {$rp(max($plafon['sisa'], 0))} dari {$rp($plafon['plafon'])}; deklarasi ini {$rp($amount)}.{$note}";
                }
            }
        }

        return array_values(array_unique($errors));
    }
}
