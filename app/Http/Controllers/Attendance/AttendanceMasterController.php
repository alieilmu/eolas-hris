<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\LemburAturan;
use App\Models\MasterOption;
use App\Models\RosterPattern;
use App\Models\ShiftCode;
use App\Models\SubGroup;
use App\Models\WorkLocation;
use App\Support\Kehadiran\Jadwal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use App\Support\Karyawan\MasterOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Master Data Attendance: Kode Shift (with their hours), Pola Roster, Status Pengajuan Uang, Hari Libur, and Waktu Kerja & Lembur. */
class AttendanceMasterController extends Controller
{
    public function index(): Response
    {
        $patterns = RosterPattern::orderBy('nama')->get();

        return Inertia::render('Attendance/MasterData', [
            'codes' => ShiftCode::withCount(['rosters', 'absensis'])->orderBy('order')->orderBy('id')->get()->map(fn (ShiftCode $c) => [
                'id' => $c->id,
                'kode' => $c->kode,
                'dws' => $c->dws,
                'keterangan' => $c->keterangan,
                'jenis' => $c->jenis,
                'jam_masuk' => ShiftCode::hhmm($c->jam_masuk),
                'jam_keluar' => ShiftCode::hhmm($c->jam_keluar),
                'istirahat_mulai' => ShiftCode::hhmm($c->istirahat_mulai),
                'istirahat_selesai' => ShiftCode::hhmm($c->istirahat_selesai),
                'jam_kerja' => ($j = Jadwal::of($c, CarbonImmutable::today())) ? round($j->menitKerja() / 60, 2) : null,
                'usage' => $c->rosters_count + $c->absensis_count,
                'patterns' => $patterns->filter(fn (RosterPattern $p) => $p->usesCode($c->id))->pluck('nama')->values(),
            ]),
            'jenis_options' => ShiftCode::JENIS,
            'patterns' => $patterns->map(fn (RosterPattern $p) => ['id' => $p->id, 'nama' => $p->nama, 'langkah' => $p->langkah]),
            'status_uang' => [
                'kategori' => TripController::STATUS_UANG,
                'label' => MasterOptions::CATEGORIES[TripController::STATUS_UANG]['label'],
                'values' => MasterOption::where('kategori', TripController::STATUS_UANG)->orderBy('urutan')->orderBy('id')->get()
                    ->map(fn (MasterOption $o) => [
                        'id' => $o->id,
                        'nilai' => $o->nilai,
                        'sistem' => $o->sistem,
                        'usage' => MasterOptions::usage(TripController::STATUS_UANG, $o->nilai),
                    ]),
            ],
            // Waktu Kerja & Lembur.
            'waktu_kerja' => WorkLocation::orderBy('order')->get(['id', 'name', 'type', 'skema_kerja', 'jam_kerja_harian']),
            'skema_options' => WorkLocation::SKEMA_KERJA,
            'lembur_aturan' => LemburAturan::current()->only(['pembulatan_menit', 'minimal_menit', 'hitung_sebelum_jadwal', 'batas_harian_jam', 'batas_mingguan_jam']),
            'hak_lembur' => SubGroup::orderByDesc('layer')->orderBy('nama')->get(['id', 'nama', 'layer'])->map(function (SubGroup $g) {
                $set = DB::table('sub_group_lembur')->where('sub_group_id', $g->id)->pluck('berhak', 'tipe_lokasi');

                return ['id' => $g->id, 'nama' => $g->nama, 'layer' => $g->layer,
                    'ho' => (bool) ($set[WorkLocation::TYPE_HEAD_OFFICE] ?? true), 'site' => (bool) ($set['Site'] ?? true)];
            }),
            'holidays' => Holiday::orderByDesc('tanggal')->get()->map(fn (Holiday $h) => [
                'id' => $h->id,
                'tanggal' => $h->tanggal->toDateString(),
                'nama' => $h->nama,
            ]),
        ]);
    }

    // --- Kode Shift ------------------------------------------------------------------------

    public function storeCode(Request $request): RedirectResponse
    {
        $code = ShiftCode::create([...$this->codeData($request), 'order' => (ShiftCode::max('order') ?? -1) + 1]);
        Inertia::flash('success', "Kode Shift {$code->kode} ditambahkan.");

        return back();
    }

    public function updateCode(Request $request, ShiftCode $shiftCode): RedirectResponse
    {
        $shiftCode->update($this->codeData($request, $shiftCode));
        Inertia::flash('success', "Kode Shift {$shiftCode->kode} diperbarui.");

        return back();
    }

    public function destroyCode(ShiftCode $shiftCode): RedirectResponse
    {
        $used = $shiftCode->rosters()->count() + $shiftCode->absensis()->count();
        if ($used) {
            throw ValidationException::withMessages(['kode' => "Kode {$shiftCode->kode} masih dipakai di {$used} hari Roster/Absensi."]);
        }
        $patterns = RosterPattern::all()->filter(fn (RosterPattern $p) => $p->usesCode($shiftCode->id))->pluck('nama');
        if ($patterns->isNotEmpty()) {
            throw ValidationException::withMessages(['kode' => "Kode {$shiftCode->kode} masih dipakai Pola Roster ".$patterns->join(', ').'.']);
        }
        $shiftCode->delete();
        Inertia::flash('success', "Kode Shift {$shiftCode->kode} dihapus.");

        return back();
    }

    private function codeData(Request $request, ?ShiftCode $current = null): array
    {
        $data = $request->validate([
            'kode' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9_\/+-]+$/'],
            'dws' => ['nullable', 'string', 'max:30'],
            'keterangan' => ['nullable', 'string', 'max:150'],
            'jenis' => ['required', Rule::in(array_keys(ShiftCode::JENIS))],
            'jam_masuk' => ['nullable', 'date_format:H:i', 'required_with:jam_keluar'],
            'jam_keluar' => ['nullable', 'date_format:H:i', 'required_with:jam_masuk'],
            'istirahat_mulai' => ['nullable', 'date_format:H:i', 'required_with:istirahat_selesai'],
            'istirahat_selesai' => ['nullable', 'date_format:H:i', 'required_with:istirahat_mulai'],
        ], ['kode.regex' => 'Kode Shift hanya huruf, angka, dan - _ / + tanpa spasi.'], [
            'kode' => 'Kode Shift', 'dws' => 'DWS', 'jenis' => 'Jenis', 'jam_masuk' => 'Jam masuk', 'jam_keluar' => 'Jam keluar',
            'istirahat_mulai' => 'Istirahat mulai', 'istirahat_selesai' => 'Istirahat selesai',
        ]);
        foreach (['jam_masuk', 'jam_keluar', 'istirahat_mulai', 'istirahat_selesai'] as $f) {
            $data[$f] = filled($data[$f] ?? null) ? $data[$f] : null;
        }
        if ($data['jam_masuk'] && $data['jam_masuk'] === $data['jam_keluar']) {
            throw ValidationException::withMessages(['jam_keluar' => 'Jam keluar tidak boleh sama dengan jam masuk.']);
        }
        if ($data['istirahat_mulai'] && ! $data['jam_masuk']) {
            throw ValidationException::withMessages(['istirahat_mulai' => 'Isi jam masuk dan jam keluar dulu.']);
        }
        if ($data['istirahat_mulai']) {
            // The break must lie inside the shift (a night shift's break may be after midnight).
            $j = Jadwal::of(new ShiftCode($data), CarbonImmutable::today());
            if (! $j || $j->istirahatMulai->lt($j->masuk) || $j->istirahatSelesai->gt($j->keluar)) {
                throw ValidationException::withMessages(['istirahat_mulai' => 'Jam istirahat harus di dalam jam kerja shift.']);
            }
        }
        $data['kode'] = mb_strtoupper(trim($data['kode']));
        $data['dws'] = filled($data['dws'] ?? null) ? trim($data['dws']) : null;

        $taken = ShiftCode::whereRaw('UPPER(kode) = ?', [$data['kode']])->when($current, fn ($q) => $q->whereKeyNot($current->id))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['kode' => "Kode Shift {$data['kode']} sudah ada."]);
        }

        return $data;
    }

    // --- Waktu Kerja & Lembur ---------------------------------------------------------------

    public function updateWaktuKerja(Request $request, WorkLocation $workLocation): RedirectResponse
    {
        $data = $request->validate([
            'skema_kerja' => ['required', Rule::in(array_keys(WorkLocation::SKEMA_KERJA))],
            'jam_kerja_harian' => ['required', 'numeric', 'min:4', 'max:12'],
        ], [], ['skema_kerja' => 'Skema kerja', 'jam_kerja_harian' => 'Jam kerja normal']);
        $workLocation->update($data);
        Inertia::flash('success', "Waktu kerja {$workLocation->name} diperbarui.");

        return back();
    }

    public function updateLemburAturan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pembulatan_menit' => ['required', 'integer', Rule::in([1, 15, 30, 60])],
            'minimal_menit' => ['required', 'integer', 'min:0', 'max:240'],
            'hitung_sebelum_jadwal' => ['required', 'boolean'],
            'batas_harian_jam' => ['required', 'integer', 'min:1', 'max:12'],
            'batas_mingguan_jam' => ['required', 'integer', 'min:1', 'max:60'],
        ]);
        LemburAturan::current()->update($data);
        Inertia::flash('success', 'Aturan lembur disimpan. Proses ulang Kehadiran agar lembur yang belum disetujui ikut berubah.');

        return back();
    }

    /** Who may be paid overtime: per SubGroup, at Head Office and at the sites. */
    public function updateHakLembur(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.id' => ['required', 'integer', 'exists:sub_groups,id'],
            'rows.*.ho' => ['required', 'boolean'],
            'rows.*.site' => ['required', 'boolean'],
        ]);
        $now = now();
        $upsert = [];
        foreach ($data['rows'] as $r) {
            $upsert[] = ['sub_group_id' => $r['id'], 'tipe_lokasi' => WorkLocation::TYPE_HEAD_OFFICE, 'berhak' => $r['ho'], 'created_at' => $now, 'updated_at' => $now];
            $upsert[] = ['sub_group_id' => $r['id'], 'tipe_lokasi' => 'Site', 'berhak' => $r['site'], 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('sub_group_lembur')->upsert($upsert, ['sub_group_id', 'tipe_lokasi'], ['berhak', 'updated_at']);
        Inertia::flash('success', 'Hak lembur per SubGroup disimpan.');

        return back();
    }

    // --- Pola Roster -----------------------------------------------------------------------

    public function storePattern(Request $request): RedirectResponse
    {
        $pattern = RosterPattern::create($this->patternData($request));
        Inertia::flash('success', "Pola Roster {$pattern->nama} ditambahkan.");

        return back();
    }

    public function updatePattern(Request $request, RosterPattern $rosterPattern): RedirectResponse
    {
        $rosterPattern->update($this->patternData($request, $rosterPattern));
        Inertia::flash('success', "Pola Roster {$rosterPattern->nama} diperbarui.");

        return back();
    }

    public function destroyPattern(RosterPattern $rosterPattern): RedirectResponse
    {
        $rosterPattern->delete();
        Inertia::flash('success', "Pola Roster {$rosterPattern->nama} dihapus.");

        return back();
    }

    private function patternData(Request $request, ?RosterPattern $current = null): array
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:60', Rule::unique('roster_patterns', 'nama')->ignore($current?->id)],
            'langkah' => ['required', 'array', 'min:1', 'max:30'],
            'langkah.*.shift_code_id' => ['required', 'integer', 'exists:shift_codes,id'],
            'langkah.*.hari' => ['required', 'integer', 'min:1', 'max:60'],
        ], [], ['nama' => 'Nama pola', 'langkah' => 'Urutan', 'langkah.*.hari' => 'Jumlah hari', 'langkah.*.shift_code_id' => 'Kode Shift']);
        $data['nama'] = trim($data['nama']);
        $data['langkah'] = array_map(fn ($s) => ['shift_code_id' => (int) $s['shift_code_id'], 'hari' => (int) $s['hari']], $data['langkah']);

        return $data;
    }

    // --- Hari Libur ------------------------------------------------------------------------

    public function storeHoliday(Request $request): RedirectResponse
    {
        $holiday = Holiday::create($this->holidayData($request));
        Inertia::flash('success', "Hari libur {$holiday->nama} ditambahkan.");

        return back();
    }

    public function updateHoliday(Request $request, Holiday $holiday): RedirectResponse
    {
        $holiday->update($this->holidayData($request, $holiday));
        Inertia::flash('success', 'Hari libur diperbarui.');

        return back();
    }

    public function destroyHoliday(Holiday $holiday): RedirectResponse
    {
        $holiday->delete();
        Inertia::flash('success', "Hari libur {$holiday->nama} dihapus.");

        return back();
    }

    private function holidayData(Request $request, ?Holiday $current = null): array
    {
        $data = $request->validate([
            'tanggal' => ['required', 'date_format:Y-m-d'],
            'nama' => ['required', 'string', 'max:120'],
        ], [], ['tanggal' => 'Tanggal', 'nama' => 'Nama hari libur']);

        $taken = Holiday::whereDate('tanggal', $data['tanggal'])->when($current, fn ($q) => $q->whereKeyNot($current->id))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['tanggal' => 'Tanggal itu sudah tercatat sebagai hari libur.']);
        }

        return $data;
    }
}
