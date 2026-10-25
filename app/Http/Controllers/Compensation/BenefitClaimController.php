<?php

namespace App\Http\Controllers\Compensation;

use App\Http\Controllers\Controller;
use App\Models\BenefitClaim;
use App\Models\BenefitClaimItem;
use App\Models\Employee;
use App\Models\Attachment;
use App\Support\Compensation\AccountingMonth;
use App\Support\Compensation\BenefitClaimCheck;
use App\Support\Compensation\BenefitEntitlement;
use App\Support\Compensation\Usage;
use App\Support\Excel\TableExcel;
use App\Support\Attachments\Attachments;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
 * Deklarasi Pengobatan / Kacamata: the claim form (as on paper, without
 * Persalinan), checked against the plafon and booked to a month closing on the 8th.
 */
class BenefitClaimController extends Controller
{
    private const SUMBER = ['hr' => 'HR', 'self_service' => 'Portal', 'import' => 'Import'];

    private const RELATIONS = ['position', 'jobLevel', 'department', 'section.department', 'workLocation', 'profile.children'];

    public function index(Request $request): Response
    {
        $filters = self::filters($request);
        $type = $filters['type'] ?? null;

        $benefit_claims = self::query($filters)
            ->with(['employee.position', 'employee.jobLevel', 'items.children', 'attachments'])
            // Waiting for HR first.
            ->orderByRaw("case when status = 'diajukan' then 0 else 1 end")
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (BenefitClaim $d) => self::row($d));

        $today = CarbonImmutable::today();
        $current = AccountingMonth::of($today);

        return Inertia::render('Compensation/BenefitClaims', [
            'benefit_claims' => $benefit_claims,
            'filters' => ['month' => $filters['month'] ?? '', 'type' => $type ?? '', 'status' => $filters['status'] ?? '', 'q' => $filters['q'] ?? ''],
            'pending' => BenefitClaim::where('status', 'pending')->count(),
            'can_edit' => $request->user()->canEdit('benefit-claims'),
            'attachment_limits' => Attachments::limits(),
            'accounting_month' => [
                'current' => $current,
                'label' => AccountingMonth::label($current),
                'span' => AccountingMonth::span($current),
                'options' => collect(range(-12, 2))->map(function (int $o) use ($current) {
                    $value = CarbonImmutable::parse("{$current}-01")->addMonths($o)->format('Y-m');

                    return ['value' => $value, 'label' => AccountingMonth::label($value)];
                })->reverse()->values(),
            ],
            'people' => Employee::with(['position:id,name'])->where('status', '!=', Employee::STATUS_TERMINATED)->orderBy('employee_number')->get()
                ->map(fn (Employee $k) => ['id' => $k->id, 'employee_number' => $k->employee_number, 'name' => $k->name, 'position' => $k->position?->name]),
            // Places typed before (the "Ugang Sayu, 2 Okt 2026" line), as suggestions.
            'provider_options' => BenefitClaim::whereNotNull('provider_name')->distinct()->orderBy('provider_name')->pluck('provider_name'),
        ]);
    }

    /**
     * The list as it is filtered, in Excel: a Rekap sheet (one row per deklarasi, the amount
     * per kind) and a Rincian sheet (one row per amount, with who it was for).
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = self::filters($request);
        $benefit_claims = self::query($filters)
            ->with(['employee.position', 'employee.department', 'employee.section.department', 'employee.workLocation', 'employee.subLocation', 'items.children'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $sum = fn (BenefitClaim $d, string ...$type) => (int) $d->items->whereIn('type', $type)->sum('amount');
        $rekap = $benefit_claims->values()->map(fn (BenefitClaim $d, int $i) => [
            $i + 1,
            $d->claim_number,
            $d->date->toDateString(),
            AccountingMonth::label($d->accounting_month),
            $d->employee->employee_number,
            $d->employee->name,
            $d->employee->position?->name,
            $d->employee->placementDepartment()?->name,
            $d->employee->workLocation?->name,
            $d->employee->subLocation?->name,
            $d->provider_name,
            $sum($d, 'treatment'),
            $sum($d, 'medical'),
            $sum($d, 'frame'),
            $sum($d, 'lens'),
            (int) $d->items->sum('amount'),
            $d->receipt_count,
            ucfirst($d->status),
            self::SUMBER[$d->source] ?? $d->source,
            $d->description,
            $d->verification_notes,
        ]);
        $breakdown = [];
        foreach ($benefit_claims as $d) {
            foreach ($d->items as $item) {
                $breakdown[] = [
                    count($breakdown) + 1,
                    $d->claim_number,
                    $d->date->toDateString(),
                    AccountingMonth::label($d->accounting_month),
                    $d->employee->employee_number,
                    $d->employee->name,
                    BenefitClaimItem::JENIS[$item->type] ?? $item->type,
                    BenefitClaimItem::PENERIMA[$item->beneficiary] ?? $item->beneficiary,
                    $item->children?->name,
                    $item->amount,
                    ucfirst($d->status),
                ];
            }
        }

        $info = TableExcel::info([
            ($filters['month'] ?? null) ? 'Bulan buku '.AccountingMonth::label($filters['month']) : 'Semua bulan buku',
            match ($filters['type'] ?? null) {
                'medical' => 'Pengobatan',
                'optical' => 'Kacamata',
                default => null,
            },
            ($filters['status'] ?? null) ? 'Status '.$filters['status'] : null,
            trim($filters['q'] ?? '') !== '' ? 'Cari "'.trim($filters['q']).'"' : null,
            $benefit_claims->count().' deklarasi',
        ]);

        return TableExcel::download('claim-'.($filters['month'] ?? 'all').'-'.now()->format('Y-m-d').'.xlsx', [
            [
                'title' => 'Claim Summary',
                'info' => $info,
                'freeze' => 6,
                'columns' => [
                    ['No', 5, 'int'], ['Number', 18], ['Date', 12, 'date'], ['Accounting Month', 14], ['Employee Number', 12], ['Name', 28], ['Position', 26],
                    ['Department', 22], ['Location', 16], ['Sub Location', 16], ['Provider', 18],
                    ['Treatment', 13, 'rupiah', 'sum' => true], ['Medical', 13, 'rupiah', 'sum' => true], ['Optical Frame', 13, 'rupiah', 'sum' => true],
                    ['Optical Lens', 13, 'rupiah', 'sum' => true], ['Total', 14, 'rupiah', 'sum' => true], ['Receipts', 9, 'int', 'sum' => true],
                    ['Status', 11], ['Source', 9], ['Description', 28], ['Verification Notes', 28],
                ],
                'rows' => $rekap,
            ],
            [
                'title' => 'Claim Items',
                'info' => $info,
                'freeze' => 6,
                'columns' => [
                    ['No', 5, 'int'], ['Number', 18], ['Date', 12, 'date'], ['Accounting Month', 14], ['Employee Number', 12], ['Name', 28],
                    ['Type', 20], ['Beneficiary', 14], ['Child Name', 22], ['Amount', 14, 'rupiah', 'sum' => true], ['Status', 11],
                ],
                'rows' => $breakdown,
            ],
        ]);
    }

    /** For the form: who the employee is, their family and what is left of each plafon on that date. */
    public function info(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'except' => ['nullable', 'integer'],
        ]);
        $employee->load(self::RELATIONS);
        $date = CarbonImmutable::parse($data['date'] ?? CarbonImmutable::today()->toDateString());
        $month = AccountingMonth::of($date);

        return response()->json([
            'employee' => self::identity($employee),
            'accounting_month' => ['value' => $month, 'label' => AccountingMonth::label($month), 'span' => AccountingMonth::span($month)],
            ...Usage::summary($employee, AccountingMonth::year($month), $data['except'] ?? null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$employee, $data, $items] = self::validateClaim($request);
        $claim = DB::transaction(function () use ($data, $items, $request) {
            // Entered by HR from the signed form: approved as it is.
            $claim = BenefitClaim::create([
                ...$data,
                'claim_number' => self::nextClaimNumber($data['accounting_month']),
                'created_by' => $request->user()->id,
                'status' => 'approved',
                'source' => 'hr',
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
            ]);
            $claim->items()->createMany($items);
            Attachments::sync($request, $claim, 'claim');

            return $claim;
        });
        Inertia::flash('success', "Deklarasi {$claim->claim_number} {$employee->name} disimpan (bulan buku ".AccountingMonth::label($claim->accounting_month).').');

        return back();
    }

    public function update(Request $request, BenefitClaim $claim): RedirectResponse
    {
        [$employee, $data, $items] = self::validateClaim($request, $claim);
        DB::transaction(function () use ($claim, $data, $items, $request) {
            $claim->update($data);
            $claim->items()->delete();
            $claim->items()->createMany($items);
            Attachments::sync($request, $claim, 'claim');
        });
        Inertia::flash('success', "Deklarasi {$claim->claim_number} {$employee->name} diperbarui.");

        return back();
    }

    public function destroy(BenefitClaim $claim): RedirectResponse
    {
        $claim->delete();
        Inertia::flash('success', "Deklarasi {$claim->claim_number} dihapus; plafonnya kembali.");

        return back();
    }

    /** Approves (it then counts as "terpakai") or rejects (with a note; it no longer holds the plafon) a deklarasi from the portal. */
    public function verify(Request $request, BenefitClaim $claim): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'notes' => ['nullable', 'string', 'max:255', 'required_if:decision,reject'],
        ], ['notes.required_if' => 'Tulis alasan penolakan.']);
        if ($claim->status !== 'pending') {
            throw ValidationException::withMessages(['decision' => "Deklarasi {$claim->claim_number} sudah diverifikasi."]);
        }
        $approve = $data['decision'] === 'approve';
        if ($approve) {
            $employee = Employee::with(self::RELATIONS)->findOrFail($claim->employee_id);
            $items = $claim->items->map(fn (BenefitClaimItem $i) => $i->only(['type', 'beneficiary', 'employee_child_id', 'amount']))->all();
            if ($errors = BenefitClaimCheck::errors($employee, $items, $claim->benefit_year, $claim->id)) {
                throw ValidationException::withMessages(['decision' => $errors]);
            }
        }
        $claim->update([
            'status' => $approve ? 'approved' : 'rejected',
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
            'verification_notes' => $data['notes'] ?? null,
        ]);
        Inertia::flash('success', "Deklarasi {$claim->claim_number} ".($approve ? 'approved.' : 'rejected.'));

        return back();
    }

    /**
     * The form checked against the plafon (also used by the employee portal).
     *
     * @return array{0: Karyawan, 1: array, 2: list<array>}
     */
    public static function validateClaim(Request $request, ?BenefitClaim $current = null): array
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')],
            'date' => ['required', 'date_format:Y-m-d'],
            'provider_name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:40'],
            'items.*.type' => ['required', Rule::in(array_keys(BenefitClaimItem::JENIS))],
            'items.*.beneficiary' => ['required', Rule::in(array_keys(BenefitClaimItem::PENERIMA))],
            'items.*.employee_child_id' => ['nullable', 'integer'],
            'items.*.amount' => ['required', 'integer', 'min:1', 'max:999999999'],
            ...Attachments::rules(),
        ], [
            'items.required' => 'Isi minimal satu nominal di tabel deklarasi.',
            ...Attachments::messages(),
        ], [
            'employee_id' => 'NRP',
            'date' => 'Tanggal',
            'attachment.*' => 'Lampiran',
            'items.*.amount' => 'Nominal',
        ]);

        // Each photo, and each page of a PDF, is one nota.
        $receipt_doc = Attachments::require($request, $current, 'Lampirkan foto / PDF nota (minimal satu).');
        $employee = Employee::with(self::RELATIONS)->findOrFail($data['employee_id']);
        if (! $current && $employee->status === Employee::STATUS_TERMINATED) {
            throw ValidationException::withMessages(['employee_id' => "{$employee->name} sudah keluar."]);
        }
        $items = array_map(fn (array $i) => [
            'type' => $i['type'],
            'beneficiary' => $i['beneficiary'],
            'employee_child_id' => $i['beneficiary'] === 'children' ? ($i['employee_child_id'] ?? null) : null,
            'amount' => (int) $i['amount'],
        ], $data['items']);

        $month = AccountingMonth::of(CarbonImmutable::parse($data['date']));
        $year = AccountingMonth::year($month);
        if ($errors = BenefitClaimCheck::errors($employee, $items, $year, $current?->id)) {
            throw ValidationException::withMessages(['items' => $errors]);
        }

        return [$employee, [
            'employee_id' => $employee->id,
            'date' => $data['date'],
            'provider_name' => filled($data['provider_name'] ?? null) ? trim($data['provider_name']) : null,
            'receipt_count' => $receipt_doc,
            'description' => $data['description'] ?? null,
            'accounting_month' => $month,
            'benefit_year' => $year,
        ], $items];
    }

    private static function filters(Request $request): array
    {
        return $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'type' => ['nullable', Rule::in(['medical', 'optical'])],
            'status' => ['nullable', Rule::in(BenefitClaim::STATUS)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
    }

    /** The deklarasi matching the filters of the list. */
    private static function query(array $filters): Builder
    {
        $type = $filters['type'] ?? null;

        return BenefitClaim::query()
            ->when($filters['month'] ?? null, fn ($q, $b) => $q->where('accounting_month', $b))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($type, fn ($q) => $q->whereHas('items', fn ($i) => $i->whereIn('type', $type === 'optical' ? BenefitClaimItem::KACAMATA : BenefitClaimItem::PENGOBATAN)))
            ->when(trim($filters['q'] ?? ''), fn ($q, $s) => $q->where(fn ($w) => $w->where('claim_number', 'like', "%{$s}%")
                ->orWhereHas('employee', fn ($k) => $k->where('name', 'like', "%{$s}%")->orWhere('employee_number', 'like', "%{$s}%"))));
    }

    /** DKL/2026/10/0001 — numbered within the book month. */
    public static function nextClaimNumber(string $month): string
    {
        $prefix = 'DKL/'.str_replace('-', '/', $month).'/';
        // Across every employee: a portal user only sees their own deklarasi.
        $last = BenefitClaim::withoutGlobalScope('access')->where('claim_number', 'like', "{$prefix}%")->orderByDesc('claim_number')->value('claim_number');

        return $prefix.sprintf('%04d', $last ? ((int) substr($last, -4)) + 1 : 1);
    }

    public static function identity(Employee $k): array
    {
        return [
            'id' => $k->id,
            'employee_number' => $k->employee_number,
            'name' => $k->name,
            'position' => $k->position?->name,
            'job_level' => $k->jobLevel?->name,
            'department' => $k->placementDepartment()?->name,
            'work_location' => $k->workLocation?->name,
            'grade' => $k->profile?->grade,
            'ptkp_status' => BenefitEntitlement::statusPtkp($k),
        ];
    }

    public static function row(BenefitClaim $d): array
    {
        $sum = fn (array $type) => $d->items->whereIn('type', $type)->sum('amount');

        return [
            'id' => $d->id,
            'claim_number' => $d->claim_number,
            'date' => $d->date->toDateString(),
            'provider_name' => $d->provider_name,
            'accounting_month' => $d->accounting_month,
            'month_label' => AccountingMonth::label($d->accounting_month),
            'receipt_count' => $d->receipt_count,
            'description' => $d->description,
            'employee_id' => $d->employee_id,
            'employee_number' => $d->employee->employee_number,
            'name' => $d->employee->name,
            'position' => $d->employee->position?->name,
            'medical' => $sum(BenefitClaimItem::PENGOBATAN),
            'optical' => $sum(BenefitClaimItem::KACAMATA),
            'total' => $d->items->sum('amount'),
            'status' => $d->status,
            'source' => $d->source,
            'verification_notes' => $d->verification_notes,
            'attachment' => $d->attachments->map(fn (Attachment $l) => $l->toView())->values(),
            'items' => $d->items->map(fn (BenefitClaimItem $i) => [
                'type' => $i->type,
                'beneficiary' => $i->beneficiary,
                'employee_child_id' => $i->employee_child_id,
                'children' => $i->children?->name,
                'amount' => $i->amount,
            ])->values(),
        ];
    }
}
