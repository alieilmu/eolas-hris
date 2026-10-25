<?php

namespace App\Http\Controllers\Time;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Attachment;
use App\Models\SubLocation;
use App\Models\LeaveTravelRequest;
use App\Models\WorkLocation;
use App\Support\Access\AccessScope;
use App\Support\Time\Airport;
use App\Support\Time\Onsite;
use App\Support\Time\Period;
use App\Support\Time\Roll;
use App\Support\Time\LeaveTravelImport;
use App\Support\Time\LeaveTravelWriter;
use App\Support\Leave\LeaveLedger;
use App\Support\Leave\FieldLeaveAllowanceRate;
use App\Support\Excel\TableExcel;
use App\Support\Employees\LookupValues;
use App\Support\Employees\EmployeeNumber;
use App\Support\Attachments\Attachments;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cuti & Dinas: business trips (dinas) and leave (cuti). Each entry fills its
 * days in Absensi, and with it the Man Onsite chart; the Pengajuan Dinas list
 * follows each trip and its money request.
 */
class LeaveTravelController extends Controller
{
    public const STATUS_UANG = 'cash_advance_status';

    public function index(Request $request): Response
    {
        $period = Period::from($request->query('period'));
        $location = $request->integer('location') ?: null;
        $sub = Roll::subLocationFilter($request->query('sub'));
        $today = CarbonImmutable::today();

        return Inertia::render('Time/LeaveTravel', [
            'period' => $period->toArray(),
            'periods' => Period::options(),
            'location' => $location,
            'sub' => $sub === null ? '' : (string) $sub,
            'locations' => AccessScope::locations(),
            'sub_locations' => AccessScope::subLocations(),
            'can_edit' => $request->user()->canEdit('leave-travel'),
            'daily' => Onsite::daily($period->start, $period->end(), $location, $sub),
            'today' => Onsite::daily($today, $today, $location, $sub)[0],
            'away' => Onsite::awayToday($location, $today, $sub),
            'people' => self::people(),
            'cities' => self::cities(),
            'airport' => Airport::options(),
            'attachment_limits' => Attachments::limits(),
        ]);
    }

    /** Pengajuan Dinas: every trip with where it stands today and its money request, 10 per page. */
    public function list(Request $request): Response
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(['business_trip', 'leave', 'all'])],
            'travel' => ['nullable', Rule::in(['soon', 'ongoing', 'end_date'])],
            'status' => ['nullable', Rule::in(LeaveTravelRequest::STATUS)],
            'cash' => ['nullable', 'string', 'max:100'],
            'ticket' => ['nullable', Rule::in(['not_yet'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $type = $filters['type'] ?? 'business_trip';
        $today = CarbonImmutable::today();
        $d = $today->toDateString();

        $leave_travel_requests = LeaveTravelRequest::with(self::WITH)
            ->when($type !== 'all', fn ($q) => $q->where('type', $type))
            ->when($filters['travel'] ?? null, fn ($q, $p) => match ($p) {
                'soon' => $q->whereDate('start_date', '>', $d),
                'end_date' => $q->whereDate('end_date', '<', $d),
                default => $q->whereDate('start_date', '<=', $d)->whereDate('end_date', '>=', $d),
            })
            ->when($filters['cash'] ?? null, fn ($q, $s) => $q->where('cash_advance_status', $s))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['ticket'] ?? null, fn ($q) => self::ticketsPending($q))
            ->when(trim($filters['q'] ?? ''), fn ($q, $s) => self::search($q, $s))
            // Waiting for HR first.
            ->orderByRaw("case when status = 'diajukan' then 0 else 1 end")
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (LeaveTravelRequest $t) => self::tripData($t, $today));

        return Inertia::render('Time/TravelRequests', [
            'leave_travel_requests' => $leave_travel_requests,
            'filters' => [
                'type' => $type, 'travel' => $filters['travel'] ?? '', 'cash' => $filters['cash'] ?? '', 'status' => $filters['status'] ?? '',
                'ticket' => $filters['ticket'] ?? '', 'q' => $filters['q'] ?? '',
            ],
            // Pengajuan from the portal waiting for HR, per jenis.
            'pending' => LeaveTravelRequest::where('status', 'pending')->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type'),
            // Approved trips whose tickets are still to buy.
            'tickets_pending' => self::ticketsPending(LeaveTravelRequest::where('status', 'approved'))->count(),
            'can_edit' => $request->user()->canEdit('travel-requests'),
            'cash_advance_status' => LookupValues::values(self::STATUS_UANG),
            'people' => self::people(),
            'cities' => self::cities(),
            'airport' => Airport::options(),
            'attachment_limits' => Attachments::limits(),
        ]);
    }

    /**
     * Trips and leave in Excel: from Cuti & Dinas for a range of months (those touching it),
     * or from Pengajuan Dinas with the filters of the list.
     */
    public function export(Request $request): StreamedResponse
    {
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m'],
            'until' => ['nullable', 'date_format:Y-m'],
            'type' => ['nullable', Rule::in(['business_trip', 'leave', 'all'])],
            'travel' => ['nullable', Rule::in(['soon', 'ongoing', 'end_date'])],
            'status' => ['nullable', Rule::in(LeaveTravelRequest::STATUS)],
            'cash' => ['nullable', 'string', 'max:100'],
            'ticket' => ['nullable', Rule::in(['not_yet'])],
            'q' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'integer'],
            'sub' => ['nullable', 'string', 'max:10'],
        ]);
        $today = CarbonImmutable::today();
        $d = $today->toDateString();
        $months = ($f['from'] ?? null) ? Period::range($f['from'], $f['until'] ?? $f['from'], 36) : null;
        $from = $months ? $months[0]->start : null;
        $to = $months ? end($months)->end() : null;
        $type = $f['type'] ?? 'all';
        $sub = Roll::subLocationFilter($f['sub'] ?? null);

        $leave_travel_requests = LeaveTravelRequest::with(['employee.position:id,name', 'employee.workLocation:id,name', 'employee.subLocation:id,name', 'employee.department', 'employee.section.department',
            'employee.profile:id,employee_id,national_id_number,employee_class'])
            ->when($months, fn ($q) => $q->whereDate('start_date', '<=', $to->toDateString())->whereDate('end_date', '>=', $from->toDateString()))
            ->when($type !== 'all', fn ($q) => $q->where('type', $type))
            ->when($f['travel'] ?? null, fn ($q, $p) => match ($p) {
                'soon' => $q->whereDate('start_date', '>', $d),
                'end_date' => $q->whereDate('end_date', '<', $d),
                default => $q->whereDate('start_date', '<=', $d)->whereDate('end_date', '>=', $d),
            })
            ->when($f['cash'] ?? null, fn ($q, $st) => $q->where('cash_advance_status', $st))
            ->when($f['status'] ?? null, fn ($q, $st) => $q->where('status', $st))
            ->when($f['location'] ?? null, fn ($q, $l) => $q->whereHas('employee', fn ($k) => $k->where('work_location_id', $l)))
            ->when($sub !== null, fn ($q) => $q->whereHas('employee', fn ($k) => $sub === Roll::NO_SUB_LOCATION ? $k->whereNull('sub_location_id') : $k->where('sub_location_id', $sub)))
            ->when($f['ticket'] ?? null, fn ($q) => self::ticketsPending($q))
            ->when(trim($f['q'] ?? ''), fn ($q, $s) => self::search($q, $s))
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        $route = fn (?string $a, ?string $b) => $a || $b ? ($a ?? '?').' → '.($b ?? '?') : null;
        $ticket = fn (LeaveTravelRequest $t, string $direction) => $t->hasTicket($direction) ? ($t->{"{$direction}_ticket_issued_at"} ? 'Sudah dibeli '.$t->{"{$direction}_ticket_issued_at"}->format('d.m.Y') : 'Belum') : null;
        $rows = $leave_travel_requests->values()->map(fn (LeaveTravelRequest $t, int $i) => [
            $i + 1,
            LeaveTravelRequest::JENIS[$t->type] ?? $t->type,
            $t->employee->employee_number,
            $t->employee->profile?->national_id_number,
            $t->employee->name,
            $t->employee->position?->name,
            $t->employee->placementDepartment()?->name,
            $t->employee->workLocation?->name,
            $t->employee->subLocation?->name,
            EmployeeNumber::derived($t->employee)['hire_location'],
            $t->employee->profile?->employee_class,
            $t->start_date->toDateString(),
            $t->end_date->toDateString(),
            $t->durationDays(),
            $t->origin_city,
            $t->destination_city,
            $t->leave_category ? (LeaveLedger::KATEGORI[$t->leave_category] ?? $t->leave_category) : null,
            $t->leave_days,
            $t->annual_leave_deducted ?: null,
            $t->long_service_leave_deducted ?: null,
            $t->field_leave_allowance,
            $t->needs_flight ? $route($t->departure_airport, $t->arrival_airport) : null,
            $t->needs_flight && $t->is_round_trip ? $t->return_date?->toDateString() : null,
            $t->needs_flight && $t->is_round_trip ? $route($t->return_departure_airport, $t->return_arrival_airport) : null,
            $t->needs_train ? 'Ya' : null,
            $ticket($t, 'outbound'),
            $ticket($t, 'return'),
            $t->needs_cash_advance ? $t->cash_advance_amount : null,
            $t->cash_advance_status,
            ['soon' => 'Akan berangkat', 'ongoing' => 'Berjalan', 'end_date' => 'Selesai'][$t->travelStatus($today)],
            ucfirst($t->status),
            ['hr' => 'HR', 'self_service' => 'Portal'][$t->source] ?? $t->source,
            $t->description,
            $t->verification_notes,
        ]);

        $name = $months ? $from->format('Y-m').($from->format('Y-m') !== $to->format('Y-m') ? '-sd-'.$to->format('Y-m') : '') : 'all';

        return TableExcel::download("leave_travel-leave-{$name}-".$today->format('Y-m-d').'.xlsx', [[
            'title' => 'Cuti & Dinas',
            'info' => TableExcel::info([
                $months ? 'Periode '.$from->format('d.m.Y').' – '.$to->format('d.m.Y') : 'Semua tanggal',
                $type === 'all' ? 'Dinas & cuti' : LeaveTravelRequest::JENIS[$type],
                ($f['location'] ?? null) ? 'Lokasi '.WorkLocation::whereKey($f['location'])->value('name') : null,
                match (true) {
                    $sub === Roll::NO_SUB_LOCATION => 'Tanpa sub lokasi',
                    is_int($sub) => 'Sub lokasi '.SubLocation::whereKey($sub)->value('name'),
                    default => null,
                },
                ($f['travel'] ?? null) ? 'Perjalanan '.$f['travel'] : null,
                ($f['status'] ?? null) ? 'Status '.$f['status'] : null,
                ($f['cash'] ?? null) ? 'Status uang '.$f['cash'] : null,
                ($f['ticket'] ?? null) ? 'Tiket belum dibeli' : null,
                trim($f['q'] ?? '') !== '' ? 'Cari "'.trim($f['q']).'"' : null,
                $leave_travel_requests->count().' data',
            ]),
            'freeze' => 4,
            'columns' => [
                ['No', 5, 'int'], ['Type', 8], ['Employee Number', 12], ['National ID Number', 18], ['Name', 28], ['Position', 26], ['Department', 22], ['Location', 16], ['Sub Location', 16],
                ['POH', 16], ['Employee Class', 11], ['Departure / Start', 12, 'date'], ['Return / End', 12, 'date'], ['Duration (days)', 8, 'int', 'sum' => true],
                ['Origin City', 16], ['Destination City', 16], ['Leave Category', 22], ['Leave Days', 7, 'int', 'sum' => true],
                ['Annual Deducted', 9, 'int', 'sum' => true], ['Long Service Deducted', 9, 'int', 'sum' => true], ['Lumpsum', 14, 'rupiah', 'sum' => true],
                ['Outbound Flight', 16], ['Return Date (round trip)', 12, 'date'], ['Return Flight', 16], ['Train Ticket', 8],
                ['Outbound Ticket', 20], ['Return Ticket', 20],
                ['Cash Advance', 14, 'rupiah', 'sum' => true], ['Cash Advance Status', 18], ['Travel Status', 14], ['Status', 11], ['Source', 8],
                ['Description', 32], ['Verification Notes', 28],
            ],
            'rows' => $rows,
        ]]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = self::validateTrip($request);
        LeaveTravelWriter::assertFree($data['employee_id'], $data['start_date'], $data['end_date']);
        LeaveTravelWriter::codeFor($data['type']);

        $leave_travel = DB::transaction(function () use ($data, $request) {
            // Entered by HR: approved as it is, so it fills Absensi straight away.
            $leave_travel = LeaveTravelRequest::create([
                ...$data,
                'cash_advance_status' => self::initialCashAdvanceStatus($data['needs_cash_advance']),
                'created_by' => $request->user()->id,
                'status' => 'approved',
                'source' => 'hr',
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
            ]);
            LeaveTravelWriter::fill($leave_travel);
            Attachments::sync($request, $leave_travel, 'leave_travel');

            return $leave_travel;
        });
        Inertia::flash('success', LeaveTravelRequest::JENIS[$leave_travel->type].' '.$leave_travel->employee->name.' ke '.$leave_travel->destination_city.' disimpan; Absensi '.$leave_travel->durationDays().' hari ikut terisi.');

        return back();
    }

    public function update(Request $request, LeaveTravelRequest $leave_travel): RedirectResponse
    {
        $data = self::validateTrip($request, $leave_travel);
        LeaveTravelWriter::assertFree($data['employee_id'], $data['start_date'], $data['end_date'], $leave_travel);
        LeaveTravelWriter::codeFor($data['type']);

        // Uang muka asked now on a trip whose money was not requested yet: it is requested.
        if ($data['needs_cash_advance'] && ! $leave_travel->needs_cash_advance && $leave_travel->cash_advance_status === (LookupValues::values(self::STATUS_UANG)[0] ?? null)) {
            $data['cash_advance_status'] = self::initialCashAdvanceStatus(true);
        }
        DB::transaction(function () use ($leave_travel, $data, $request) {
            $leave_travel->update($data);
            LeaveTravelWriter::fill($leave_travel->refresh());
            Attachments::sync($request, $leave_travel, 'leave_travel');
        });
        Inertia::flash('success', 'Data '.LeaveTravelRequest::JENIS[$leave_travel->type].' '.$leave_travel->employee->name.' diperbarui.');

        return back();
    }

    public function destroy(LeaveTravelRequest $leave_travel): RedirectResponse
    {
        $leave_travel->delete(); // its Absensi days go with it
        Inertia::flash('success', 'Data '.LeaveTravelRequest::JENIS[$leave_travel->type].' '.$leave_travel->employee->name.' dihapus; hari-harinya di Absensi ikut dikosongkan.');

        return back();
    }

    /** Approves (fills Absensi) or rejects (with a note) a cuti / dinas from the employee portal. */
    public function verify(Request $request, LeaveTravelRequest $leave_travel): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'notes' => ['nullable', 'string', 'max:255', 'required_if:decision,reject'],
        ], ['notes.required_if' => 'Tulis alasan penolakan.']);
        if ($leave_travel->status !== 'pending') {
            throw ValidationException::withMessages(['decision' => 'Pengajuan ini sudah diverifikasi.']);
        }
        $approve = $data['decision'] === 'approve';
        if ($approve) {
            LeaveTravelWriter::codeFor($leave_travel->type);
        }
        DB::transaction(function () use ($leave_travel, $approve, $data, $request) {
            $leave_travel->update([
                'status' => $approve ? 'approved' : 'rejected',
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
                'verification_notes' => $data['notes'] ?? null,
            ]);
            LeaveTravelWriter::fill($leave_travel);
        });
        Inertia::flash('success', LeaveTravelRequest::JENIS[$leave_travel->type].' '.$leave_travel->employee->name.' '.($approve ? 'disetujui; Absensi ikut terisi.' : 'rejected.'));

        return back();
    }

    public function cashAdvanceStatus(Request $request, LeaveTravelRequest $leave_travel): RedirectResponse
    {
        $data = $request->validate([
            'cash_advance_status' => ['required', Rule::in(LookupValues::values(self::STATUS_UANG))],
        ], [], ['cash_advance_status' => 'Status Pengajuan Uang']);
        $leave_travel->update($data);
        Inertia::flash('success', "Status uang {$leave_travel->employee->name}: {$data['cash_advance_status']}.");

        return back();
    }

    public function template(): StreamedResponse
    {
        return LeaveTravelImport::template();
    }

    /** Import Excel: first a check of every row (JSON), then with `simpan` the valid rows are saved. */
    public function import(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'], 'save' => ['nullable', 'boolean']], [], ['file' => 'File Excel']);
        $save = $request->boolean('save');
        $result = LeaveTravelImport::run($data['file']->getRealPath(), $request->user(), $save);
        if (! $save) {
            return response()->json($result);
        }
        if (! $result['ok']) {
            throw ValidationException::withMessages(['file' => 'Tidak ada baris yang bisa diimport.']);
        }
        Inertia::flash('success', "{$result['ok']} pengajuan diimport dan Absensi ikut terisi".($result['failed'] ? "; {$result['failed']} baris dilewati." : '.'));

        return back();
    }

    /** Marks the ticket going (pergi) or coming back (pulang) as bought, or not yet. */
    public function ticket(Request $request, LeaveTravelRequest $leave_travel): RedirectResponse
    {
        $data = $request->validate(['direction' => ['required', Rule::in(['outbound', 'return'])], 'issued' => ['required', 'boolean']]);
        $direction = $data['direction'];
        if (! $leave_travel->hasTicket($direction)) {
            throw ValidationException::withMessages(['direction' => "Pengajuan ini tidak meminta tiket {$direction}."]);
        }
        $issued = (bool) $data['issued'];
        $leave_travel->update(["{$direction}_ticket_issued_at" => $issued ? now() : null, "{$direction}_ticket_issued_by" => $issued ? $request->user()->id : null]);
        Inertia::flash('success', "Tiket {$direction} {$leave_travel->employee->name}: ".($issued ? 'sudah dibeli.' : 'belum dibeli.'));

        return back();
    }

    /** The first money status: "Diajukan" when uang muka is asked (if that status exists), else the first one. */
    public static function initialCashAdvanceStatus(bool $uangMuka): ?string
    {
        $options = LookupValues::values(self::STATUS_UANG);

        return $uangMuka && in_array('Diajukan', $options, true) ? 'Diajukan' : ($options[0] ?? null);
    }

    /** A new trip is for an active employee; an existing one keeps its employee even after they left. */
    public static function validateTrip(Request $request, ?LeaveTravelRequest $current = null, bool $attachment = true): array
    {
        $keepsEmployee = $current && (int) $request->input('employee_id') === $current->employee_id;
        $business_trip = $request->input('type') === 'business_trip';
        $pp = $request->boolean('needs_flight') && $request->boolean('is_round_trip');
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(LeaveTravelRequest::JENIS))],
            'leave_category' => ['nullable', 'required_if:type,leave', Rule::in(array_keys(LeaveLedger::KATEGORI))],
            'employee_id' => ['required', 'integer', $keepsEmployee ? Rule::exists('employees', 'id') : Rule::exists('employees', 'id')->where('status', Employee::STATUS_ACTIVE)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'origin_city' => ['required', 'string', 'max:100'],
            'destination_city' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255', Rule::requiredIf($business_trip && $request->boolean('needs_train'))],
            'needs_flight' => ['nullable', 'boolean'],
            'departure_airport' => ['nullable', 'required_if_accepted:needs_flight', Rule::in(Airport::codes())],
            'arrival_airport' => ['nullable', 'required_if_accepted:needs_flight', Rule::in(Airport::codes()), 'different:departure_airport'],
            'is_round_trip' => ['nullable', 'boolean'],
            'return_date' => ['nullable', Rule::requiredIf($pp), 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'return_departure_airport' => ['nullable', Rule::requiredIf($pp), Rule::in(Airport::codes())],
            'return_arrival_airport' => ['nullable', Rule::requiredIf($pp), Rule::in(Airport::codes()), 'different:return_departure_airport'],
            'needs_train' => ['nullable', 'boolean'],
            'needs_cash_advance' => ['nullable', 'boolean'],
            'cash_advance_amount' => ['nullable', Rule::requiredIf($business_trip && $request->boolean('needs_cash_advance')), 'integer', 'min:1', 'max:999999999'],
            ...Attachments::rules(),
        ], [
            'employee_id.exists' => 'NRP tidak ditemukan di karyawan aktif.',
            'end_date.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
            'leave_category.required_if' => 'Pilih jenis cuti.',
            'departure_airport.required_if_accepted' => 'Pilih bandara keberangkatan.',
            'arrival_airport.required_if_accepted' => 'Pilih bandara tujuan.',
            'arrival_airport.different' => 'Bandara tujuan harus berbeda dari bandara keberangkatan.',
            'departure_airport.in' => 'Bandara tidak dikenal; pilih dari daftar.',
            'arrival_airport.in' => 'Bandara tidak dikenal; pilih dari daftar.',
            'return_date.required' => 'Isi tanggal penerbangan pulang.',
            'return_date.after_or_equal' => 'Tanggal pulang tidak boleh sebelum tanggal awal.',
            'return_departure_airport.required' => 'Pilih bandara keberangkatan pulang.',
            'return_arrival_airport.required' => 'Pilih bandara tujuan pulang.',
            'return_arrival_airport.different' => 'Bandara tujuan pulang harus berbeda dari bandara keberangkatannya.',
            'return_departure_airport.in' => 'Bandara tidak dikenal; pilih dari daftar.',
            'return_arrival_airport.in' => 'Bandara tidak dikenal; pilih dari daftar.',
            'description.required' => 'Tulis stasiun asal dan tujuan kereta di Keterangan (mis. Gambir → Bandung).',
            'cash_advance_amount.required' => 'Isi jumlah uang muka yang diajukan.',
            'cash_advance_amount.min' => 'Jumlah uang muka harus lebih dari 0.',
            ...Attachments::messages(),
        ], [
            'employee_id' => 'NRP',
            'start_date' => 'Tanggal awal',
            'end_date' => 'Tanggal akhir',
            'origin_city' => 'Kota asal',
            'destination_city' => 'Kota tujuan',
            'cash_advance_amount' => 'Jumlah uang muka',
        ]);
        if (CarbonImmutable::parse($data['start_date'])->diffInDays(CarbonImmutable::parse($data['end_date'])) > 366) {
            throw ValidationException::withMessages(['end_date' => 'Satu entri paling lama 1 tahun.']);
        }
        $attachment && Attachments::require($request, $current, 'Lampirkan foto / PDF form '.(($data['type'] ?? '') === 'leave' ? 'cuti' : 'dinas').' yang sudah disetujui.');
        $data['origin_city'] = trim($data['origin_city']);
        $data['destination_city'] = trim($data['destination_city']);
        // A flight ticket goes with its airports; a train ticket only for dinas.
        $pesawat = $request->boolean('needs_flight');
        $data['needs_flight'] = $pesawat;
        $data['departure_airport'] = $pesawat ? $data['departure_airport'] : null;
        $data['arrival_airport'] = $pesawat ? $data['arrival_airport'] : null;
        $data['is_round_trip'] = $pp;
        $data['return_date'] = $pp ? $data['return_date'] : null;
        $data['return_departure_airport'] = $pp ? $data['return_departure_airport'] : null;
        $data['return_arrival_airport'] = $pp ? $data['return_arrival_airport'] : null;
        $data['needs_train'] = $business_trip && $request->boolean('needs_train');
        // Uang muka only for dinas, with its amount.
        $data['needs_cash_advance'] = $business_trip && $request->boolean('needs_cash_advance');
        $data['cash_advance_amount'] = $data['needs_cash_advance'] ? (int) $data['cash_advance_amount'] : null;
        unset($data['attachment'], $data['page_count'], $data['delete_attachments']);

        // A cuti takes its days (calendar days, as in Absensi) from the annual quota or Cuti Besar, by its kind.
        $days = (int) CarbonImmutable::parse($data['start_date'])->diffInDays(CarbonImmutable::parse($data['end_date'])) + 1;
        if ($data['type'] === 'leave') {
            $employee = Employee::withoutGlobalScope('access')->findOrFail($data['employee_id']);
            $data += ['leave_days' => $days, ...LeaveLedger::potongan($employee, $data['leave_category'], $days, $current?->id)];
            $data['field_leave_allowance'] = FieldLeaveAllowanceRate::amount($employee, $data['leave_category']);
        } else {
            $data = [...$data, 'leave_category' => null, 'leave_days' => null, 'annual_leave_deducted' => 0, 'long_service_leave_deducted' => 0, 'long_service_period' => null, 'field_leave_allowance' => null];
        }
        if (! $pesawat && ! $data['needs_train']) {
            $data += ['outbound_ticket_issued_at' => null, 'outbound_ticket_issued_by' => null];
        }
        if (! $pp) {
            $data += ['return_ticket_issued_at' => null, 'return_ticket_issued_by' => null];
        }

        return $data;
    }

    public static function tripData(LeaveTravelRequest $t, CarbonImmutable $today): array
    {
        return [
            'id' => $t->id,
            'employee_id' => $t->employee_id,
            'employee_number' => $t->employee->employee_number,
            'name' => $t->employee->name,
            'position' => $t->employee->position?->name,
            'work_location' => $t->employee->workLocation?->name,
            'national_id_number' => $t->employee->profile?->national_id_number,
            'point_of_hire' => EmployeeNumber::derived($t->employee)['hire_location'],
            'employee_class' => $t->employee->profile?->employee_class,
            'type' => $t->type,
            'start_date' => $t->start_date->toDateString(),
            'end_date' => $t->end_date->toDateString(),
            'origin_city' => $t->origin_city,
            'destination_city' => $t->destination_city,
            'description' => $t->description,
            'cash_advance_status' => $t->cash_advance_status,
            'status' => $t->status,
            'source' => $t->source,
            'verification_notes' => $t->verification_notes,
            'leave_category' => $t->leave_category,
            'leave_days' => $t->leave_days,
            'annual_leave_deducted' => $t->annual_leave_deducted,
            'long_service_leave_deducted' => $t->long_service_leave_deducted,
            'needs_flight' => $t->needs_flight,
            'departure_airport' => $t->departure_airport,
            'arrival_airport' => $t->arrival_airport,
            'is_round_trip' => $t->is_round_trip,
            'return_date' => $t->return_date?->toDateString(),
            'return_departure_airport' => $t->return_departure_airport,
            'return_arrival_airport' => $t->return_arrival_airport,
            'needs_train' => $t->needs_train,
            'needs_cash_advance' => $t->needs_cash_advance,
            'cash_advance_amount' => $t->cash_advance_amount,
            'field_leave_allowance' => $t->field_leave_allowance,
            'tickets_issued' => [
                'outbound' => $t->outbound_ticket_issued_at ? ['at' => $t->outbound_ticket_issued_at->toIso8601String(), 'by' => $t->outboundTicketIssuer?->name] : null,
                'return' => $t->return_ticket_issued_at ? ['at' => $t->return_ticket_issued_at->toIso8601String(), 'by' => $t->returnTicketIssuer?->name] : null,
            ],
            'attachment' => $t->attachments->map(fn (Attachment $l) => $l->toView())->values(),
            'old' => $t->durationDays(),
            'travel' => $t->travelStatus($today),
            // Days until departure (akan), the day of the trip it is (berjalan), days since return (selesai).
            'day_number' => match ($t->travelStatus($today)) {
                'soon' => (int) $today->diffInDays($t->start_date),
                'ongoing' => (int) $t->start_date->diffInDays($today) + 1,
                default => (int) $t->end_date->diffInDays($today),
            },
        ];
    }

    /** What tripData() reads. */
    public const WITH = ['employee.position:id,name', 'employee.workLocation:id,name', 'employee.profile:id,employee_id,national_id_number,employee_class', 'attachments',
        'outboundTicketIssuer:id,name', 'returnTicketIssuer:id,name'];

    /** Trips with a ticket asked and not bought yet, going or coming back. */
    private static function ticketsPending($query)
    {
        return $query->where(fn ($q) => $q
            ->where(fn ($w) => $w->where(fn ($t) => $t->where('needs_flight', true)->orWhere('needs_train', true))->whereNull('outbound_ticket_issued_at'))
            ->orWhere(fn ($w) => $w->where('needs_flight', true)->where('is_round_trip', true)->whereNull('return_ticket_issued_at')));
    }

    /** Name, NRP, NIK or city. */
    private static function search($query, string $s)
    {
        return $query->where(fn ($w) => $w
            ->whereHas('employee', fn ($k) => $k->where('name', 'like', "%{$s}%")->orWhere('employee_number', 'like', "%{$s}%")
                ->orWhereHas('profile', fn ($p) => $p->where('national_id_number', 'like', "%{$s}%")))
            ->orWhere('destination_city', 'like', "%{$s}%")
            ->orWhere('origin_city', 'like', "%{$s}%"));
    }

    /** Active employees, for looking up an NRP in the form. */
    private static function people(): array
    {
        return Employee::with(['position:id,name', 'workLocation:id,name'])
            ->where('status', Employee::STATUS_ACTIVE)
            ->orderBy('employee_number')
            ->get()
            ->map(fn (Employee $k) => [
                'id' => $k->id,
                'employee_number' => $k->employee_number,
                'name' => $k->name,
                'position' => $k->position?->name,
                'work_location' => $k->workLocation?->name,
            ])
            ->all();
    }

    /** Cities typed before, as suggestions. */
    private static function cities(): array
    {
        return LeaveTravelRequest::query()->select('origin_city as city')->union(LeaveTravelRequest::query()->select('destination_city as city'))
            ->pluck('city')->unique()->sort()->values()->all();
    }
}
