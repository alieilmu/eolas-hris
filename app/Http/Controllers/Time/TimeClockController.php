<?php

namespace App\Http\Controllers\Time;

use App\Http\Controllers\Controller;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\OvertimeRecord;
use App\Support\Audit\Audit;
use App\Support\Workflow\Workflow;
use App\Models\AttendanceDevice;
use App\Models\WorkLocation;
use App\Support\Access\AccessScope;
use App\Support\Time\Roll;
use App\Support\TimeClock\AttendanceFile;
use App\Support\TimeClock\AttendanceIngest;
use App\Support\TimeClock\DeviceRoute;
use App\Support\TimeClock\AttendanceProcessor;
use App\Support\TimeClock\AttendanceSummary;
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
class TimeClockController extends Controller
{
    public const TABS = ['resume', 'overtime', 'log', 'device'];

    /** The longest stretch a page reads at once. */
    private const MAKS_HARI = 62;

    public function index(Request $request): Response
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'resume';
        [$from, $until] = $this->range($request);
        $locations = AccessScope::locations();
        $location = $request->integer('location') ?: null;
        if ($location && ! $locations->contains('id', $location)) {
            $location = null;
        }
        $q = trim((string) $request->query('q'));
        $user = $request->user();

        $props = [
            'tab' => $tab,
            'filters' => ['from' => $from->toDateString(), 'until' => $until->toDateString(), 'location' => $location, 'q' => $q, 'status' => (string) $request->query('status', 'issues')],
            'locations' => $locations,
            'status_labels' => AttendanceSummary::STATUS,
            'can' => ['edit' => $user->canEdit('time-clock'), 'approve' => $user->canEdit('overtime-approval')],
            'new_devices' => AttendanceDevice::where('status', 'new')->count(),
        ];

        return Inertia::render('Time/TimeClock', $props + match ($tab) {
            'resume' => $this->resume($from, $until, $location, $q, $props['filters']['status']),
            'overtime' => $this->overtime($from, $until, $location, $q, (string) $request->query('status', 'pending')),
            'log' => $this->logs($from, $until, $location, $q),
            'device' => $this->device($request),
        });
    }

    /** Reprocesses the stretch into Absensi and Lembur. */
    public function process(Request $request): RedirectResponse
    {
        [$from, $until] = $this->range($request);
        $location = $request->integer('location') ?: null;
        $ids = $this->employees($from, $until, $location)->pluck('id')->all();
        $result = AttendanceProcessor::run($from, $until, $ids);
        Inertia::flash('success', "Kehadiran diproses: {$result['attendance']} hari Absensi diperbarui, {$result['overtime']} lembur dihitung.");

        return back();
    }

    /** A clock-in added by HR (a forgotten CI/CO), with its reason. */
    public function storeLog(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'punched_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'notes' => ['required', 'string', 'max:255'],
        ], [], ['punched_at' => 'Waktu', 'notes' => 'Alasan']);
        $k = Employee::findOrFail($data['employee_id']); // scoped: only the account's locations
        $result = AttendanceIngest::store([['pin' => $k->pin(), 'punched_at' => str_replace('T', ' ', $data['punched_at']).':00']], null, 'manual', $request->user()->id, $data['notes']);
        if (! $result['accepted']) {
            throw ValidationException::withMessages(['punched_at' => 'Log pada waktu ini sudah ada.']);
        }
        Inertia::flash('success', "Log absen {$k->name} ditambahkan.");

        return back();
    }

    /** Only HR's own corrections can be removed; a machine's log stays. */
    public function destroyLog(AttendanceLog $log): RedirectResponse
    {
        abort_unless($log->source === 'manual' && $log->employee_id && Employee::whereKey($log->employee_id)->exists(), 403);
        $date = $log->punched_at->startOfDay();
        $kid = $log->employee_id;
        $log->delete();
        AttendanceProcessor::run($date->subDay(), $date, [$kid]);
        Inertia::flash('success', 'Koreksi log absen dihapus.');

        return back();
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:txt,dat,csv,xlsx,xls'],
            'attendance_device_id' => ['nullable', 'integer', 'exists:attendance_devices,id'],
        ], [], ['file' => 'File']);
        $file = $request->file('file');
        $read = AttendanceFile::read($file->getRealPath(), $file->getClientOriginalExtension());
        if (! $read['rows']) {
            throw ValidationException::withMessages(['file' => 'Tidak ada baris yang terbaca. Kolom: PIN/NRP, lalu tanggal-jam (atau tanggal dan jam).']);
        }
        $device = $request->integer('attendance_device_id') ? AttendanceDevice::find($request->integer('attendance_device_id')) : null;
        $result = AttendanceIngest::store($read['rows'], $device, 'import', $request->user()->id);
        $wrong = $read['wrong'] ? ' '.count($read['wrong']).' baris tidak terbaca.' : '';
        Inertia::flash('success', "{$result['accepted']} log diimpor ({$result['duplicate']} sudah ada, {$result['without_employee']} PIN tidak dikenal).{$wrong}");

        return back();
    }

    // --- Mesin Absen -------------------------------------------------------------------------

    public function storeDevice(Request $request): RedirectResponse
    {
        $data = $this->deviceData($request);
        DB::transaction(function () use ($data) {
            DeviceRoute::claim($data['serial_number']);
            AttendanceDevice::create($data);
        });
        Inertia::flash('success', "Mesin {$data['name']} ditambahkan.");

        return back();
    }

    public function updateDevice(Request $request, AttendanceDevice $device): RedirectResponse
    {
        $data = $this->deviceData($request, $device);
        DB::transaction(function () use ($data, $device) {
            DeviceRoute::claim($data['serial_number'], $device->serial_number);
            $device->update($data);
        });
        Inertia::flash('success', "Mesin {$device->name} diperbarui.");

        return back();
    }

    /** Removes the machine; its logs stay (without a machine). */
    public function destroyDevice(AttendanceDevice $device): RedirectResponse
    {
        DeviceRoute::release($device->serial_number);
        $device->delete();
        Inertia::flash('success', "Mesin {$device->name} dikeluarkan.");

        return back();
    }

    // --- Lembur ----------------------------------------------------------------------------------

    public function verifyOvertime(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'decision' => ['required', Rule::in(['approved', 'rejected', 'pending'])],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $visible = Employee::pluck('id'); // scoped
        $records = OvertimeRecord::whereIn('id', $data['ids'])->whereIn('employee_id', $visible)->get();
        $flows = Workflow::pendingOf(OvertimeRecord::class, $records->pluck('id'));
        $user = $request->user();
        $label = ['approved' => 'disetujui', 'rejected' => 'ditolak', 'pending' => 'dikembalikan ke menunggu'][$data['decision']];

        // Overtime in a Workflow: its step, for whoever approves it now; overtime from before: straight away, as before.
        [[$n, $skipped]] = Workflow::quietly(function () use ($records, $flows, $data, $user) {
            $n = 0;
            $skipped = 0;
            $since = Workflow::since('overtime');
            foreach ($records as $o) {
                $flow = $flows->get($o->id);
                if ($data['decision'] === 'pending') {
                    if ($o->status === 'pending') {
                        continue;
                    }
                    $o->update(['status' => 'pending', 'verified_by' => null, 'verified_at' => null, 'verification_notes' => $data['notes'] ?? null]);
                    // Back to waiting: through its flow again from the first step.
                    if ($since && $o->created_at >= $since) {
                        Workflow::start($o, 'overtime', $o->employee, null, $o->workflowFacts(), $o->summary(), '/time/clock?tab=overtime&status=pending');
                    }
                    $n++;
                } elseif ($flow) {
                    if (! Workflow::canDecide($flow, $user)) {
                        $skipped++;

                        continue;
                    }
                    $data['decision'] === 'approved' ? Workflow::approve($flow, $user, $data['notes'] ?? null) : Workflow::reject($flow, $user, $data['notes'] ?? null);
                    $n++;
                } elseif ($o->status === 'pending') {
                    $o->update(['status' => $data['decision'], 'verified_by' => $user->id, 'verified_at' => now(), 'verification_notes' => $data['notes'] ?? null]);
                    $n++;
                }
            }

            return [$n, $skipped];
        });
        if ($flows->isNotEmpty() && $data['decision'] !== 'pending' && $n > 0) {
            Audit::log($data['decision'] === 'approved' ? 'workflow.approved' : 'workflow.rejected', null, "Lembur · {$n} {$label}".($data['notes'] ?? null ? " — {$data['notes']}" : ''));
        }
        Inertia::flash('success', "{$n} lembur {$label}.".($skipped ? " {$skipped} dilewati: menunggu langkah lain di alur persetujuannya." : ''));

        return back();
    }

    // --- pieces ------------------------------------------------------------------------------------

    private static function overtimeRow(OvertimeRecord $l): array
    {
        return [
            'id' => $l->id, 'date' => $l->date->toDateString(), 'employee_number' => $l->employee?->employee_number, 'name' => $l->employee?->name,
            'location' => $l->employee?->workLocation?->name, 'day_type' => $l->day_type,
            'start_date' => $l->start_date?->format('d/m H:i'), 'end_date' => $l->end_date?->format('d/m H:i'),
            'work_hours' => round($l->worked_minutes / 60, 2), 'paid_hours' => $l->paid_hours, 'weighted_hours' => $l->weighted_hours,
            'status' => $l->status, 'verification_notes' => $l->verification_notes, 'verifier' => $l->verifier?->name,
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(Request $request): array
    {
        $today = CarbonImmutable::today();
        $parse = fn (?string $v) => $v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? CarbonImmutable::parse($v) : null;
        $from = $parse($request->input('from')) ?? $today->startOfMonth();
        $until = $parse($request->input('until')) ?? $today;
        if ($until->lt($from)) {
            [$from, $until] = [$until, $from];
        }
        if ($from->diffInDays($until) >= self::MAKS_HARI) {
            $until = $from->addDays(self::MAKS_HARI - 1);
        }

        return [$from, $until];
    }

    /** Employees of the account's locations employed in the stretch. */
    private function employees(CarbonImmutable $from, CarbonImmutable $until, ?int $location, string $q = ''): Collection
    {
        return Roll::between($from, $until, $location)
            ->when($q !== '', fn ($c) => $c->filter(fn (Employee $k) => str_contains(mb_strtolower($k->name.' '.$k->employee_number), mb_strtolower($q))))
            ->values()
            ->load(['workLocation', 'jobLevel']);
    }

    private function resume(CarbonImmutable $from, CarbonImmutable $until, ?int $location, string $q, string $status): array
    {
        $rows = collect(AttendanceSummary::build($this->employees($from, $until, $location, $q), $from, $until));
        $count = $rows->countBy('status');
        $shown = match ($status) {
            'all' => $rows,
            'issues' => $rows->whereIn('status', AttendanceSummary::MASALAH),
            'late' => $rows->filter(fn ($r) => $r['late'] > 0),
            'early_leave' => $rows->filter(fn ($r) => $r['early_leave'] > 0),
            'overtime' => $rows->filter(fn ($r) => $r['is_overtime_eligible'] && ($r['overtime']['overtime_minutes'] ?? 0) > 0),
            default => $rows->where('status', $status),
        };
        $shift_window = $rows->filter(fn ($r) => $r['schedule_type'] === 'work');

        return [
            'summary' => [
                'employee' => $rows->pluck('employee_id')->unique()->count(),
                'scheduled_days' => $shift_window->count(),
                'complete' => $count['complete'] ?? 0,
                'missing_clock_in' => $count['missing_clock_in'] ?? 0,
                'missing_clock_out' => $count['missing_clock_out'] ?? 0,
                'no_show' => $count['no_show'] ?? 0,
                'mismatch' => $count['mismatch'] ?? 0,
                'late' => $rows->where('late', '>', 0)->count(),
                'early_leave' => $rows->where('early_leave', '>', 0)->count(),
                'overtime_hours' => round($rows->filter(fn ($r) => $r['is_overtime_eligible'])->sum(fn ($r) => $r['overtime']['paid_hours'] ?? 0), 1),
                'compliance' => $shift_window->count() ? round(($count['complete'] ?? 0) / $shift_window->count() * 100, 1) : null,
            ],
            'rows' => $shown->sortBy([['date', 'desc'], ['name', 'asc']])->take(2000)->values(),
            'total_rows' => $shown->count(),
            'employee_options' => $this->employees($from, $until, $location)->map(fn (Employee $k) => ['id' => $k->id, 'label' => "{$k->employee_number} · {$k->name}"])->values(),
        ];
    }

    private function overtime(CarbonImmutable $from, CarbonImmutable $until, ?int $location, string $q, string $status): array
    {
        $base = OvertimeRecord::query()->whereBetween('date', [$from->toDateString(), $until->toDateString()])
            ->whereHas('employee', fn ($k) => $k->when($location, fn ($k) => $k->where('work_location_id', $location))
                ->when($q !== '', fn ($k) => $k->where(fn ($k) => $k->where('name', 'like', "%{$q}%")->orWhere('employee_number', 'like', "%{$q}%"))));
        $sum = (clone $base)->selectRaw('status, count(*) n, sum(paid_hours) hours, sum(weighted_hours) weighted')->groupBy('status')->get()->keyBy('status');

        return [
            'overtime_summary' => collect(OvertimeRecord::STATUS)->map(fn ($label, $key) => [
                'key' => $key, 'label' => $label, 'n' => (int) ($sum[$key]->n ?? 0),
                'hours' => round((float) ($sum[$key]->hours ?? 0), 1), 'weighted' => round((float) ($sum[$key]->weighted ?? 0), 1),
            ])->values(),
            'overtime_records' => (clone $base)->when(in_array($status, array_keys(OvertimeRecord::STATUS), true), fn ($q) => $q->where('status', $status))
                ->with(['employee:id,employee_number,name,work_location_id', 'employee.workLocation:id,name,work_schedule_scheme', 'verifier:id,name'])
                ->orderByDesc('date')->limit(1500)->get()
                ->pipe(function (Collection $rows) {
                    // Overtime in a Workflow: the step it waits at, and whether this account decides it.
                    $flows = Workflow::pendingOf(OvertimeRecord::class, $rows->pluck('id'));

                    return $rows->map(fn (OvertimeRecord $l) => [...self::overtimeRow($l), 'workflow' => Workflow::glance($flows->get($l->id), auth()->user())]);
                }),
            'overtime_status' => $status,
        ];
    }

    private function logs(CarbonImmutable $from, CarbonImmutable $until, ?int $location, string $q): array
    {
        $visible = $this->employees($from, $until, $location, $q)->pluck('id');

        return [
            'logs' => AttendanceLog::with(['employee:id,employee_number,name', 'device:id,name'])
                ->whereBetween('punched_at', [$from->toDateString().' 00:00:00', $until->toDateString().' 23:59:59'])
                // Logs of an unknown PIN only for an account that sees every location, unfiltered.
                ->where(fn ($w) => $w->whereIn('employee_id', $visible)
                    ->when($location === null && $q === '' && AccessScope::workLocationIds() === null, fn ($w) => $w->orWhereNull('employee_id')))
                ->orderByDesc('punched_at')->limit(1500)->get()
                ->map(fn (AttendanceLog $l) => [
                    'id' => $l->id, 'punched_at' => $l->punched_at->format('Y-m-d H:i:s'), 'pin' => $l->pin,
                    'employee_number' => $l->employee?->employee_number, 'name' => $l->employee?->name, 'device' => $l->device?->name,
                    'source' => $l->source, 'notes' => $l->notes,
                ]),
            'employee_options' => $this->employees($from, $until, $location)->map(fn (Employee $k) => ['id' => $k->id, 'label' => "{$k->employee_number} · {$k->name}"])->values(),
            'device_options' => AttendanceDevice::where('status', '!=', 'new')->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function device(Request $request): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $counts = AttendanceLog::whereDate('punched_at', $today)->whereNotNull('attendance_device_id')->selectRaw('attendance_device_id, count(*) n')->groupBy('attendance_device_id')->pluck('n', 'attendance_device_id');
        $last = AttendanceLog::whereNotNull('attendance_device_id')->selectRaw('attendance_device_id, max(punched_at) w')->groupBy('attendance_device_id')->pluck('w', 'attendance_device_id');

        return [
            'devices' => AttendanceDevice::with('workLocation:id,name')->orderByRaw("status = 'baru' desc")->orderBy('name')->get()->map(fn (AttendanceDevice $m) => [
                'id' => $m->id, 'name' => $m->name, 'serial_number' => $m->serial_number, 'brand' => $m->brand, 'model' => $m->model,
                'work_location_id' => $m->work_location_id, 'location' => $m->workLocation?->name, 'status' => $m->status,
                'online' => $m->online(), 'last_seen_at' => $m->last_seen_at?->toDateTimeString(), 'last_ip' => $m->last_ip,
                'logs_today' => (int) ($counts[$m->id] ?? 0), 'last_log' => $last[$m->id] ?? null, 'notes' => $m->notes,
            ]),
            'work_locations' => WorkLocation::orderBy('sort_order')->get(['id', 'name']),
            'server' => ['host' => $request->getHost(), 'path' => '/iclock/cdata'],
        ];
    }

    private function deviceData(Request $request, ?AttendanceDevice $current = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'serial_number' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('attendance_devices', 'serial_number')->ignore($current?->id)],
            'brand' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:60'],
            'work_location_id' => ['nullable', 'integer', 'exists:work_locations,id'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string', 'max:255'],
        ], ['serial_number.regex' => 'Serial number hanya huruf, angka, titik, - dan _.', 'serial_number.unique' => 'Serial number ini sudah terdaftar.'], ['serial_number' => 'Serial number']);
        $data['serial_number'] = trim($data['serial_number']);
        if ($data['work_location_id'] ?? null) {
            AccessScope::assertWorkLocation((int) $data['work_location_id']);
        }

        return $data;
    }
}
