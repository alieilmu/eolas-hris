<?php

namespace App\Support\Compensation;

use App\Models\BenefitClaimItem;
use App\Models\Employee;
use App\Models\OpticalBenefitLimit;

/**
 * Whether a deklarasi fits the plafon: Pengobatan for the family in the plafon
 * year, Kacamata per family member (lensa per year, bingkai per two years).
 */
class BenefitClaimCheck
{
    /**
     * @param  list<array{jenis: string, penerima: string, karyawan_anak_id: ?int, nominal: int}>  $items
     * @return list<string> what is wrong; empty when it fits
     */
    public static function errors(Employee $k, array $items, int $year, ?int $exceptDeklarasi = null): array
    {
        $errors = [];
        $members = collect(BenefitEntitlement::members($k))->keyBy('key');
        $rp = fn (int $n) => 'Rp '.number_format($n, 0, ',', '.');

        foreach ($items as $item) {
            $key = $item['beneficiary'] === 'children' ? 'children:'.($item['employee_child_id'] ?? '') : $item['beneficiary'];
            if (! $members->has($key)) {
                $errors[] = match ($item['beneficiary']) {
                    'spouse' => 'Status perkawinan karyawan bukan Menikah, jadi tidak ada klaim untuk Istri / Suami.',
                    'children' => 'Pilih anak yang terdaftar di profil karyawan.',
                    default => 'Penerima tidak dikenal.',
                };
            }
        }
        if ($errors) {
            return array_values(array_unique($errors));
        }

        $summary = Usage::summary($k, $year, $exceptDeklarasi);

        $medical = array_sum(array_map(fn ($i) => $i['amount'], array_filter($items, fn ($i) => in_array($i['type'], BenefitClaimItem::PENGOBATAN, true))));
        if ($medical > 0) {
            $p = $summary['medical'];
            if ($p['benefit_limit'] === null) {
                $errors[] = 'SubGroup karyawan belum punya Plafon Pengobatan di Pengaturan Kompensasi.';
            } elseif ($medical > $p['remaining']) {
                $errors[] = "Plafon Pengobatan {$year} ({$summary['ptkp_status']}) tersisa {$rp(max($p['remaining'], 0))} dari {$rp($p['benefit_limit'])}; deklarasi ini {$rp($medical)}.";
            }
        }

        $optical = collect($summary['optical'])->keyBy('key');
        foreach (BenefitClaimItem::KACAMATA as $type) {
            $byMember = [];
            foreach ($items as $i) {
                if ($i['type'] === $type) {
                    $key = $i['beneficiary'] === 'children' ? "children:{$i['employee_child_id']}" : $i['beneficiary'];
                    $byMember[$key] = ($byMember[$key] ?? 0) + $i['amount'];
                }
            }
            foreach ($byMember as $key => $amount) {
                $m = $optical[$key];
                $benefit_limit = $m[$type];
                if ($benefit_limit['benefit_limit'] === null) {
                    $errors[] = 'SubGroup karyawan belum punya Plafon Kacamata di Pengaturan Kompensasi.';

                    continue;
                }
                if ($amount > $benefit_limit['remaining']) {
                    $what = $type === 'frame' ? 'Bingkai' : 'Lensa';
                    $note = $type === 'frame' && $benefit_limit['last']
                        ? ' Bingkai terakhir diklaim '.AccountingMonth::label($benefit_limit['last']).'; bingkai bisa diklaim lagi mulai tahun '.(AccountingMonth::year($benefit_limit['last']) + OpticalBenefitLimit::BINGKAI_TAHUN).'.'
                        : '';
                    $errors[] = "Kacamata – {$what} {$m['name']} ({$m['relationship']}) tersisa {$rp(max($benefit_limit['remaining'], 0))} dari {$rp($benefit_limit['benefit_limit'])}; deklarasi ini {$rp($amount)}.{$note}";
                }
            }
        }

        return array_values(array_unique($errors));
    }
}
