<?php

namespace App\Support\Mpp;

use App\Models\Applicant;
use App\Models\ApplicationForm;
use App\Models\Candidate;
use App\Models\Golongan;
use App\Models\Jabatan;
use App\Models\Karyawan;
use App\Models\MppAturan;
use App\Models\MppPlan;
use App\Models\MppPlanItem;
use App\Models\PlafonPengobatan;
use App\Models\SkalaUpah;
use App\Models\SkalaUpahKolom;
use App\Models\SkalaUpahNilai;
use App\Models\SubGroup;
use App\Models\WorkLocation;
use App\Support\Karyawan\Completeness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Man Power Planning, as the MPP sheet in Excel: per position of a work location the Plan,
 * the Actual (active employees in it), who is expected to leave it this period (promotion,
 * rotation, demotion, leaving, retirement, long sickness), and the Gap:
 *
 *     Gap = Actual − (Plan + Promosi + Rotasi + Demosi + Keluar + Pensiun + Sakit)
 *
 * A minus gap is people still needed; its cost is estimated from the Skala Upah.
 */
class Mpp
{
    /** Excel's categories: the SubGroup of the position (every staff layer is STAFF). */
    public const KATEGORI = ['STAFF' => 'Staff', 'NONOM' => 'Nonom', 'OPERATOR' => 'Operator', 'MEKANIK' => 'Mekanik & Welder'];

    // --- periods -----------------------------------------------------------------------------

    /** @return array{jenis: string, tahun: int, ke: int, mulai: CarbonImmutable, selesai: CarbonImmutable} */
    public static function periodeOf(CarbonImmutable $date, ?string $jenis = null): array
    {
        $jenis ??= MppAturan::current()->periode;
        $months = $jenis === 'semester' ? 6 : 3;
        $ke = intdiv($date->month - 1, $months) + 1;
        $mulai = CarbonImmutable::create($date->year, ($ke - 1) * $months + 1, 1);

        return ['jenis' => $jenis, 'tahun' => $date->year, 'ke' => $ke, 'mulai' => $mulai, 'selesai' => $mulai->addMonths($months)->subDay()];
    }

    /** "2027-Q2" / "2027-S1" → the period, or null when it does not read as one. */
    public static function parseKode(?string $kode): ?array
    {
        if (! $kode || ! preg_match('/^(\d{4})-([QS])([1-4])$/', $kode, $m) || ($m[2] === 'S' && $m[3] > 2)) {
            return null;
        }
        $jenis = $m[2] === 'S' ? 'semester' : 'kuartal';

        return self::periodeOf(CarbonImmutable::create((int) $m[1], ((int) $m[3] - 1) * ($jenis === 'semester' ? 6 : 3) + 1, 1), $jenis);
    }

    public static function kode(array $p): string
    {
        return "{$p['tahun']}-".($p['jenis'] === 'semester' ? 'S' : 'Q').$p['ke'];
    }

    public static function label(array $p): string
    {
        $bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

        return ($p['jenis'] === 'semester' ? 'Semester ' : 'Kuartal ').$p['ke'].' '.$p['tahun'].' ('.$bulan[$p['mulai']->month - 1].'–'.$bulan[$p['selesai']->month - 1].')';
    }

    /** The periods to choose from: the past ones that have a plan, this one and the next. */
    public static function periodeOptions(?WorkLocation $location, CarbonImmutable $today): array
    {
        $now = self::periodeOf($today);
        $next = self::periodeOf($now['selesai']->addDay());
        $kodes = MppPlan::when($location, fn ($q) => $q->where('work_location_id', $location->id))
            ->where('mulai', '<', $now['mulai']->toDateString())->orderByDesc('mulai')->get()
            ->map(fn (MppPlan $p) => $p->kode())->unique()->take(8)->all();

        return collect([self::kode($next), self::kode($now), ...$kodes])->unique()
            ->map(fn (string $k) => ['value' => $k, 'label' => self::label(self::parseKode($k)), 'sekarang' => $k === self::kode($now)])->values()->all();
    }

    /**
     * The location's plan for the period; a period not planned yet starts as a copy of the
     * latest plan before it (so the plan carries on from quarter to quarter).
     */
    public static function plan(WorkLocation $location, array $p): MppPlan
    {
        $existing = MppPlan::where(['work_location_id' => $location->id, 'jenis' => $p['jenis'], 'tahun' => $p['tahun'], 'ke' => $p['ke']])->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($location, $p) {
            $from = MppPlan::where('work_location_id', $location->id)->where('mulai', '<', $p['mulai']->toDateString())->orderByDesc('mulai')->first();
            $plan = MppPlan::create([
                'work_location_id' => $location->id, 'jenis' => $p['jenis'], 'tahun' => $p['tahun'], 'ke' => $p['ke'],
                'mulai' => $p['mulai']->toDateString(), 'selesai' => $p['selesai']->toDateString(), 'dari_plan_id' => $from?->id,
            ]);
            if ($from) {
                // What was planned carries on; movements belong to their own period.
                $plan->items()->createMany($from->items->map(fn (MppPlanItem $i) => [
                    'jabatan_id' => $i->jabatan_id, 'plan' => $i->plan, 'golongan_id' => $i->golongan_id, 'sumber' => $i->sumber, 'keterangan' => $i->keterangan,
                ])->all());
            }

            return $plan;
        });
    }

    // --- the table ---------------------------------------------------------------------------

    /**
     * Every position of the plan's location with its numbers, grouped per departemen, plus
     * the totals and the per-category summary.
     */
    public static function table(MppPlan $plan, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $location = $plan->workLocation;
        $jabatans = Jabatan::with(['subGroup', 'departemen', 'section.departemen', 'divisi', 'subLocation'])
            ->where('work_location_id', $location->id)
            ->get();
        $items = $plan->items()->get()->keyBy('jabatan_id');
        $holders = Karyawan::withoutGlobalScope('akses')->with('profile:id,karyawan_id,tanggal_lahir,sakit_mulai,sakit_selesai,sakit_keterangan')
            ->where('status', Karyawan::STATUS_AKTIF)->whereIn('jabatan_id', $jabatans->pluck('id'))
            ->get(['id', 'nrp', 'name', 'jabatan_id'])->groupBy('jabatan_id');
        $lowongan = self::lowongan($jabatans->pluck('id'));
        $golonganDefault = self::golonganDefaults();
        $golongans = Golongan::pluck('kode', 'id');

        $rows = $jabatans->map(function (Jabatan $j) use ($items, $holders, $plan, $golonganDefault, $golongans, $lowongan, $location) {
            $item = $items[$j->id] ?? null;
            $people = $holders[$j->id] ?? collect();
            [$pensiunAuto, $pensiunList] = self::pensiun($people, $plan);
            [$sakitAuto, $sakitList] = self::sakit($people, $plan);
            $pensiun = $item?->pensiun ?? $pensiunAuto;
            $sakit = $item?->sakit ?? $sakitAuto;
            $actual = $people->count();
            $planCount = $item?->plan ?? 0;
            $keluar = ($item?->promosi ?? 0) + ($item?->rotasi ?? 0) + ($item?->demosi ?? 0) + ($item?->keluar ?? 0) + $pensiun + $sakit;
            $gap = $actual - ($planCount + $keluar);
            $golonganId = $item?->golongan_id ?? ($golonganDefault[$j->sub_group_id] ?? null);
            $upah = $golonganId ? self::upah($golonganId, $location->id, $plan->tahun) : null;
            $departemen = $j->departemen ?? $j->section?->departemen;

            return [
                'jabatan_id' => $j->id,
                'jabatan' => $j->name,
                'kategori' => self::kategori($j),
                'sub_group' => $j->subGroup?->nama,
                'layer' => $j->subGroup?->layer ?? 0,
                'sub_lokasi' => $j->subLocation?->name,
                'departemen_id' => $departemen?->id,
                'departemen' => $departemen?->name ?? ($j->divisi?->name ? "DIVISI {$j->divisi->name}" : 'MANAJEMEN LOKASI'),
                'departemen_order' => $departemen ? [1, $departemen->order ?? 0, $departemen->name] : [0, 0, ''],
                'order' => $j->order ?? 0,
                'plan' => $planCount,
                'actual' => $actual,
                'promosi' => $item?->promosi ?? 0,
                'rotasi' => $item?->rotasi ?? 0,
                'demosi' => $item?->demosi ?? 0,
                'keluar' => $item?->keluar ?? 0,
                'pensiun' => $pensiun,
                'pensiun_auto' => $pensiunAuto,
                'pensiun_manual' => $item?->pensiun !== null && $item !== null,
                'pensiun_list' => $pensiunList,
                'sakit' => $sakit,
                'sakit_auto' => $sakitAuto,
                'sakit_manual' => $item?->sakit !== null && $item !== null,
                'sakit_list' => $sakitList,
                'gap' => $gap,
                'bulan_pindah' => $item?->bulan_pindah ?? ($pensiunList[0]['bulan'] ?? null),
                'bulan_penuhi' => $item?->bulan_penuhi,
                'sumber' => $item?->sumber,
                'golongan_id' => $golonganId,
                'golongan' => $golonganId ? ($golongans[$golonganId] ?? null) : null,
                'golongan_manual' => (bool) $item?->golongan_id,
                'upah' => $upah,
                'biaya' => $gap < 0 && $upah ? -$gap * $upah : 0,
                'keterangan' => $item?->keterangan,
                'karyawan' => $people->map(fn (Karyawan $k) => ['nrp' => $k->nrp, 'name' => $k->name])->values(),
                'lowongan' => $lowongan[$j->id] ?? null,
            ];
        })->sort(fn ($a, $b) => [$a['departemen_order'], -$a['layer'], $a['kategori'] === 'STAFF' ? 0 : 1, $a['order'], $a['jabatan']]
            <=> [$b['departemen_order'], -$b['layer'], $b['kategori'] === 'STAFF' ? 0 : 1, $b['order'], $b['jabatan']])->values();

        $sum = fn (Collection $c) => [
            'plan' => $c->sum('plan'), 'actual' => $c->sum('actual'),
            'promosi' => $c->sum('promosi'), 'rotasi' => $c->sum('rotasi'), 'demosi' => $c->sum('demosi'), 'keluar' => $c->sum('keluar'),
            'pensiun' => $c->sum('pensiun'), 'sakit' => $c->sum('sakit'), 'gap' => $c->sum('gap'),
            'kurang' => $c->sum(fn ($r) => max(0, -$r['gap'])), 'lebih' => $c->sum(fn ($r) => max(0, $r['gap'])), 'biaya' => $c->sum('biaya'),
        ];
        $groups = $rows->groupBy('departemen')->map(fn (Collection $c, string $name) => ['departemen' => $name, 'rows' => $c->values(), 'total' => $sum($c)])->values();
        $kategori = collect(self::KATEGORI)->map(fn (string $label, string $k) => ['kategori' => $k, 'label' => $label, ...$sum($rows->where('kategori', $k))])->values();
        $belum = Karyawan::withoutGlobalScope('akses')->where('status', Karyawan::STATUS_AKTIF)->where('work_location_id', $location->id)->whereNull('jabatan_id')->count();

        return ['groups' => $groups, 'total' => $sum($rows), 'kategori' => $kategori, 'belum_ditempatkan' => $belum, 'bulan_periode' => self::bulanPeriode($plan)];
    }

    /** Totals of one location for the All Site overview (without the per-position detail). */
    public static function summary(MppPlan $plan): array
    {
        $t = self::table($plan);

        return $t['total'] + ['jabatan' => $t['groups']->sum(fn ($g) => count($g['rows']))];
    }

    public static function kategori(Jabatan $j): string
    {
        $nama = strtoupper((string) $j->subGroup?->nama);

        return match (true) {
            ($j->subGroup?->layer ?? 1) >= 2 => 'STAFF',
            str_contains($nama, 'OPERATOR') => 'OPERATOR',
            str_contains($nama, 'MEKANIK') || str_contains($nama, 'WELDER') => 'MEKANIK',
            default => 'NONOM',
        };
    }

    /** Holders who reach the retirement age inside the period. */
    private static function pensiun(Collection $people, MppPlan $plan): array
    {
        $list = $people->map(function (Karyawan $k) use ($plan) {
            $lahir = $k->profile?->tanggal_lahir;
            $tanggal = $lahir?->addYears(Completeness::PENSIUN_AGE);

            return $tanggal && $tanggal->betweenIncluded($plan->mulai, $plan->selesai)
                ? ['nrp' => $k->nrp, 'name' => $k->name, 'tanggal' => $tanggal->toDateString(), 'bulan' => $tanggal->month] : null;
        })->filter()->sortBy('tanggal')->values()->all();

        return [count($list), $list];
    }

    /** Holders on long sick leave at any time in the period. */
    private static function sakit(Collection $people, MppPlan $plan): array
    {
        $list = $people->map(function (Karyawan $k) use ($plan) {
            $mulai = $k->profile?->sakit_mulai;
            $selesai = $k->profile?->sakit_selesai;

            return $mulai && $mulai->lte($plan->selesai) && (! $selesai || $selesai->gte($plan->mulai))
                ? ['nrp' => $k->nrp, 'name' => $k->name, 'mulai' => $mulai->toDateString(), 'selesai' => $selesai?->toDateString(), 'keterangan' => $k->profile->sakit_keterangan] : null;
        })->filter()->values()->all();

        return [count($list), $list];
    }

    /** The months of the period, for "Waktu pindah / pemenuhan". */
    private static function bulanPeriode(MppPlan $plan): array
    {
        $bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $out = [];
        for ($m = $plan->mulai->month; $m <= $plan->selesai->month; $m++) {
            $out[] = ['value' => $m, 'label' => $bulan[$m - 1]];
        }

        return $out;
    }

    // --- costs -------------------------------------------------------------------------------

    /**
     * SubGroup => golongan for the cost: as set in Master Data, else the lowest golongan its
     * Plafon Pengobatan row expects.
     *
     * @return array<int, int>
     */
    public static function golonganDefaults(): array
    {
        static $cache = null;
        if ($cache !== null && ! app()->runningUnitTests()) {
            return $cache;
        }
        $golongans = Golongan::orderBy('urutan')->orderBy('id')->get(['id', 'grup']);
        $order = $golongans->pluck('id')->flip();
        $grupOf = $golongans->pluck('grup', 'id')->map(fn ($g) => strtoupper((string) $g));
        $subGroups = SubGroup::pluck('nama', 'id')->map(fn ($n) => strtoupper((string) $n));
        $map = [];
        foreach (PlafonPengobatan::orderBy('urutan')->get() as $row) {
            $ids = collect($row->golongan_ids ?? [])->sortBy(fn ($id) => $order[$id] ?? PHP_INT_MAX)->values();
            foreach ($row->sub_group_ids ?? [] as $sg) {
                // A row shared by several SubGroups (Nonom, Operator, Mekanik, Welder): the lowest golongan of its own group.
                $own = $ids->first(fn ($id) => ($nama = $subGroups[$sg] ?? '') !== '' && str_contains($nama, $grupOf[$id] ?? '#'));
                $map[(int) $sg] ??= ($own ?? $ids->first()) ? (int) ($own ?? $ids->first()) : null;
            }
        }
        foreach (MppAturan::current()->golongan_sub_group ?? [] as $sg => $golongan) {
            if ($golongan) {
                $map[(int) $sg] = (int) $golongan;
            }
        }

        return $cache = array_filter($map);
    }

    /** The monthly wage of the golongan's first step at the location in that year (or the nearest earlier year). */
    public static function upah(int $golonganId, int $workLocationId, int $tahun): ?int
    {
        static $cache = [];
        $key = "{$golonganId}:{$workLocationId}:{$tahun}";
        if (array_key_exists($key, $cache) && ! app()->runningUnitTests()) {
            return $cache[$key];
        }
        $step = SkalaUpah::where('golongan_id', $golonganId)->orderBy('urutan')->orderBy('id')->value('id');
        $kolom = SkalaUpahKolom::where('tahun', '<=', $tahun)
            ->where(fn ($q) => $q->where('work_location_id', $workLocationId)->orWhereNull('work_location_id'))
            ->orderByDesc('tahun')->orderByRaw('work_location_id is null')->value('id');

        return $cache[$key] = $step && $kolom ? SkalaUpahNilai::where(['skala_upah_id' => $step, 'kolom_id' => $kolom])->value('nominal') : null;
    }

    // --- recruitment -------------------------------------------------------------------------

    /**
     * Open vacancies made for these positions, with the candidates still in process.
     *
     * @return array<int, array{form_id: int, title: string, is_open: bool, pelamar: int, kandidat: int}>
     */
    public static function lowongan(Collection $jabatanIds): array
    {
        $forms = ApplicationForm::whereIn('jabatan_id', $jabatanIds)->orderByDesc('id')->get(['id', 'title', 'is_open', 'jabatan_id']);
        $out = [];
        foreach ($forms->groupBy('jabatan_id') as $jabatanId => $list) {
            $ids = $list->pluck('id');
            $out[$jabatanId] = [
                'form_id' => $list->first()->id,
                'title' => $list->first()->title,
                'is_open' => $list->contains('is_open', true),
                'pelamar' => Applicant::whereIn('application_form_id', $ids)->where('status', 'baru')->count(),
                'kandidat' => Candidate::whereNull('ditolak_at')->whereDoesntHave('karyawan')
                    ->whereHas('applicant', fn ($q) => $q->whereIn('application_form_id', $ids))->count(),
            ];
        }

        return $out;
    }
}
