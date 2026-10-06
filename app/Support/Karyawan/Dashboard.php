<?php

namespace App\Support\Karyawan;

use App\Models\Karyawan;
use App\Models\PkwtPeriod;
use Carbon\CarbonImmutable;

/** Everything the Man Power dashboard shows, computed over active employees. */
class Dashboard
{
    /** Contracts ending within this many months are listed as "akan berakhir". */
    private const PKWT_LIST_MONTHS = 1;

    /** Employees reaching retirement age within this many months are listed. */
    private const PENSIUN_LIST_MONTHS = 12;

    private const BULAN = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    public static function build(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        $karyawans = Karyawan::aktif()
            ->with(['jabatan', 'profile.pkwtPeriods', 'profile.anak'])
            ->orderBy('nrp')
            ->get();

        // --- completeness ---------------------------------------------------
        $groupDefs = ColumnGroups::build();
        $tally = array_fill_keys(array_column($groupDefs, 'id'), ['filled' => 0, 'total' => 0, 'applicable' => 0]);

        foreach ($karyawans as $k) {
            foreach (Completeness::score($k->profile) as $id => $s) {
                if (! $s['applicable']) {
                    continue;
                }
                $tally[$id]['applicable']++;
                $tally[$id]['filled'] += $s['filled'];
                $tally[$id]['total'] += $s['total'];
            }
        }

        $groups = array_map(fn (array $g) => [
            'id' => $g['id'],
            'title' => $g['title'],
            // null when no employee currently needs to fill this group.
            'percent' => $tally[$g['id']]['total'] > 0
                ? (int) round($tally[$g['id']]['filled'] / $tally[$g['id']]['total'] * 100)
                : null,
            ...$tally[$g['id']],
        ], $groupDefs);

        // --- origin, contract type and the follow-up lists --------------------
        $asal = ['lokal' => 0, 'kiriman' => 0, 'belum_diisi' => 0];
        $statusPegawai = ['pkwt' => 0, 'tetap' => 0];
        $akanBerakhir = [];
        $berakhir = [];
        $pensiun = [];
        $ulangTahun = [];

        foreach ($karyawans as $k) {
            $p = $k->profile;
            $base = ['karyawan_id' => $k->id, 'nrp' => $k->nrp, 'name' => $k->name, 'jabatan' => $k->jabatan?->name ?? 'Belum ditempatkan'];

            // Read from the NRP; unknown when its lokasi penerimaan is not mapped to a work location.
            match (Nrp::statusAsal($k->nrp, $k->work_location_id)) {
                'Lokal' => $asal['lokal']++,
                'Kiriman' => $asal['kiriman']++,
                default => $asal['belum_diisi']++,
            };

            $tetap = $p?->status_pegawai_tetap === true;
            $tetap ? $statusPegawai['tetap']++ : $statusPegawai['pkwt']++;

            // Contracts to follow up: only staff not yet appointed permanent.
            if (! $tetap && ($latest = $p?->latestPkwt())) {
                $sisaHari = Dates::daysUntil($latest->tanggal_berakhir, $today);
                $item = $base + [
                    'periode_label' => PkwtPeriod::label($latest->periode_ke),
                    'tanggal_kontrak' => $latest->tanggal_kontrak->toDateString(),
                    'tanggal_berakhir' => $latest->tanggal_berakhir->toDateString(),
                    'sisa_hari' => $sisaHari, // negative once the contract has ended
                    'period_count' => $p->pkwtPeriods->count(),
                    // Default start of an extension: the day after the latest contract ends.
                    'next_start' => $latest->tanggal_berakhir->addDay()->toDateString(),
                ];

                if ($sisaHari < 0) {
                    $berakhir[] = $item;
                } elseif (Dates::isWithinMonths($latest->tanggal_berakhir, self::PKWT_LIST_MONTHS, $today)) {
                    $akanBerakhir[] = $item;
                }
            }

            if ($lahir = $p?->tanggal_lahir) {
                $tanggalPensiun = $lahir->addYears(Completeness::PENSIUN_AGE);
                if (Dates::isWithinMonths($tanggalPensiun, self::PENSIUN_LIST_MONTHS, $today)) {
                    $pensiun[] = $base + [
                        'tanggal_lahir' => $lahir->toDateString(),
                        'tanggal_pensiun' => $tanggalPensiun->toDateString(),
                        'sisa_hari' => Dates::daysUntil($tanggalPensiun, $today),
                    ];
                }

                if ($lahir->month === $today->month) {
                    $ulangTahun[] = $base + [
                        'tanggal_lahir' => $lahir->toDateString(),
                        'hari' => $lahir->day,
                        'usia_baru' => $today->year - $lahir->year,
                        'hari_ini' => $lahir->day === $today->day,
                    ];
                }
            }
        }

        usort($akanBerakhir, fn ($a, $b) => $a['sisa_hari'] <=> $b['sisa_hari']);
        usort($berakhir, fn ($a, $b) => $b['sisa_hari'] <=> $a['sisa_hari']); // most recently lapsed first
        usort($pensiun, fn ($a, $b) => $a['sisa_hari'] <=> $b['sisa_hari']);
        usort($ulangTahun, fn ($a, $b) => $a['hari'] <=> $b['hari']);

        $sumFilled = array_sum(array_column($groups, 'filled'));
        $sumTotal = array_sum(array_column($groups, 'total'));

        return [
            'today' => $today->toDateString(),
            'total_aktif' => $karyawans->count(),
            'completeness' => [
                'overall_percent' => $sumTotal > 0 ? (int) round($sumFilled / $sumTotal * 100) : null,
                'groups' => $groups,
            ],
            'asal' => $asal,
            'status_pegawai' => $statusPegawai,
            'pkwt_akan_berakhir' => $akanBerakhir,
            'pkwt_berakhir' => $berakhir,
            'pensiun_age' => Completeness::PENSIUN_AGE,
            'pensiun' => $pensiun,
            'ulang_tahun_bulan' => self::BULAN[$today->month],
            'ulang_tahun' => $ulangTahun,
        ];
    }
}
