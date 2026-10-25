<?php

namespace App\Http\Controllers\Time;

use App\Http\Controllers\Controller;
use App\Models\LeavePolicy;
use App\Models\LongServiceLeave;
use App\Models\LeaveTransaction;
use App\Models\Employee;
use App\Models\LeaveTravelRequest;
use App\Models\WorkLocation;
use App\Support\Access\AccessScope;
use App\Support\Time\Roll;
use App\Support\Leave\LeaveLedger;
use App\Support\Leave\LeaveExcel;
use App\Support\Excel\TableExcel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Comben → Cuti: the annual leave balance of every employee (as the old "SISA CUTI
 * TAHUNAN" monitoring: each leave as Hari | Tgl), the Cuti Besar entitlements and
 * choices, leave typed by HR, and the export in the same layout.
 */
class LeaveBalanceController extends Controller
{
    private const BESAR_STATUS = [
        'not_chosen' => 'Belum dipilih',
        'pending' => 'Menunggu HR',
        'taken' => 'Diambil',
        'cashed_out' => 'Diuangkan',
        'auto_cashed_out' => 'Lewat batas → diuangkan',
    ];

    private const SUMBER = ['import' => 'Import', 'manual' => 'Dicatat HR', 'leave_travel' => 'Cuti & Dinas'];

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('Time/LeaveBalances', [
            'tab' => $filters['tab'],
            'filters' => array_diff_key($filters, ['tab' => 1]),
            'rows' => $this->rows($filters),
            'locations' => AccessScope::locations(),
            'sub_locations' => AccessScope::subLocations(),
            'policy' => LeavePolicy::current()->only(['annual_entitlement_days', 'annual_validity_years', 'long_service_interval_years', 'long_service_days', 'long_service_taken_salary_multiplier', 'long_service_cashout_salary_multiplier', 'long_service_decision_months']),
            'long_service_pending' => LongServiceLeave::where('status', 'pending')->count(),
            'can_edit' => $request->user()->canEdit('leave-balances'),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    /** One employee: the balance per year and every leave taken. */
    public function show(Employee $employee): JsonResponse
    {
        $employee->load(['profile', 'position:id,name', 'workLocation:id,name', 'subLocation:id,name']);
        $data = LeaveLedger::load([$employee->id]);
        $summary = LeaveLedger::summary($employee, data: $data);

        return response()->json([
            'employee' => ['id' => $employee->id, 'employee_number' => $employee->employee_number, 'name' => $employee->name, 'position' => $employee->position?->name],
            'annual' => array_diff_key($summary['annual'], ['allocation' => 1]),
            'long_service' => $summary['long_service'],
            'transaction' => self::items($employee, $data, true, $summary['annual']['allocation'] ?? []),
            'field' => ($l = LeaveLedger::field($employee)) ? ['leave' => $l->leave_days, 'departure' => $l->outbound_travel_days, 'back' => $l->return_travel_days, 'total' => $l->total()] : null,
        ]);
    }

    /** Leave taken outside Cuti & Dinas (before the app, or a correction). */
    public function storeTransaction(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['annual'])],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'days' => ['required', 'integer', 'min:1', 'max:366'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['start_date' => 'Tanggal awal', 'end_date' => 'Tanggal akhir', 'days' => 'Jumlah hari']);
        $employee->leaveTransactions()->create([
            ...$data,
            'year' => (int) substr($data['start_date'], 0, 4),
            'source' => 'manual',
            'created_by' => $request->user()->id,
        ]);
        Inertia::flash('success', "Cuti {$data['days']} hari {$employee->name} dicatat.");

        return back();
    }

    public function destroyTransaction(LeaveTransaction $transaction): RedirectResponse
    {
        $transaction->delete();
        Inertia::flash('success', "Catatan cuti {$transaction->days} hari dihapus; saldonya kembali.");

        return back();
    }

    /** HR records the employee's choice for a Cuti Besar entitlement (or clears it). */
    public function decideLongService(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate([
            'sequence_no' => ['required', 'integer', 'min:1', 'max:20'],
            'decision' => ['nullable', Rule::in(array_keys(LongServiceLeave::KEPUTUSAN))],
        ]);
        $this->assertReached($employee, $data['sequence_no']);
        $current = LongServiceLeave::where('employee_id', $employee->id)->where('sequence_no', $data['sequence_no'])->first();
        if (! $data['decision']) {
            if ($current && LeaveTravelRequest::where('employee_id', $employee->id)->where('long_service_period', $data['sequence_no'])->where('status', '!=', 'rejected')->exists()) {
                throw ValidationException::withMessages(['decision' => 'Cuti Besar ini sudah dipakai di Cuti & Dinas; hapus cutinya dulu.']);
            }
            $current?->delete();
            Inertia::flash('success', "Pilihan Cuti Besar {$employee->name} dikosongkan.");

            return back();
        }
        LongServiceLeave::updateOrCreate(
            ['employee_id' => $employee->id, 'sequence_no' => $data['sequence_no']],
            ['decision' => $data['decision'], 'status' => 'approved', 'source' => $current?->source ?? 'hr', 'verified_by' => $request->user()->id, 'verified_at' => now()],
        );
        Inertia::flash('success', "Cuti Besar {$employee->name}: ".LongServiceLeave::KEPUTUSAN[$data['decision']].'.');

        return back();
    }

    /** A choice sent from the portal: confirmed, or rejected (the entitlement is open again). */
    public function verifyLongService(Request $request, LongServiceLeave $longServiceLeave): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'notes' => ['nullable', 'string', 'max:255', 'required_if:decision,reject']], ['notes.required_if' => 'Tulis alasan penolakan.']);
        if ($longServiceLeave->status !== 'pending') {
            throw ValidationException::withMessages(['decision' => 'Pilihan ini sudah diverifikasi.']);
        }
        $name = $longServiceLeave->employee->name;
        if ($data['decision'] === 'reject') {
            $longServiceLeave->delete();
            Inertia::flash('success', "Pilihan Cuti Besar {$name} ditolak; ia bisa memilih lagi.");
        } else {
            $longServiceLeave->update(['status' => 'approved', 'verified_by' => $request->user()->id, 'verified_at' => now(), 'notes' => $data['notes'] ?? null]);
            Inertia::flash('success', "Cuti Besar {$name} disetujui: ".LongServiceLeave::KEPUTUSAN[$longServiceLeave->decision].'.');
        }

        return back();
    }

    /**
     * Excel: the "SISA CUTI TAHUNAN" sheet in the old layout, the Cuti Besar entitlements
     * (one row per period; the status filter of the Cuti Besar tab applies) and every leave
     * with the quota it was taken from.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $today = CarbonImmutable::today();
        $rows = $this->rows([...$filters, 'tab' => 'annual'], true);
        $book = LeaveExcel::build($rows, $today);
        $policy = LeavePolicy::current();
        $scope = [
            $filters['location'] ? 'Lokasi '.WorkLocation::whereKey($filters['location'])->value('name') : null,
            $filters['q'] !== '' ? 'Cari "'.$filters['q'].'"' : null,
            count($rows).' karyawan aktif',
        ];

        $long_service = [];
        foreach ($rows as $r) {
            $identity = [$r['employee_number'], $r['name'], $r['department'], $r['work_location'], $r['sub_location'], $r['hired'], $r['years_of_service']];
            $period = collect($r['long_service']['period'])->when($filters['status'], fn ($c, $st) => $c->where('status', $st));
            foreach ($period as $p) {
                $long_service[] = [
                    count($long_service) + 1, ...$identity,
                    $p['sequence_no'], $p['years_of_service'], $p['entitlement_date'], $p['limit'],
                    self::BESAR_STATUS[$p['status']] ?? $p['status'],
                    $p['status'] === 'taken' ? $p['days'] : null,
                    $p['status'] === 'taken' ? $p['used'] : null,
                    $p['pending'] ?: null,
                    $p['status'] === 'taken' ? $p['remaining'] : null,
                    $p['base_pay'] ? "{$p['base_pay']}x gapok" : null,
                    $r['long_service']['next'],
                ];
            }
            // Not reached yet: one row with when it will be (left out when filtering by status).
            if (! $r['long_service']['period'] && ! $filters['status']) {
                $long_service[] = [count($long_service) + 1, ...$identity, null, null, null, null, $r['hired'] ? 'Belum tercapai' : 'Tgl masuk kosong', null, null, null, null, null, $r['long_service']['next']];
            }
        }
        TableExcel::addSheet($book, [
            'title' => 'LONG SERVICE LEAVE',
            'info' => TableExcel::info([
                "Tiap {$policy->long_service_interval_years} thn: diambil {$policy->long_service_days} hari + {$policy->long_service_taken_salary_multiplier}x gapok, atau diuangkan {$policy->long_service_cashout_salary_multiplier}x gapok; batas pilih {$policy->long_service_decision_months} bulan",
                $filters['status'] ? 'Status '.(self::BESAR_STATUS[$filters['status']] ?? $filters['status']) : null,
                ...$scope,
            ]),
            'freeze' => 3,
            'columns' => [
                ['No', 5, 'int'], ['Employee Number', 12], ['Name', 28], ['Department', 22], ['Location', 16], ['Sub Location', 16], ['Hire Date', 12, 'date'], ['Years of Service', 16],
                ['Period No.', 8, 'int'], ['Service (years)', 9, 'int'], ['Entitlement Date', 12, 'date'], ['Choice Deadline', 12, 'date'], ['Status', 22],
                ['Entitled Days', 8, 'int'], ['Used', 8, 'int'], ['Pending', 8, 'int'], ['Remaining Days', 8, 'int'], ['Basic Salary', 10], ['Next Entitlement', 13, 'date'],
            ],
            'rows' => $long_service,
        ]);

        $breakdown = [];
        foreach ($rows as $r) {
            foreach ($r['transaction'] as $item) {
                $breakdown[] = [
                    count($breakdown) + 1, $r['employee_number'], $r['name'], $r['department'],
                    LeaveLedger::KATEGORI[$item['type']] ?? ucfirst($item['type']),
                    self::SUMBER[$item['source']] ?? $item['source'],
                    $item['year'], $item['start_date'], $item['end_date'], $item['start_date'] ? null : $item['description'],
                    $item['days'], $item['deduct'] ?: null, ($item['long_service_leave_deducted'] ?? 0) ?: null,
                    self::deductedFrom($item),
                    ucfirst($item['status']), $item['notes'],
                ];
            }
        }
        TableExcel::addSheet($book, [
            'title' => 'LEAVE TAKEN',
            'info' => TableExcel::info(['Sisa per '.$today->format('d.m.Y'), ...$scope]),
            'freeze' => 3,
            'columns' => [
                ['No', 5, 'int'], ['Employee Number', 12], ['Name', 28], ['Department', 22], ['Leave Type', 20], ['Source', 12], ['Year', 7, 'int'],
                ['Start Date', 12, 'date'], ['End Date', 12, 'date'], ['Date (legacy text)', 22], ['Days', 6, 'int', 'sum' => true],
                ['Annual Deducted', 9, 'int', 'sum' => true], ['Long Service Deducted', 9, 'int', 'sum' => true], ['Deducted From', 34], ['Status', 11], ['Notes', 30],
            ],
            'rows' => $breakdown,
        ]);
        $book->setActiveSheetIndex(0);

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), 'leave-annual-long_service-'.$today->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** "Cuti 2024 · 2 hr, Hutang → cuti 2026 · 1 hr", as in the detail popup. */
    private static function deductedFrom(array $item): ?string
    {
        if ($item['status'] === 'pending') {
            return 'menunggu persetujuan';
        }
        if (! $item['deduct']) {
            return 'tidak memotong';
        }

        return implode(', ', array_map(fn (array $p) => ($p['debt'] ? ($p['entitlement'] ? "Hutang → cuti {$p['entitlement']}" : 'Hutang') : "Cuti {$p['entitlement']}")." · {$p['days']} hr", $item['deducted_from'])) ?: null;
    }

    /** For the cuti form in Cuti & Dinas: balance, field-leave allowance, Cuti Besar. */
    public function info(Request $request, Employee $employee): JsonResponse
    {
        return response()->json(LeaveLedger::info($employee, $request->integer('except') ?: null));
    }

    // -------------------------------------------------------------------------------------

    private function filters(Request $request): array
    {
        $f = $request->validate([
            'tab' => ['nullable', Rule::in(['annual', 'long_service'])],
            'location' => ['nullable', 'integer'],
            'sub' => ['nullable', 'string', 'max:10'],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:30'],
        ]);

        return ['tab' => $f['tab'] ?? 'annual', 'location' => $f['location'] ?? null, 'sub' => $f['sub'] ?? '', 'q' => $f['q'] ?? '', 'status' => $f['status'] ?? ''];
    }

    /** Employees with their balance; $withItems adds every leave (the export lists them as Hari | Tgl). */
    private function rows(array $f, bool $withItems = false): array
    {
        $sub = Roll::subLocationFilter($f['sub'] ?: null);
        $q = trim($f['q']);
        $employees = Employee::with(['profile', 'position:id,name', 'department:id,name', 'section.department', 'workLocation:id,name', 'subLocation:id,name'])
            ->where('status', Employee::STATUS_ACTIVE)
            ->when($f['location'], fn ($w, $l) => $w->where('work_location_id', $l))
            ->when($sub !== null, fn ($w) => $sub === Roll::NO_SUB_LOCATION ? $w->whereNull('sub_location_id') : $w->where('sub_location_id', $sub))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('employee_number', 'like', "%{$q}%")))
            ->orderBy('name')
            ->get();
        $data = LeaveLedger::load($employees->pluck('id'));
        $today = CarbonImmutable::today();

        $rows = $employees->map(function (Employee $k) use ($data, $today, $withItems) {
            $s = LeaveLedger::summary($k, $today, $data);
            $items = self::items($k, $data, false, $s['annual']['allocation'] ?? []);

            return [
                'id' => $k->id,
                'employee_number' => $k->employee_number,
                'name' => $k->name,
                'position' => $k->position?->name,
                'department' => $k->placementDepartment()?->name,
                'work_location' => $k->workLocation?->name,
                'sub_location' => $k->subLocation?->name,
                'hired' => $k->profile?->hire_date?->toDateString(),
                'years_of_service' => $k->profile?->years_of_service,
                'annual' => array_diff_key($s['annual'], ['year' => 1, 'allocation' => 1]),
                'long_service' => $s['long_service'],
                'transaction_count' => count($items),
                'transaction' => $withItems ? $items : [],
            ];
        });

        if ($f['tab'] === 'long_service' && $f['status']) {
            $rows = $rows->filter(fn ($r) => collect($r['long_service']['period'])->contains('status', $f['status']));
        }

        return $rows->values()->all();
    }

    /**
     * Every leave of the employee (imported, typed, and from Cuti & Dinas), oldest first,
     * with the quota each one was taken from ($alokasi from the ledger).
     */
    public static function items(Employee $k, array $data, bool $withIds = false, array $allocation = []): array
    {
        $items = collect();
        foreach ($data['transaction'][$k->id] ?? [] as $p) {
            $items->push([
                'id' => $withIds ? $p->id : null,
                'source' => $p->source,
                'type' => $p->type,
                'year' => $p->year,
                'start_date' => $p->start_date?->toDateString(),
                'end_date' => $p->end_date?->toDateString(),
                'description' => $p->date_notes,
                'days' => $p->days,
                'deduct' => $p->days,
                'status' => 'approved',
                'notes' => $p->notes,
                'deducted_from' => $allocation["p{$p->id}"] ?? [],
            ]);
        }
        foreach ($data['leave_travel_requests'][$k->id] ?? [] as $t) {
            $items->push([
                'id' => null,
                'leave_travel_request_id' => $t->id,
                'source' => 'leave_travel',
                'type' => $t->leave_category ?? 'other',
                'year' => $t->start_date->year,
                'start_date' => $t->start_date->toDateString(),
                'end_date' => $t->end_date->toDateString(),
                'description' => null,
                'days' => $t->leave_days ?? $t->durationDays(),
                'deduct' => $t->annual_leave_deducted,
                'long_service_leave_deducted' => $t->long_service_leave_deducted,
                'status' => $t->status,
                'notes' => $t->description,
                'deducted_from' => $allocation["t{$t->id}"] ?? [],
            ]);
        }

        return $items->sortBy(fn ($i) => sprintf('%04d-%s', $i['year'], $i['start_date'] ?? '00'))->values()->all();
    }

    private function assertReached(Employee $k, int $period): void
    {
        $reached = collect(LeaveLedger::summary($k)['long_service']['period'])->pluck('sequence_no');
        if (! $reached->contains($period)) {
            throw ValidationException::withMessages(['sequence_no' => 'Hak Cuti Besar periode ini belum tercapai.']);
        }
    }
}
