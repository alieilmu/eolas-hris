<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AbsenLog;
use App\Models\Karyawan;
use App\Models\Lembur;
use App\Models\MesinAbsen;
use App\Models\WorkLocation;
use App\Support\Access\AccessScope;
use App\Support\Attendance\Roll;
use App\Support\Kehadiran\AbsenFile;
use App\Support\Kehadiran\Ingest;
use App\Support\Kehadiran\MesinRoute;
use App\Support\Kehadiran\Proses;
use App\Support\Kehadiran\Resume;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Attendance → Kehadiran: clock in / clock out (CI/CO) from the attendance machines.
 *  - Resume: each employee's days against the roster — complete, missing CI or CO,
 *    absent, not matching the roster, late, early, working time and overtime;
 *  - Lembur: overtime counted from CI/CO, approved before payroll;
 *  - Log Absen: the raw logs, corrections by HR and file imports;
 *  - Mesin Absen: the machines, added and removed here, pushing over ADMS.
 */
class KehadiranController extends Controller
{
    public const TABS = ['resume', 'lembur', 'log', 'mesin'];

    /** The longest stretch a page reads at once. */
    private const MAKS_HARI = 62;

    public function index(Request $request): Response
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'resume';
        [$dari, $sampai] = $this->range($request);
        $locations = AccessScope::locations();
        $lokasi = $request->integer('lokasi') ?: null;
        if ($lokasi && ! $locations->contains('id', $lokasi)) {
            $lokasi = null;
        }
        $q = trim((string) $request->query('q'));
        $user = $request->user();

        $props = [
            'tab' => $tab,
            'filters' => ['dari' => $dari->toDateString(), 'sampai' => $sampai->toDateString(), 'lokasi' => $lokasi, 'q' => $q, 'status' => (string) $request->query('status', 'masalah')],
            'locations' => $locations,
            'status_labels' => Resume::STATUS,
            'can' => ['edit' => $user->canEdit('kehadiran'), 'approve' => $user->canEdit('lembur-approve')],
            'mesin_baru' => MesinAbsen::where('status', 'baru')->count(),
        ];

        return Inertia::render('Attendance/Kehadiran', $props + match ($tab) {
            'resume' => $this->resume($dari, $sampai, $lokasi, $q, $props['filters']['status']),
            'lembur' => $this->lembur($dari, $sampai, $lokasi, $q, (string) $request->query('status', 'menunggu')),
            'log' => $this->logs($dari, $sampai, $lokasi, $q),
            'mesin' => $this->mesin($request),
        });
    }

    /** Reprocesses the stretch into Absensi and Lembur. */
    public function proses(Request $request): RedirectResponse
    {
        [$dari, $sampai] = $this->range($request);
        $lokasi = $request->integer('lokasi') ?: null;
        $ids = $this->karyawans($dari, $sampai, $lokasi)->pluck('id')->all();
        $hasil = Proses::run($dari, $sampai, $ids);
        Inertia::flash('success', "Kehadiran diproses: {$hasil['absensi']} hari Absensi diperbarui, {$hasil['lembur']} lembur dihitung.");

        return back();
    }

    /** A clock-in added by HR (a forgotten CI/CO), with its reason. */
    public function storeLog(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'karyawan_id' => ['required', 'integer'],
            'waktu' => ['required', 'date_format:Y-m-d\TH:i'],
            'catatan' => ['required', 'string', 'max:255'],
        ], [], ['waktu' => 'Waktu', 'catatan' => 'Alasan']);
        $k = Karyawan::findOrFail($data['karyawan_id']); // scoped: only the account's locations
        $hasil = Ingest::store([['pin' => $k->pin(), 'waktu' => str_replace('T', ' ', $data['waktu']).':00']], null, 'manual', $request->user()->id, $data['catatan']);
        if (! $hasil['diterima']) {
            throw ValidationException::withMessages(['waktu' => 'Log pada waktu ini sudah ada.']);
        }
        Inertia::flash('success', "Log absen {$k->name} ditambahkan.");

        return back();
    }

    /** Only HR's own corrections can be removed; a machine's log stays. */
    public function destroyLog(AbsenLog $log): RedirectResponse
    {
        abort_unless($log->sumber === 'manual' && $log->karyawan_id && Karyawan::whereKey($log->karyawan_id)->exists(), 403);
        $tanggal = $log->waktu->startOfDay();
        $kid = $log->karyawan_id;
        $log->delete();
        Proses::run($tanggal->subDay(), $tanggal, [$kid]);
        Inertia::flash('success', 'Koreksi log absen dihapus.');

        return back();
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:txt,dat,csv,xlsx,xls'],
            'mesin_absen_id' => ['nullable', 'integer', 'exists:mesin_absens,id'],
        ], [], ['file' => 'File']);
        $file = $request->file('file');
        $read = AbsenFile::read($file->getRealPath(), $file->getClientOriginalExtension());
        if (! $read['rows']) {
            throw ValidationException::withMessages(['file' => 'Tidak ada baris yang terbaca. Kolom: PIN/NRP, lalu tanggal-jam (atau tanggal dan jam).']);
        }
        $mesin = $request->integer('mesin_absen_id') ? MesinAbsen::find($request->integer('mesin_absen_id')) : null;
        $hasil = Ingest::store($read['rows'], $mesin, 'import', $request->user()->id);
        $salah = $read['salah'] ? ' '.count($read['salah']).' baris tidak terbaca.' : '';
        Inertia::flash('success', "{$hasil['diterima']} log diimpor ({$hasil['duplikat']} sudah ada, {$hasil['tanpa_karyawan']} PIN tidak dikenal).{$salah}");

        return back();
    }

    // --- Mesin Absen -------------------------------------------------------------------------

    public function storeMesin(Request $request): RedirectResponse
    {
        $data = $this->mesinData($request);
        DB::transaction(function () use ($data) {
            MesinRoute::claim($data['serial_number']);
            MesinAbsen::create($data);
        });
        Inertia::flash('success', "Mesin {$data['nama']} ditambahkan.");

        return back();
    }

    public function updateMesin(Request $request, MesinAbsen $mesin): RedirectResponse
    {
        $data = $this->mesinData($request, $mesin);
        DB::transaction(function () use ($data, $mesin) {
            MesinRoute::claim($data['serial_number'], $mesin->serial_number);
            $mesin->update($data);
        });
        Inertia::flash('success', "Mesin {$mesin->nama} diperbarui.");

        return back();
    }

    /** Removes the machine; its logs stay (without a machine). */
    public function destroyMesin(MesinAbsen $mesin): RedirectResponse
    {
        MesinRoute::release($mesin->serial_number);
        $mesin->delete();
        Inertia::flash('success', "Mesin {$mesin->nama} dikeluarkan.");

        return back();
    }

    // --- Lembur ----------------------------------------------------------------------------------

    public function verifyLembur(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'keputusan' => ['required', Rule::in(['disetujui', 'ditolak', 'menunggu'])],
            'catatan' => ['nullable', 'string', 'max:255'],
        ]);
        $visible = Karyawan::pluck('id'); // scoped
        $n = Lembur::whereIn('id', $data['ids'])->whereIn('karyawan_id', $visible)->update([
            'status' => $data['keputusan'],
            'verified_by' => $data['keputusan'] === 'menunggu' ? null : $request->user()->id,
            'verified_at' => $data['keputusan'] === 'menunggu' ? null : now(),
            'catatan_verifikasi' => $data['catatan'] ?? null,
            'updated_at' => now(),
        ]);
        Inertia::flash('success', "{$n} lembur ".['disetujui' => 'disetujui', 'ditolak' => 'ditolak', 'menunggu' => 'dikembalikan ke menunggu'][$data['keputusan']].'.');

        return back();
    }

    // --- pieces ------------------------------------------------------------------------------------

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(Request $request): array
    {
        $today = CarbonImmutable::today();
        $parse = fn (?string $v) => $v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? CarbonImmutable::parse($v) : null;
        $dari = $parse($request->input('dari')) ?? $today->startOfMonth();
        $sampai = $parse($request->input('sampai')) ?? $today;
        if ($sampai->lt($dari)) {
            [$dari, $sampai] = [$sampai, $dari];
        }
        if ($dari->diffInDays($sampai) >= self::MAKS_HARI) {
            $sampai = $dari->addDays(self::MAKS_HARI - 1);
        }

        return [$dari, $sampai];
    }

    /** Employees of the account's locations employed in the stretch. */
    private function karyawans(CarbonImmutable $dari, CarbonImmutable $sampai, ?int $lokasi, string $q = ''): Collection
    {
        return Roll::between($dari, $sampai, $lokasi)
            ->when($q !== '', fn ($c) => $c->filter(fn (Karyawan $k) => str_contains(mb_strtolower($k->name.' '.$k->nrp), mb_strtolower($q))))
            ->values()
            ->load(['workLocation', 'subGroup']);
    }

    private function resume(CarbonImmutable $dari, CarbonImmutable $sampai, ?int $lokasi, string $q, string $status): array
    {
        $rows = collect(Resume::build($this->karyawans($dari, $sampai, $lokasi, $q), $dari, $sampai));
        $count = $rows->countBy('status');
        $shown = match ($status) {
            'semua' => $rows,
            'masalah' => $rows->whereIn('status', Resume::MASALAH),
            'terlambat' => $rows->filter(fn ($r) => $r['terlambat'] > 0),
            'pulang_cepat' => $rows->filter(fn ($r) => $r['pulang_cepat'] > 0),
            'lembur' => $rows->filter(fn ($r) => $r['berhak_lembur'] && ($r['lembur']['menit_lembur'] ?? 0) > 0),
            default => $rows->where('status', $status),
        };
        $jadwal = $rows->filter(fn ($r) => $r['roster_jenis'] === 'hadir');

        return [
            'summary' => [
                'karyawan' => $rows->pluck('karyawan_id')->unique()->count(),
                'hari_jadwal' => $jadwal->count(),
                'lengkap' => $count['lengkap'] ?? 0,
                'tanpa_ci' => $count['tanpa_ci'] ?? 0,
                'tanpa_co' => $count['tanpa_co'] ?? 0,
                'tidak_hadir' => $count['tidak_hadir'] ?? 0,
                'tidak_sesuai' => $count['tidak_sesuai'] ?? 0,
                'terlambat' => $rows->where('terlambat', '>', 0)->count(),
                'pulang_cepat' => $rows->where('pulang_cepat', '>', 0)->count(),
                'jam_lembur' => round($rows->filter(fn ($r) => $r['berhak_lembur'])->sum(fn ($r) => $r['lembur']['jam_dibayar'] ?? 0), 1),
                'kepatuhan' => $jadwal->count() ? round(($count['lengkap'] ?? 0) / $jadwal->count() * 100, 1) : null,
            ],
            'rows' => $shown->sortBy([['tanggal', 'desc'], ['nama', 'asc']])->take(2000)->values(),
            'total_rows' => $shown->count(),
            'karyawan_options' => $this->karyawans($dari, $sampai, $lokasi)->map(fn (Karyawan $k) => ['id' => $k->id, 'label' => "{$k->nrp} · {$k->name}"])->values(),
        ];
    }

    private function lembur(CarbonImmutable $dari, CarbonImmutable $sampai, ?int $lokasi, string $q, string $status): array
    {
        $base = Lembur::query()->whereBetween('tanggal', [$dari->toDateString(), $sampai->toDateString()])
            ->whereHas('karyawan', fn ($k) => $k->when($lokasi, fn ($k) => $k->where('work_location_id', $lokasi))
                ->when($q !== '', fn ($k) => $k->where(fn ($k) => $k->where('name', 'like', "%{$q}%")->orWhere('nrp', 'like', "%{$q}%"))));
        $sum = (clone $base)->selectRaw('status, count(*) n, sum(jam_dibayar) jam, sum(jam_terhitung) terhitung')->groupBy('status')->get()->keyBy('status');

        return [
            'lembur_summary' => collect(Lembur::STATUS)->map(fn ($label, $key) => [
                'key' => $key, 'label' => $label, 'n' => (int) ($sum[$key]->n ?? 0),
                'jam' => round((float) ($sum[$key]->jam ?? 0), 1), 'terhitung' => round((float) ($sum[$key]->terhitung ?? 0), 1),
            ])->values(),
            'lemburs' => (clone $base)->when(in_array($status, array_keys(Lembur::STATUS), true), fn ($q) => $q->where('status', $status))
                ->with(['karyawan:id,nrp,name,work_location_id', 'karyawan.workLocation:id,name,skema_kerja', 'verifier:id,name'])
                ->orderByDesc('tanggal')->limit(1500)->get()
                ->map(fn (Lembur $l) => [
                    'id' => $l->id, 'tanggal' => $l->tanggal->toDateString(), 'nrp' => $l->karyawan?->nrp, 'nama' => $l->karyawan?->name,
                    'lokasi' => $l->karyawan?->workLocation?->name, 'jenis_hari' => $l->jenis_hari,
                    'mulai' => $l->mulai?->format('d/m H:i'), 'selesai' => $l->selesai?->format('d/m H:i'),
                    'jam_kerja' => round($l->menit_kerja / 60, 2), 'jam_dibayar' => $l->jam_dibayar, 'jam_terhitung' => $l->jam_terhitung,
                    'status' => $l->status, 'catatan_verifikasi' => $l->catatan_verifikasi, 'verifier' => $l->verifier?->name,
                ]),
            'lembur_status' => $status,
        ];
    }

    private function logs(CarbonImmutable $dari, CarbonImmutable $sampai, ?int $lokasi, string $q): array
    {
        $visible = $this->karyawans($dari, $sampai, $lokasi, $q)->pluck('id');

        return [
            'logs' => AbsenLog::with(['karyawan:id,nrp,name', 'mesin:id,nama'])
                ->whereBetween('waktu', [$dari->toDateString().' 00:00:00', $sampai->toDateString().' 23:59:59'])
                // Logs of an unknown PIN only for an account that sees every location, unfiltered.
                ->where(fn ($w) => $w->whereIn('karyawan_id', $visible)
                    ->when($lokasi === null && $q === '' && AccessScope::workLocationIds() === null, fn ($w) => $w->orWhereNull('karyawan_id')))
                ->orderByDesc('waktu')->limit(1500)->get()
                ->map(fn (AbsenLog $l) => [
                    'id' => $l->id, 'waktu' => $l->waktu->format('Y-m-d H:i:s'), 'pin' => $l->pin,
                    'nrp' => $l->karyawan?->nrp, 'nama' => $l->karyawan?->name, 'mesin' => $l->mesin?->nama,
                    'sumber' => $l->sumber, 'catatan' => $l->catatan,
                ]),
            'karyawan_options' => $this->karyawans($dari, $sampai, $lokasi)->map(fn (Karyawan $k) => ['id' => $k->id, 'label' => "{$k->nrp} · {$k->name}"])->values(),
            'mesin_options' => MesinAbsen::where('status', '!=', 'baru')->orderBy('nama')->get(['id', 'nama']),
        ];
    }

    private function mesin(Request $request): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $counts = AbsenLog::whereDate('waktu', $today)->whereNotNull('mesin_absen_id')->selectRaw('mesin_absen_id, count(*) n')->groupBy('mesin_absen_id')->pluck('n', 'mesin_absen_id');
        $last = AbsenLog::whereNotNull('mesin_absen_id')->selectRaw('mesin_absen_id, max(waktu) w')->groupBy('mesin_absen_id')->pluck('w', 'mesin_absen_id');

        return [
            'mesins' => MesinAbsen::with('workLocation:id,name')->orderByRaw("status = 'baru' desc")->orderBy('nama')->get()->map(fn (MesinAbsen $m) => [
                'id' => $m->id, 'nama' => $m->nama, 'serial_number' => $m->serial_number, 'merek' => $m->merek, 'model' => $m->model,
                'work_location_id' => $m->work_location_id, 'lokasi' => $m->workLocation?->name, 'status' => $m->status,
                'online' => $m->online(), 'last_seen_at' => $m->last_seen_at?->toDateTimeString(), 'last_ip' => $m->last_ip,
                'log_hari_ini' => (int) ($counts[$m->id] ?? 0), 'log_terakhir' => $last[$m->id] ?? null, 'catatan' => $m->catatan,
            ]),
            'work_locations' => WorkLocation::orderBy('order')->get(['id', 'name']),
            'server' => ['host' => $request->getHost(), 'path' => '/iclock/cdata'],
        ];
    }

    private function mesinData(Request $request, ?MesinAbsen $current = null): array
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:100'],
            'serial_number' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('mesin_absens', 'serial_number')->ignore($current?->id)],
            'merek' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'work_location_id' => ['nullable', 'integer', 'exists:work_locations,id'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
            'catatan' => ['nullable', 'string', 'max:255'],
        ], ['serial_number.regex' => 'Serial number hanya huruf, angka, titik, - dan _.', 'serial_number.unique' => 'Serial number ini sudah terdaftar.'], ['serial_number' => 'Serial number']);
        $data['serial_number'] = trim($data['serial_number']);
        if ($data['work_location_id'] ?? null) {
            AccessScope::assertWorkLocation((int) $data['work_location_id']);
        }

        return $data;
    }
}
