<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use App\Models\ApplicationForm;
use App\Models\Candidate;
use App\Models\Karyawan;
use App\Models\MppPlan;
use App\Models\RecruitmentStage;
use App\Support\Access\AccessScope;
use App\Support\Attendance\Onsite;
use App\Support\Mpp\Mpp;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Eolas dashboard: one look over every module — people, attendance, leave and travel,
 * Comben, recruitment and Man Power Planning — for the account's locations. Lists with
 * names only appear for the menus the account may open.
 */
class DashboardController extends Controller
{
    private const BULAN = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    public function __invoke(Request $request): RedirectResponse|Response
    {
        $user = $request->user();
        if ($user->isKaryawan()) {
            return redirect()->route('portal');
        }
        AccessScope::enable();

        $today = CarbonImmutable::today();
        $locations = AccessScope::locations();
        $lokasi = $request->integer('lokasi') ?: null;
        if ($lokasi && ! $locations->contains('id', $lokasi)) {
            $lokasi = null;
        }
        $ids = $this->karyawanIds($lokasi);

        return Inertia::render('Dashboard', [
            'today' => $today->toDateString(),
            'lokasi' => $lokasi,
            'locations' => $locations,
            'people' => $this->people($lokasi, $today),
            'onsite' => rescue(fn () => Onsite::daily($today->subDays(20), $today->addDays(9), $lokasi), [], false),
            'kehadiran' => $this->kehadiran($ids, $today),
            'pengajuan' => $this->pengajuan($ids),
            'perjalanan' => $this->perjalanan($ids, $today, $user->canView('trip-leave', 'pengajuan-dinas')),
            'comben' => $this->comben($ids, $today),
            'recruitment' => $this->recruitment($today),
            'mpp' => $this->mpp($lokasi, $locations),
            'ulang_tahun' => $user->canView('karyawan') ? $this->ulangTahun($lokasi, $today) : null,
        ]);
    }

    /** Active employees of the account's locations (or of one of them). */
    private function karyawanIds(?int $lokasi): Collection
    {
        return Karyawan::where('status', Karyawan::STATUS_AKTIF)->when($lokasi, fn ($q) => $q->where('work_location_id', $lokasi))->pluck('id');
    }

    private function people(?int $lokasi, CarbonImmutable $today): array
    {
        $aktif = Karyawan::where('status', Karyawan::STATUS_AKTIF)->when($lokasi, fn ($q) => $q->where('work_location_id', $lokasi))
            ->with(['profile.pkwtPeriods', 'workLocation'])->get();
        $keluar = Karyawan::where('status', Karyawan::STATUS_KELUAR)->when($lokasi, fn ($q) => $q->where('work_location_id', $lokasi))
            ->whereYear('tanggal_keluar', $today->year)->count();

        $tally = fn (callable $key) => $aktif->groupBy($key)->map->count()->sortDesc()->map(fn ($n, $label) => ['label' => (string) $label, 'value' => $n])->values();
        $usia = ['< 25' => 0, '25–34' => 0, '35–44' => 0, '45–54' => 0, '55+' => 0];
        $masaKerja = ['< 1 th' => 0, '1–3 th' => 0, '3–5 th' => 0, '5–10 th' => 0, '10+ th' => 0];
        $pkwtHabis = 0;
        $baru = 0;
        foreach ($aktif as $k) {
            $p = $k->profile;
            if ($lahir = $p?->tanggal_lahir) {
                $u = $lahir->diffInYears($today);
                $usia[match (true) { $u < 25 => '< 25', $u < 35 => '25–34', $u < 45 => '35–44', $u < 55 => '45–54', default => '55+' }]++;
            }
            if ($masuk = $p?->tanggal_masuk) {
                $m = $masuk->diffInYears($today);
                $masaKerja[match (true) { $m < 1 => '< 1 th', $m < 3 => '1–3 th', $m < 5 => '3–5 th', $m < 10 => '5–10 th', default => '10+ th' }]++;
                $baru += $masuk->gte($today->subDays(90)) ? 1 : 0;
            }
            $akhir = $p?->status_pegawai_tetap ? null : $p?->pkwtPeriods?->sortByDesc('tanggal_berakhir')->first()?->tanggal_berakhir;
            $pkwtHabis += $akhir && $akhir->between($today, $today->addDays(30)) ? 1 : 0;
        }
        $pairs = fn (array $a) => collect($a)->map(fn ($n, $label) => ['label' => $label, 'value' => $n])->values();

        return [
            'aktif' => $aktif->count(),
            'keluar_tahun_ini' => $keluar,
            'baru_90_hari' => $baru,
            'pkwt_30_hari' => $pkwtHabis,
            'per_lokasi' => $tally(fn ($k) => $k->workLocation?->name ?? 'Belum ditempatkan'),
            'gender' => $tally(fn ($k) => $k->profile?->jenis_kelamin ?: 'Belum diisi'),
            'status_pegawai' => $tally(fn ($k) => $k->profile?->status_pegawai_tetap ? 'Tetap' : 'PKWT'),
            'klasifikasi' => $tally(fn ($k) => $k->profile?->klasifikasi ?: 'Belum diisi'),
            'usia' => $pairs($usia),
            'masa_kerja' => $pairs($masaKerja),
        ];
    }

    /** Absensi of the last 30 days by kind (hadir, sakit, izin, mangkir, ...), and the attendance rate. */
    private function kehadiran(Collection $ids, CarbonImmutable $today): array
    {
        $rows = DB::table('absensis')->join('shift_codes', 'shift_codes.id', '=', 'absensis.shift_code_id')
            ->whereIn('absensis.karyawan_id', $ids)->whereBetween('absensis.tanggal', [$today->subDays(30)->toDateString(), $today->subDay()->toDateString()])
            ->selectRaw('shift_codes.jenis as k, count(*) as v')->groupBy('shift_codes.jenis')->pluck('v', 'k')->map(fn ($n) => (int) $n);
        $kerja = $rows->only(['hadir', 'sakit', 'izin', 'mangkir', 'dinas'])->sum();

        return [
            'per_jenis' => $rows->map(fn ($n, $jenis) => ['label' => ucfirst((string) $jenis), 'key' => $jenis, 'value' => $n])->values(),
            'rate' => $kerja > 0 ? round((($rows['hadir'] ?? 0) + ($rows['dinas'] ?? 0)) / $kerja * 100, 1) : null,
        ];
    }

    /** Pengajuan from the portal waiting for HR. */
    private function pengajuan(Collection $ids): array
    {
        $count = fn (string $table) => DB::table($table)->where('status', 'diajukan')->whereIn('karyawan_id', $ids)->count();

        return [
            ['key' => 'trip', 'label' => 'Cuti & Dinas', 'value' => $count('trips'), 'href' => '/attendance/pengajuan-dinas', 'menu' => 'pengajuan-dinas'],
            ['key' => 'deklarasi', 'label' => 'Deklarasi Pengobatan', 'value' => $count('deklarasis'), 'href' => '/comben/deklarasi', 'menu' => 'deklarasi'],
            ['key' => 'update', 'label' => 'Update Data Karyawan', 'value' => $count('pengajuan_update_datas'), 'href' => '/manpower/pengajuan-data', 'menu' => 'update-data'],
            ['key' => 'cuti_besar', 'label' => 'Pilihan Cuti Besar', 'value' => $count('cuti_besars'), 'href' => '/comben/cuti', 'menu' => 'cuti'],
        ];
    }

    /** Dinas and cuti running today, and the ones coming in the next two weeks. */
    private function perjalanan(Collection $ids, CarbonImmutable $today, bool $names): array
    {
        $base = fn () => DB::table('trips')->where('trips.status', 'disetujui')->whereIn('trips.karyawan_id', $ids);
        $d = $today->toDateString();
        $running = $base()->where('trips.tanggal_awal', '<=', $d)->where('trips.tanggal_akhir', '>=', $d)->selectRaw('trips.jenis as k, count(*) as v')->groupBy('trips.jenis')->pluck('v', 'k');
        $coming = $names ? $base()->join('karyawans', 'karyawans.id', '=', 'trips.karyawan_id')
            ->whereBetween('trips.tanggal_awal', [$today->addDay()->toDateString(), $today->addDays(14)->toDateString()])
            ->orderBy('trips.tanggal_awal')->limit(6)
            ->get(['karyawans.name', 'trips.jenis', 'trips.kategori_cuti', 'trips.tanggal_awal', 'trips.tanggal_akhir', 'trips.kota_tujuan']) : null;

        return ['dinas_hari_ini' => (int) ($running['dinas'] ?? 0), 'cuti_hari_ini' => (int) ($running['cuti'] ?? 0), 'akan_datang' => $coming];
    }

    /** Deklarasi approved in this plafon year: per month and per kind. */
    private function comben(Collection $ids, CarbonImmutable $today): array
    {
        $items = DB::table('deklarasi_items')->join('deklarasis', 'deklarasis.id', '=', 'deklarasi_items.deklarasi_id')
            ->where('deklarasis.status', 'disetujui')->where('deklarasis.tahun_plafon', $today->year)->whereIn('deklarasis.karyawan_id', $ids);
        $perBulan = (clone $items)->selectRaw('deklarasis.bulan_buku as k, sum(deklarasi_items.nominal) as v')->groupBy('deklarasis.bulan_buku')->pluck('v', 'k');
        $perJenis = (clone $items)->selectRaw('deklarasi_items.jenis as k, sum(deklarasi_items.nominal) as v')->groupBy('deklarasi_items.jenis')->pluck('v', 'k');

        return [
            'total' => (int) $perJenis->map(fn ($v) => (int) $v)->sum(),
            'jumlah' => (clone $items)->distinct()->count('deklarasis.id'),
            'per_bulan' => collect(range(1, 12))->map(fn ($m) => [
                'label' => self::BULAN[$m], 'value' => (int) ($perBulan[sprintf('%d-%02d', $today->year, $m)] ?? 0),
            ])->values(),
            'per_jenis' => $perJenis->map(fn ($v, $jenis) => ['label' => ucfirst((string) $jenis), 'value' => (int) $v])->sortByDesc('value')->values(),
        ];
    }

    /** Candidates per stage, new applicants and open vacancies. */
    private function recruitment(CarbonImmutable $today): array
    {
        $perStage = Candidate::whereNull('ditolak_at')->selectRaw('recruitment_stage_id as k, count(*) as v')->groupBy('recruitment_stage_id')->pluck('v', 'k');

        return [
            'stages' => RecruitmentStage::orderBy('order')->get(['id', 'title', 'color'])->map(fn ($s) => [
                'label' => $s->title, 'color' => $s->color, 'value' => (int) ($perStage[$s->id] ?? 0),
            ])->values(),
            'pelamar_baru' => Applicant::where('status', 'baru')->count(),
            'pelamar_30_hari' => Applicant::where('submitted_at', '>=', $today->subDays(30))->count(),
            'lowongan_buka' => ApplicationForm::where('is_open', true)->count(),
        ];
    }

    /** Plan, actual and gap of the latest Man Power Planning per work location. */
    private function mpp(?int $lokasi, Collection $locations): array
    {
        return $locations->when($lokasi, fn ($c) => $c->where('id', $lokasi))->map(function ($loc) {
            $plan = MppPlan::where('work_location_id', $loc->id)->orderByDesc('tahun')->orderByDesc('ke')->orderByDesc('id')->first();
            $s = $plan ? rescue(fn () => Mpp::summary($plan), null, false) : null;

            return $s ? ['label' => $loc->name, 'plan' => (int) ($s['plan'] ?? 0), 'actual' => (int) ($s['actual'] ?? 0), 'gap' => (int) ($s['gap'] ?? 0)] : null;
        })->filter()->values()->all();
    }

    /** Birthdays in the coming 30 days. */
    private function ulangTahun(?int $lokasi, CarbonImmutable $today): array
    {
        return Karyawan::where('status', Karyawan::STATUS_AKTIF)->when($lokasi, fn ($q) => $q->where('work_location_id', $lokasi))
            ->with(['profile:id,karyawan_id,tanggal_lahir', 'jabatan:id,name'])->get()
            ->filter(fn ($k) => $k->profile?->tanggal_lahir)
            ->map(function ($k) use ($today) {
                $lahir = $k->profile->tanggal_lahir;
                $next = $lahir->setYear($today->year);
                $next = $next->lt($today) ? $next->addYear() : $next;

                return ['name' => $k->name, 'jabatan' => $k->jabatan?->name, 'tanggal' => $next->toDateString(), 'usia' => (int) round($lahir->diffInYears($next)), 'hari' => (int) $today->diffInDays($next)];
            })
            ->filter(fn ($b) => $b['hari'] <= 30)->sortBy('hari')->take(6)->values()->all();
    }
}
