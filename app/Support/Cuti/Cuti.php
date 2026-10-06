<?php

namespace App\Support\Cuti;

use App\Models\CutiAturan;
use App\Models\CutiBesar;
use App\Models\CutiLapangan;
use App\Models\CutiPengambilan;
use App\Models\Karyawan;
use App\Models\Trip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Cuti Tahunan & Cuti Besar.
 *
 * Tahunan (as the old LEDGER CUTI sheet): the quota of a year arrives on the
 * anniversary of the hire date, from the second year on; it lives N years (2) and
 * is used oldest first (FIFO). Days taken beyond what is left become hutang, paid
 * from the next quotas. Days count in the year the leave starts.
 *
 * Besar: every N years of service (5) the employee chooses: take the days (25,
 * plus 1x gapok) or cash it in (2x gapok); not chosen by the deadline = cashed in.
 *
 * A cuti in Trip & Leave takes days by its kind: lapangan (field leave) takes from
 * the annual quota only what goes beyond the location's allowance (leave days +
 * travel out + travel back); tahunan takes all its days; besar takes from the Cuti
 * Besar taken (the rest from the annual quota); lainnya takes nothing.
 */
class Cuti
{
    public const KATEGORI = [
        'lapangan' => 'Cuti Lapangan',
        'tahunan' => 'Cuti Tahunan',
        'besar' => 'Cuti Besar',
        'lainnya' => 'Cuti Lainnya (tidak memotong)',
    ];

    /** Pengambilan, trips (not rejected) and Cuti Besar choices of these employees, grouped by employee. */
    public static function load(iterable $karyawanIds): array
    {
        $ids = collect($karyawanIds)->values()->all();

        return [
            'pengambilan' => CutiPengambilan::withoutGlobalScopes()->whereIn('karyawan_id', $ids)->orderBy('tahun')->orderBy('tanggal_awal')->orderBy('id')->get()->groupBy('karyawan_id'),
            'trips' => Trip::withoutGlobalScopes()->whereIn('karyawan_id', $ids)->where('jenis', 'cuti')->where('status', '!=', 'ditolak')->orderBy('tanggal_awal')->get()->groupBy('karyawan_id'),
            'besar' => CutiBesar::withoutGlobalScopes()->whereIn('karyawan_id', $ids)->get()->groupBy('karyawan_id'),
        ];
    }

    /** Everything about one employee's leave: annual balance per year and the Cuti Besar entitlements. */
    public static function summary(Karyawan $k, ?CarbonImmutable $today = null, ?array $data = null, ?int $exceptTrip = null): array
    {
        $today ??= CarbonImmutable::today();
        $data ??= self::load([$k->id]);
        $k->loadMissing('profile');
        $masuk = $k->profile?->tanggal_masuk;
        $pengambilan = ($data['pengambilan'][$k->id] ?? collect())->where('jenis', 'tahunan');
        $trips = ($data['trips'][$k->id] ?? collect())->when($exceptTrip, fn ($c) => $c->where('id', '!=', $exceptTrip));

        // Each leave taken, in the year it counts in, oldest first: the ledger says which quota it used.
        $items = [];
        foreach ($pengambilan as $p) {
            $items[] = ['key' => "p{$p->id}", 'tahun' => $p->tahun, 'hari' => $p->hari, 'urut' => $p->tanggal_awal?->toDateString() ?? "{$p->tahun}-00"];
        }
        foreach ($trips->where('status', 'disetujui')->where('potong_tahunan', '>', 0) as $t) {
            $items[] = ['key' => "t{$t->id}", 'tahun' => $t->tanggal_awal->year, 'hari' => $t->potong_tahunan, 'urut' => $t->tanggal_awal->toDateString()];
        }
        $diambil = [];
        foreach ($items as $i) {
            $diambil[$i['tahun']] = ($diambil[$i['tahun']] ?? 0) + $i['hari'];
        }
        $menunggu = (int) $trips->where('status', 'diajukan')->sum('potong_tahunan');

        return [
            'tahunan' => self::tahunan($masuk, $diambil, $menunggu, $today, $items),
            'besar' => self::besar($masuk, $data['besar'][$k->id] ?? collect(), $trips, $today),
        ];
    }

    /**
     * The annual balance: quota buckets per year, used oldest first.
     *
     * With $items (each leave: key, tahun, hari, urut) it also tells, per leave, which year's
     * quota its days came from — or hutang, and the later quota that paid it.
     *
     * @param  array<int, int>  $diambil  year => days taken
     * @param  list<array{key: string, tahun: int, hari: int, urut: string}>  $items
     */
    public static function tahunan(?CarbonImmutable $masuk, array $diambil, int $menunggu, CarbonImmutable $today, array $items = []): array
    {
        $a = CutiAturan::current();
        if (! $masuk) {
            return ['ada' => false, 'alasan' => 'Tanggal masuk belum diisi di profil.', 'menunggu' => $menunggu, 'diambil_total' => array_sum($diambil)];
        }
        if (! $items) {
            foreach ($diambil as $y => $hari) {
                $items[] = ['key' => "y{$y}", 'tahun' => $y, 'hari' => $hari, 'urut' => "{$y}"];
            }
        }
        usort($items, fn ($x, $y) => [$x['tahun'], $x['urut']] <=> [$y['tahun'], $y['urut']]);
        $byYear = [];
        foreach ($items as $i) {
            $byYear[$i['tahun']][] = $i;
        }
        $first = min($masuk->year + 1, ...(array_keys($diambil) ?: [$today->year]));
        $buckets = []; // [year granted => days left]
        $debts = []; // [[key, days]] taken beyond the balance, oldest first
        $alokasi = []; // key => [{jatah, hari, hutang}]
        $years = [];
        $hangus = 0;
        for ($y = $first; $y <= $today->year; $y++) {
            // Buckets older than the life of a quota are gone.
            foreach ($buckets as $from => $left) {
                if ($from + $a->masa_aktif_tahun - 1 < $y) {
                    unset($buckets[$from]);
                }
            }
            $jatah = $y >= $masuk->year + 1 && self::anniversary($masuk, $y)->lte($today) ? $a->jatah_tahunan : 0;
            // A new quota first pays the hutang, oldest first.
            $left = $jatah;
            foreach ($debts as $n => [$key, $days]) {
                if ($left <= 0) {
                    break;
                }
                $pay = min($days, $left);
                $alokasi[$key][] = ['jatah' => $y, 'hari' => $pay, 'hutang' => true];
                $debts[$n][1] -= $pay;
                $left -= $pay;
            }
            $debts = array_values(array_filter($debts, fn ($d) => $d[1] > 0));
            if ($left > 0) {
                $buckets[$y] = $left;
            }
            ksort($buckets);
            foreach ($byYear[$y] ?? [] as $item) {
                $take = $item['hari'];
                foreach ($buckets as $from => $rest) {
                    if ($take <= 0) {
                        break;
                    }
                    $use = min($rest, $take);
                    if ($use > 0) {
                        $buckets[$from] -= $use;
                        $take -= $use;
                        $alokasi[$item['key']][] = ['jatah' => $from, 'hari' => $use, 'hutang' => false];
                    }
                }
                if ($take > 0) {
                    $debts[] = [$item['key'], $take];
                }
            }
            $hutang = array_sum(array_column($debts, 1));
            $saldo = array_sum($buckets);
            // What expires at the end of this year unused.
            $hangus = array_sum(array_filter($buckets, fn ($left, $from) => $from + $a->masa_aktif_tahun - 1 === $y, ARRAY_FILTER_USE_BOTH));
            $years[] = ['tahun' => $y, 'jatah' => $jatah, 'diambil' => $diambil[$y] ?? 0, 'sisa' => $saldo, 'hutang' => $hutang];
        }
        // Hutang not paid yet.
        foreach ($debts as [$key, $days]) {
            $alokasi[$key][] = ['jatah' => null, 'hari' => $days, 'hutang' => true];
        }
        $now = end($years) ?: ['jatah' => 0, 'diambil' => 0, 'sisa' => 0, 'hutang' => 0];
        $next = self::anniversary($masuk, $today->year);
        if ($next->lte($today) || $today->year < $masuk->year + 1) {
            $next = self::anniversary($masuk, max($today->year + 1, $masuk->year + 1));
        }

        return [
            'ada' => true,
            'masuk' => $masuk->toDateString(),
            'saldo' => $now['sisa'],
            'hutang' => $now['hutang'],
            'menunggu' => $menunggu,
            'tersedia' => $now['sisa'] - $now['hutang'] - $menunggu,
            'jatah_tahun_ini' => $now['jatah'],
            'diambil_tahun_ini' => $now['diambil'],
            'diambil_total' => array_sum($diambil),
            'hangus_akhir_tahun' => $hangus,
            'hak_berikutnya' => $next->toDateString(),
            'tahun' => $years,
            'alokasi' => $alokasi,
        ];
    }

    /** The Cuti Besar entitlements reached so far (and the next one). */
    public static function besar(?CarbonImmutable $masuk, Collection $choices, Collection $trips, CarbonImmutable $today): array
    {
        $a = CutiAturan::current();
        if (! $masuk) {
            return ['periode' => [], 'berikutnya' => null];
        }
        $periode = [];
        for ($n = 1; ($hak = $masuk->addYears($n * $a->besar_interval_tahun))->lte($today); $n++) {
            $choice = $choices->firstWhere('periode_ke', $n);
            $batas = $hak->addMonths($a->besar_batas_bulan);
            $terpakai = (int) $trips->where('status', 'disetujui')->where('cuti_besar_periode', $n)->sum('potong_besar');
            $menunggu = (int) $trips->where('status', 'diajukan')->where('cuti_besar_periode', $n)->sum('potong_besar');
            $status = match (true) {
                $choice?->status === 'diajukan' => 'menunggu',
                $choice?->keputusan === 'diambil' => 'diambil',
                $choice?->keputusan === 'diuangkan' => 'diuangkan',
                $today->gt($batas) => 'otomatis_diuangkan',
                default => 'belum_dipilih',
            };
            $periode[] = [
                'id' => $choice?->id,
                'periode_ke' => $n,
                'masa_kerja' => $n * $a->besar_interval_tahun,
                'tanggal_hak' => $hak->toDateString(),
                'batas' => $batas->toDateString(),
                'status' => $status,
                'keputusan' => $choice?->keputusan,
                'keputusan_status' => $choice?->status,
                'hari' => $status === 'diambil' ? $a->besar_hari : 0,
                'terpakai' => $terpakai,
                'menunggu' => $menunggu,
                'sisa' => $status === 'diambil' ? $a->besar_hari - $terpakai - $menunggu : 0,
                'gapok' => match ($status) {
                    'diambil' => $a->besar_gapok_diambil,
                    'diuangkan', 'otomatis_diuangkan' => $a->besar_gapok_diuangkan,
                    default => null,
                },
            ];
        }

        return ['periode' => $periode, 'berikutnya' => $masuk->addYears($n * $a->besar_interval_tahun)->toDateString(), 'berikutnya_masa_kerja' => $n * $a->besar_interval_tahun];
    }

    /** The field-leave allowance of the employee's sub location, else of their work location. */
    public static function lapangan(Karyawan $k): ?CutiLapangan
    {
        if (! $k->work_location_id) {
            return null;
        }
        $rows = CutiLapangan::where('work_location_id', $k->work_location_id)->get();

        return ($k->sub_location_id ? $rows->firstWhere('sub_location_id', $k->sub_location_id) : null) ?? $rows->whereNull('sub_location_id')->first();
    }

    /**
     * What a cuti of $hari days takes, by its kind.
     *
     * @return array{potong_tahunan: int, potong_besar: int, cuti_besar_periode: int|null}
     */
    public static function potongan(Karyawan $k, string $kategori, int $hari, ?int $exceptTrip = null): array
    {
        $out = ['potong_tahunan' => 0, 'potong_besar' => 0, 'cuti_besar_periode' => null];
        switch ($kategori) {
            case 'tahunan':
                $out['potong_tahunan'] = $hari;
                break;
            case 'lapangan':
                $allowance = self::lapangan($k)
                    ?? throw ValidationException::withMessages(['kategori_cuti' => 'Cuti lapangan belum diatur untuk lokasi karyawan ini (Master Data Comben → Cuti Lapangan).']);
                $out['potong_tahunan'] = max(0, $hari - $allowance->total());
                break;
            case 'besar':
                $open = collect(self::summary($k, exceptTrip: $exceptTrip)['besar']['periode'])->where('status', 'diambil')->where('sisa', '>', 0)->first()
                    ?? throw ValidationException::withMessages(['kategori_cuti' => 'Belum ada hak Cuti Besar yang dipilih "Diambil" dan masih bersisa.']);
                $out['potong_besar'] = min($hari, $open['sisa']);
                $out['potong_tahunan'] = $hari - $out['potong_besar'];
                $out['cuti_besar_periode'] = $open['periode_ke'];
                break;
        }

        return $out;
    }

    /** For the cuti form: the balance, the field-leave allowance and the Cuti Besar to take. */
    public static function info(Karyawan $k, ?int $exceptTrip = null): array
    {
        $s = self::summary($k, exceptTrip: $exceptTrip);
        $l = self::lapangan($k);

        return [
            'tahunan' => array_diff_key($s['tahunan'], ['tahun' => 1, 'alokasi' => 1]),
            'lapangan' => $l ? ['cuti' => $l->hari_cuti, 'berangkat' => $l->hari_berangkat, 'kembali' => $l->hari_kembali, 'total' => $l->total()] : null,
            'besar' => collect($s['besar']['periode'])->whereIn('status', ['diambil', 'belum_dipilih', 'menunggu'])->values(),
            'kategori' => self::KATEGORI,
        ];
    }

    /** The anniversary of the hire date in a year (29 Feb → 28 Feb). */
    public static function anniversary(CarbonImmutable $masuk, int $year): CarbonImmutable
    {
        return CarbonImmutable::create($year, $masuk->month, min($masuk->day, CarbonImmutable::create($year, $masuk->month, 1)->daysInMonth));
    }
}
