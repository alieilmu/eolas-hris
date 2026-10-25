<?php

namespace App\Http\Controllers\Time;

use App\Http\Controllers\Controller;
use App\Models\PublicHoliday;
use App\Models\OvertimePolicy;
use App\Models\LookupValue;
use App\Models\ShiftPattern;
use App\Models\ShiftCode;
use App\Models\JobLevel;
use App\Models\WorkLocation;
use App\Support\TimeClock\ShiftWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use App\Support\Employees\LookupValues;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Pengaturan Waktu & Kehadiran: Kode Shift (with their hours), Pola Roster, Status Pengajuan Uang, Hari Libur, and Waktu Kerja & Lembur. */
class TimeSettingsController extends Controller
{
    public function index(): Response
    {
        $patterns = ShiftPattern::orderBy('name')->get();

        return Inertia::render('Time/Settings', [
            'codes' => ShiftCode::withCount(['shift_schedules', 'attendance_records'])->orderBy('sort_order')->orderBy('id')->get()->map(fn (ShiftCode $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'daily_work_schedule' => $c->daily_work_schedule,
                'description' => $c->description,
                'type' => $c->type,
                'deducts_base_salary' => $c->deducts_base_salary,
                'start_time' => ShiftCode::hhmm($c->start_time),
                'end_time' => ShiftCode::hhmm($c->end_time),
                'break_start' => ShiftCode::hhmm($c->break_start),
                'break_end' => ShiftCode::hhmm($c->break_end),
                'work_hours' => ($j = ShiftWindow::of($c, CarbonImmutable::today())) ? round($j->workedMinutes() / 60, 2) : null,
                'usage' => $c->shift_schedules_count + $c->attendance_records_count,
                'patterns' => $patterns->filter(fn (ShiftPattern $p) => $p->usesCode($c->id))->pluck('name')->values(),
            ]),
            'type_options' => ShiftCode::JENIS,
            'patterns' => $patterns->map(fn (ShiftPattern $p) => ['id' => $p->id, 'name' => $p->name, 'steps' => $p->steps]),
            'cash_advance_status' => [
                'category' => LeaveTravelController::STATUS_UANG,
                'label' => LookupValues::CATEGORIES[LeaveTravelController::STATUS_UANG]['label'],
                'values' => LookupValue::where('category', LeaveTravelController::STATUS_UANG)->orderBy('sort_order')->orderBy('id')->get()
                    ->map(fn (LookupValue $o) => [
                        'id' => $o->id,
                        'value' => $o->value,
                        'is_system' => $o->is_system,
                        'usage' => LookupValues::usage(LeaveTravelController::STATUS_UANG, $o->value),
                    ]),
            ],
            // Waktu Kerja & Lembur.
            'work_schedule' => WorkLocation::orderBy('sort_order')->get(['id', 'name', 'type', 'work_schedule_scheme', 'daily_normal_hours']),
            'scheme_options' => WorkLocation::SKEMA_KERJA,
            'overtime_policy' => OvertimePolicy::current()->only(['rounding_minutes', 'minimum_minutes', 'count_before_shift', 'daily_limit_hours', 'weekly_limit_hours']),
            'overtime_eligibility' => JobLevel::orderByDesc('layer')->orderBy('name')->get(['id', 'name', 'layer'])->map(function (JobLevel $g) {
                $set = DB::table('overtime_eligibilities')->where('job_level_id', $g->id)->pluck('is_eligible', 'location_type');

                return ['id' => $g->id, 'name' => $g->name, 'layer' => $g->layer,
                    'ho' => (bool) ($set[WorkLocation::TYPE_HEAD_OFFICE] ?? true), 'site' => (bool) ($set[WorkLocation::TYPE_SITE] ?? true)];
            }),
            'public_holidays' => PublicHoliday::orderByDesc('date')->get()->map(fn (PublicHoliday $h) => [
                'id' => $h->id,
                'date' => $h->date->toDateString(),
                'name' => $h->name,
            ]),
        ]);
    }

    // --- Kode Shift ------------------------------------------------------------------------

    public function storeCode(Request $request): RedirectResponse
    {
        $code = ShiftCode::create([...$this->codeData($request), 'sort_order' => (ShiftCode::max('sort_order') ?? -1) + 1]);
        Inertia::flash('success', "Kode Shift {$code->code} ditambahkan.");

        return back();
    }

    public function updateCode(Request $request, ShiftCode $shiftCode): RedirectResponse
    {
        $shiftCode->update($this->codeData($request, $shiftCode));
        Inertia::flash('success', "Kode Shift {$shiftCode->code} diperbarui.");

        return back();
    }

    public function destroyCode(ShiftCode $shiftCode): RedirectResponse
    {
        $used = $shiftCode->shift_schedules()->count() + $shiftCode->attendance_records()->count();
        if ($used) {
            throw ValidationException::withMessages(['code' => "Kode {$shiftCode->code} masih dipakai di {$used} hari Roster/Absensi."]);
        }
        $patterns = ShiftPattern::all()->filter(fn (ShiftPattern $p) => $p->usesCode($shiftCode->id))->pluck('name');
        if ($patterns->isNotEmpty()) {
            throw ValidationException::withMessages(['code' => "Kode {$shiftCode->code} masih dipakai Pola Roster ".$patterns->join(', ').'.']);
        }
        $shiftCode->delete();
        Inertia::flash('success', "Kode Shift {$shiftCode->code} dihapus.");

        return back();
    }

    private function codeData(Request $request, ?ShiftCode $current = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9_\/+-]+$/'],
            'daily_work_schedule' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:150'],
            'type' => ['required', Rule::in(array_keys(ShiftCode::JENIS))],
            'deducts_base_salary' => ['nullable', 'boolean'],
            'start_time' => ['nullable', 'date_format:H:i', 'required_with:end_time'],
            'end_time' => ['nullable', 'date_format:H:i', 'required_with:start_time'],
            'break_start' => ['nullable', 'date_format:H:i', 'required_with:break_end'],
            'break_end' => ['nullable', 'date_format:H:i', 'required_with:break_start'],
        ], ['code.regex' => 'Kode Shift hanya huruf, angka, dan - _ / + tanpa spasi.'], [
            'code' => 'Kode Shift', 'daily_work_schedule' => 'DWS', 'type' => 'Jenis', 'deducts_base_salary' => 'Potong Gapok', 'start_time' => 'Jam masuk', 'end_time' => 'Jam keluar',
            'break_start' => 'Istirahat mulai', 'break_end' => 'Istirahat selesai',
        ]);
        foreach (['start_time', 'end_time', 'break_start', 'break_end'] as $f) {
            $data[$f] = filled($data[$f] ?? null) ? $data[$f] : null;
        }
        // Only a day off work can cut the gaji pokok.
        $data['deducts_base_salary'] = (bool) ($data['deducts_base_salary'] ?? false) && in_array($data['type'], ['absent', 'permission'], true);
        if ($data['start_time'] && $data['start_time'] === $data['end_time']) {
            throw ValidationException::withMessages(['end_time' => 'Jam keluar tidak boleh sama dengan jam masuk.']);
        }
        if ($data['break_start'] && ! $data['start_time']) {
            throw ValidationException::withMessages(['break_start' => 'Isi jam masuk dan jam keluar dulu.']);
        }
        if ($data['break_start']) {
            // The break must lie inside the shift (a night shift's break may be after midnight).
            $j = ShiftWindow::of(new ShiftCode($data), CarbonImmutable::today());
            if (! $j || $j->breakStart->lt($j->start) || $j->breakEnd->gt($j->end)) {
                throw ValidationException::withMessages(['break_start' => 'Jam istirahat harus di dalam jam kerja shift.']);
            }
        }
        $data['code'] = mb_strtoupper(trim($data['code']));
        $data['daily_work_schedule'] = filled($data['daily_work_schedule'] ?? null) ? trim($data['daily_work_schedule']) : null;

        $taken = ShiftCode::whereRaw('UPPER(code) = ?', [$data['code']])->when($current, fn ($q) => $q->whereKeyNot($current->id))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['code' => "Kode Shift {$data['code']} sudah ada."]);
        }

        return $data;
    }

    // --- Waktu Kerja & Lembur ---------------------------------------------------------------

    public function updateWorkSchedule(Request $request, WorkLocation $workLocation): RedirectResponse
    {
        $data = $request->validate([
            'work_schedule_scheme' => ['required', Rule::in(array_keys(WorkLocation::SKEMA_KERJA))],
            'daily_normal_hours' => ['required', 'numeric', 'min:4', 'max:12'],
        ], [], ['work_schedule_scheme' => 'Skema kerja', 'daily_normal_hours' => 'Jam kerja normal']);
        $workLocation->update($data);
        Inertia::flash('success', "Waktu kerja {$workLocation->name} diperbarui.");

        return back();
    }

    public function updateOvertimePolicy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rounding_minutes' => ['required', 'integer', Rule::in([1, 15, 30, 60])],
            'minimum_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'count_before_shift' => ['required', 'boolean'],
            'daily_limit_hours' => ['required', 'integer', 'min:1', 'max:12'],
            'weekly_limit_hours' => ['required', 'integer', 'min:1', 'max:60'],
        ]);
        OvertimePolicy::current()->update($data);
        Inertia::flash('success', 'Aturan lembur disimpan. Proses ulang Kehadiran agar lembur yang belum disetujui ikut berubah.');

        return back();
    }

    /** Who may be paid overtime: per SubGroup, at Head Office and at the sites. */
    public function updateOvertimeEligibility(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.id' => ['required', 'integer', 'exists:job_levels,id'],
            'rows.*.ho' => ['required', 'boolean'],
            'rows.*.site' => ['required', 'boolean'],
        ]);
        $now = now();
        $upsert = [];
        foreach ($data['rows'] as $r) {
            $upsert[] = ['job_level_id' => $r['id'], 'location_type' => WorkLocation::TYPE_HEAD_OFFICE, 'is_eligible' => $r['ho'], 'created_at' => $now, 'updated_at' => $now];
            $upsert[] = ['job_level_id' => $r['id'], 'location_type' => WorkLocation::TYPE_SITE, 'is_eligible' => $r['site'], 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('overtime_eligibilities')->upsert($upsert, ['job_level_id', 'location_type'], ['is_eligible', 'updated_at']);
        Inertia::flash('success', 'Hak lembur per SubGroup disimpan.');

        return back();
    }

    // --- Pola Roster -----------------------------------------------------------------------

    public function storePattern(Request $request): RedirectResponse
    {
        $pattern = ShiftPattern::create($this->patternData($request));
        Inertia::flash('success', "Pola Roster {$pattern->name} ditambahkan.");

        return back();
    }

    public function updatePattern(Request $request, ShiftPattern $rosterPattern): RedirectResponse
    {
        $rosterPattern->update($this->patternData($request, $rosterPattern));
        Inertia::flash('success', "Pola Roster {$rosterPattern->name} diperbarui.");

        return back();
    }

    public function destroyPattern(ShiftPattern $rosterPattern): RedirectResponse
    {
        $rosterPattern->delete();
        Inertia::flash('success', "Pola Roster {$rosterPattern->name} dihapus.");

        return back();
    }

    private function patternData(Request $request, ?ShiftPattern $current = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('shift_patterns', 'name')->ignore($current?->id)],
            'steps' => ['required', 'array', 'min:1', 'max:30'],
            'steps.*.shift_code_id' => ['required', 'integer', 'exists:shift_codes,id'],
            'steps.*.days' => ['required', 'integer', 'min:1', 'max:60'],
        ], [], ['name' => 'Nama pola', 'steps' => 'Urutan', 'steps.*.days' => 'Jumlah hari', 'steps.*.shift_code_id' => 'Kode Shift']);
        $data['name'] = trim($data['name']);
        $data['steps'] = array_map(fn ($s) => ['shift_code_id' => (int) $s['shift_code_id'], 'days' => (int) $s['days']], $data['steps']);

        return $data;
    }

    // --- Hari Libur ------------------------------------------------------------------------

    public function storeHoliday(Request $request): RedirectResponse
    {
        $holiday = PublicHoliday::create($this->holidayData($request));
        Inertia::flash('success', "Hari libur {$holiday->name} ditambahkan.");

        return back();
    }

    public function updateHoliday(Request $request, PublicHoliday $holiday): RedirectResponse
    {
        $holiday->update($this->holidayData($request, $holiday));
        Inertia::flash('success', 'Hari libur diperbarui.');

        return back();
    }

    public function destroyHoliday(PublicHoliday $holiday): RedirectResponse
    {
        $holiday->delete();
        Inertia::flash('success', "Hari libur {$holiday->name} dihapus.");

        return back();
    }

    private function holidayData(Request $request, ?PublicHoliday $current = null): array
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:120'],
        ], [], ['date' => 'Tanggal', 'name' => 'Nama hari libur']);

        $taken = PublicHoliday::whereDate('date', $data['date'])->when($current, fn ($q) => $q->whereKeyNot($current->id))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['date' => 'Tanggal itu sudah tercatat sebagai hari libur.']);
        }

        return $data;
    }
}
