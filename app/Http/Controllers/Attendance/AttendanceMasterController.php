<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\MasterOption;
use App\Models\RosterPattern;
use App\Models\ShiftCode;
use App\Support\Karyawan\MasterOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Master Data Attendance: Kode Shift, Pola Roster, Status Pengajuan Uang and Hari Libur. */
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
        ], ['kode.regex' => 'Kode Shift hanya huruf, angka, dan - _ / + tanpa spasi.'], ['kode' => 'Kode Shift', 'dws' => 'DWS', 'jenis' => 'Jenis']);
        $data['kode'] = mb_strtoupper(trim($data['kode']));
        $data['dws'] = filled($data['dws'] ?? null) ? trim($data['dws']) : null;

        $taken = ShiftCode::whereRaw('UPPER(kode) = ?', [$data['kode']])->when($current, fn ($q) => $q->whereKeyNot($current->id))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['kode' => "Kode Shift {$data['kode']} sudah ada."]);
        }

        return $data;
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
