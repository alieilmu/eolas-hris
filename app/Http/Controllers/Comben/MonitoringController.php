<?php

namespace App\Http\Controllers\Comben;

use App\Http\Controllers\Controller;
use App\Models\Deklarasi;
use App\Models\Departemen;
use App\Models\Karyawan;
use App\Support\Access\AccessScope;
use App\Support\Comben\BukuBulan;
use App\Support\Comben\Entitlement;
use App\Support\Comben\Usage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sisa plafon per employee for one plafon year: Pengobatan per family, Kacamata
 * per family member, with what was claimed in each book month.
 */
class MonitoringController extends Controller
{
    public function index(Request $request): Response
    {
        $current = BukuBulan::currentTahun();
        $tahun = $request->integer('tahun') ?: $current;
        $people = Karyawan::with(['jabatan', 'subGroup', 'departemen', 'section.departemen', 'profile.anak'])
            ->where('status', '!=', Karyawan::STATUS_KELUAR)
            ->orderBy('nrp')
            ->get();
        $ids = $people->pluck('id');
        $e = Entitlement::shared();
        $pengobatan = Usage::pengobatan($ids, $tahun);
        $kacamata = Usage::kacamata($ids, $tahun);

        $pengobatanRows = [];
        $kacamataRows = [];
        foreach ($people as $k) {
            $base = [
                'id' => $k->id,
                'nrp' => $k->nrp,
                'name' => $k->name,
                'jabatan' => $k->jabatan?->name,
                'sub_group' => $k->subGroup?->nama,
                'departemen_id' => $k->placementDepartemen()?->id,
                'work_location_id' => $k->work_location_id,
                'sub_location_id' => $k->sub_location_id,
                'golongan' => $k->profile?->golongan,
                'status_ptkp' => Entitlement::statusPtkp($k),
                'warning' => $e->warning($k),
            ];
            $plafon = $e->plafonPengobatan($k);
            $used = $pengobatan[$k->id] ?? ['bulan' => [], 'total' => 0, 'menunggu' => 0];
            $pengobatanRows[] = $base + [
                'layer' => $e->pengobatanRule($k)?->layer,
                'keluarga' => Entitlement::keluarga($k),
                'plafon' => $plafon,
                'bulan' => (object) $used['bulan'],
                'terpakai' => $used['total'],
                // Pengajuan from the portal waiting for HR: they already hold their amount.
                'menunggu' => $used['menunggu'],
                'sisa' => $plafon === null ? null : $plafon - $used['total'] - $used['menunggu'],
            ];

            $rule = $e->kacamataRule($k);
            foreach (Entitlement::members($k) as $m) {
                $u = $kacamata[$k->id][$m['key']] ?? Usage::emptyKacamata();
                $kacamataRows[] = $base + [
                    'key' => "{$k->id}:{$m['key']}",
                    'anggota' => $m['nama'],
                    'hubungan' => $m['hubungan'],
                    'plafon_bingkai' => $rule?->bingkai,
                    'plafon_lensa' => $rule?->lensa,
                    'bulan' => (object) $u['bulan'],
                    'bingkai' => $u['bingkai'],
                    'lensa' => $u['lensa'],
                    'menunggu' => $u['bingkai_menunggu'] + $u['lensa_menunggu'],
                    'sisa_bingkai' => $rule ? $rule->bingkai - $u['bingkai'] - $u['bingkai_menunggu'] : null,
                    'sisa_lensa' => $rule ? $rule->lensa - $u['lensa'] - $u['lensa_menunggu'] : null,
                    'bingkai_terakhir' => $u['bingkai_terakhir'],
                ];
            }
        }

        $first = (int) (Deklarasi::min('tahun_plafon') ?? $current);

        return Inertia::render('Comben/Monitoring', [
            'tahun' => $tahun,
            'tahuns' => range(max($current + 1, $tahun), min($first, $tahun, $current - 1)),
            'months' => array_map(fn (string $b) => ['value' => $b, 'label' => BukuBulan::label($b), 'span' => BukuBulan::span($b)], BukuBulan::months($tahun)),
            'current_bulan' => BukuBulan::of(now()),
            'pengobatan' => $pengobatanRows,
            'kacamata' => $kacamataRows,
            'locations' => AccessScope::locations(),
            'sub_locations' => AccessScope::subLocations(),
            'departemens' => Departemen::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
