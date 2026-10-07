<?php

namespace App\Http\Controllers\Comben;

use App\Http\Controllers\Controller;
use App\Models\Deklarasi;
use App\Models\Departemen;
use App\Models\Karyawan;
use App\Models\SubLocation;
use App\Models\WorkLocation;
use App\Support\Access\AccessScope;
use App\Support\Attendance\Roll;
use App\Support\Comben\BukuBulan;
use App\Support\Comben\Entitlement;
use App\Support\Comben\Usage;
use App\Support\Excel\TableExcel;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        // Per month as an object, also when nothing was claimed yet.
        $json = fn (array $rows) => array_map(fn (array $r) => ['bulan' => (object) $r['bulan']] + $r, $rows);
        [$pengobatanRows, $kacamataRows] = self::data($tahun);
        $first = (int) (Deklarasi::min('tahun_plafon') ?? $current);

        return Inertia::render('Comben/Monitoring', [
            'tahun' => $tahun,
            'tahuns' => range(max($current + 1, $tahun), min($first, $tahun, $current - 1)),
            'months' => array_map(fn (string $b) => ['value' => $b, 'label' => BukuBulan::label($b), 'span' => BukuBulan::span($b)], BukuBulan::months($tahun)),
            'current_bulan' => BukuBulan::of(now()),
            'pengobatan' => $json($pengobatanRows),
            'kacamata' => $json($kacamataRows),
            'locations' => AccessScope::locations(),
            'sub_locations' => AccessScope::subLocations(),
            'departemens' => Departemen::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** The two tables with the filters of the page (lokasi, sub lokasi, departemen, search), one sheet each. */
    public function export(Request $request): StreamedResponse
    {
        $f = $request->validate([
            'tahun' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'lokasi' => ['nullable', 'integer'],
            'sub' => ['nullable', 'string', 'max:10'],
            'departemen' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $tahun = (int) ($f['tahun'] ?? BukuBulan::currentTahun());
        $lokasi = isset($f['lokasi']) ? (int) $f['lokasi'] : null;
        $departemen = isset($f['departemen']) ? (int) $f['departemen'] : null;
        $sub = Roll::subLocationFilter($f['sub'] ?? null);
        $q = mb_strtolower(trim($f['q'] ?? ''));
        $keep = fn (array $r) => ($lokasi === null || $r['work_location_id'] === $lokasi)
            && ($sub === null || ($sub === Roll::NO_SUB_LOCATION ? $r['sub_location_id'] === null : $r['sub_location_id'] === $sub))
            && ($departemen === null || $r['departemen_id'] === $departemen)
            && ($q === '' || str_contains(mb_strtolower($r['name']), $q) || str_contains($r['nrp'], $q) || str_contains(mb_strtolower($r['jabatan'] ?? ''), $q));

        [$pengobatan, $kacamata] = self::data($tahun);
        $pengobatan = array_values(array_filter($pengobatan, $keep));
        $kacamata = array_values(array_filter($kacamata, $keep));
        $months = BukuBulan::months($tahun);
        $monthColumns = array_map(fn (string $b) => [BukuBulan::label($b), 12, 'rupiah', 'sum' => true], $months);
        $perMonth = fn (array $r) => array_map(fn (string $b) => $r['bulan'][$b] ?? null, $months);
        $identity = fn (array $r) => [$r['nrp'], $r['name'], $r['jabatan'], $r['departemen'], $r['lokasi'], $r['sub_lokasi'], $r['golongan'], $r['status_ptkp']];
        $identityColumns = [['NRP', 12], ['Nama', 28], ['Jabatan', 26], ['Departemen', 22], ['Lokasi', 16], ['Sub Lokasi', 16], ['Gol', 6], ['PTKP', 7]];

        $info = TableExcel::info([
            "Tahun plafon {$tahun}",
            $lokasi ? 'Lokasi '.WorkLocation::whereKey($lokasi)->value('name') : null,
            match (true) {
                $sub === Roll::NO_SUB_LOCATION => 'Tanpa sub lokasi',
                is_int($sub) => 'Sub lokasi '.SubLocation::whereKey($sub)->value('name'),
                default => null,
            },
            $departemen ? 'Departemen '.Departemen::whereKey($departemen)->value('name') : null,
            $q !== '' ? 'Cari "'.trim($f['q']).'"' : null,
        ]);

        return TableExcel::download("monitoring-plafon-{$tahun}-".now()->format('Y-m-d').'.xlsx', [
            [
                'title' => "Plafon Pengobatan {$tahun}",
                'info' => $info,
                'freeze' => 3,
                'columns' => [
                    ['No', 5, 'int'], ...$identityColumns, ['Layer', 10], ['Keluarga', 9],
                    ['Plafon', 14, 'rupiah', 'sum' => true], ...$monthColumns,
                    ['Terpakai', 14, 'rupiah', 'sum' => true], ['Menunggu', 12, 'rupiah', 'sum' => true], ['Sisa', 14, 'rupiah', 'sum' => true], ['Peringatan', 36],
                ],
                'rows' => array_map(fn (array $r, int $i) => [
                    $i + 1, ...$identity($r), $r['layer'], $r['keluarga'] ? 'Ya' : 'Tidak',
                    $r['plafon'], ...$perMonth($r),
                    $r['terpakai'], $r['menunggu'] ?: null, $r['sisa'], $r['warning'],
                ], $pengobatan, array_keys($pengobatan)),
            ],
            [
                'title' => "Plafon Kacamata {$tahun}",
                'info' => $info,
                'freeze' => 3,
                'columns' => [
                    ['No', 5, 'int'], ...$identityColumns, ['Anggota', 24], ['Hubungan', 12],
                    ['Plafon Bingkai', 13, 'rupiah'], ['Plafon Lensa', 13, 'rupiah'], ...$monthColumns,
                    ['Bingkai', 13, 'rupiah', 'sum' => true], ['Lensa', 13, 'rupiah', 'sum' => true], ['Menunggu', 12, 'rupiah', 'sum' => true],
                    ['Sisa Bingkai', 13, 'rupiah'], ['Sisa Lensa', 13, 'rupiah'], ['Bingkai Terakhir', 14],
                ],
                'rows' => array_map(fn (array $r, int $i) => [
                    $i + 1, ...$identity($r), $r['anggota'], $r['hubungan'],
                    $r['plafon_bingkai'], $r['plafon_lensa'], ...$perMonth($r),
                    $r['bingkai'], $r['lensa'], $r['menunggu'] ?: null, $r['sisa_bingkai'], $r['sisa_lensa'], $r['bingkai_terakhir'] ? BukuBulan::label($r['bingkai_terakhir']) : null,
                ], $kacamata, array_keys($kacamata)),
            ],
        ]);
    }

    /**
     * Every employee not yet out, with their plafon and use in $tahun.
     *
     * @return array{0: list<array>, 1: list<array>} Pengobatan rows, Kacamata rows
     */
    private static function data(int $tahun): array
    {
        $people = Karyawan::with(['jabatan', 'subGroup', 'departemen', 'section.departemen', 'profile.anak', 'workLocation:id,name', 'subLocation:id,name'])
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
            $departemen = $k->placementDepartemen();
            $base = [
                'id' => $k->id,
                'nrp' => $k->nrp,
                'name' => $k->name,
                'jabatan' => $k->jabatan?->name,
                'sub_group' => $k->subGroup?->nama,
                'departemen_id' => $departemen?->id,
                'departemen' => $departemen?->name,
                'work_location_id' => $k->work_location_id,
                'lokasi' => $k->workLocation?->name,
                'sub_location_id' => $k->sub_location_id,
                'sub_lokasi' => $k->subLocation?->name,
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
                'bulan' => $used['bulan'],
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
                    'bulan' => $u['bulan'],
                    'bingkai' => $u['bingkai'],
                    'lensa' => $u['lensa'],
                    'menunggu' => $u['bingkai_menunggu'] + $u['lensa_menunggu'],
                    'sisa_bingkai' => $rule ? $rule->bingkai - $u['bingkai'] - $u['bingkai_menunggu'] : null,
                    'sisa_lensa' => $rule ? $rule->lensa - $u['lensa'] - $u['lensa_menunggu'] : null,
                    'bingkai_terakhir' => $u['bingkai_terakhir'],
                ];
            }
        }

        return [$pengobatanRows, $kacamataRows];
    }
}
