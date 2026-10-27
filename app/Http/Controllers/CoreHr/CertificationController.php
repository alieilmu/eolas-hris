<?php

namespace App\Http\Controllers\CoreHr;

use App\Http\Controllers\Controller;
use App\Models\CertificationType;
use App\Models\Employee;
use App\Models\EmployeeCertification;
use App\Support\Access\AccessScope;
use App\Support\Attachments\Attachments;
use App\Support\Excel\TableExcel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sertifikasi & Izin Kerja: licences and certificates that expire (STR/SIP, SIMPER/KIMPER,
 * SKK, K3 …) per employee, with their scans; what runs out soon comes first. Reminders go
 * out 90, 60 and 30 days before (eolas:certifications:remind).
 */
class CertificationController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(array_keys(EmployeeCertification::STATUS))], 'type' => ['nullable', 'integer'], 'location' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:100']]);
        $today = CarbonImmutable::today();
        $all = EmployeeCertification::with(['employee:id,employee_number,name,work_location_id,position_id', 'employee.position:id,name', 'employee.workLocation:id,name', 'type', 'attachments'])
            ->whereHas('employee', fn ($q) => $q->where('status', '!=', Employee::STATUS_TERMINATED))
            ->when($f['type'] ?? null, fn ($q, $t) => $q->where('certification_type_id', $t))
            ->when($f['location'] ?? null, fn ($q, $l) => $q->whereHas('employee', fn ($q) => $q->where('work_location_id', $l)))
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where(fn ($q) => $q->where('number', 'like', "%{$t}%")
                ->orWhereHas('employee', fn ($q) => $q->where('name', 'like', "%{$t}%")->orWhere('employee_number', 'like', "%{$t}%"))))
            ->get();
        $counts = $all->countBy(fn (EmployeeCertification $c) => $c->status($today));
        $rows = $all->when($f['status'] ?? null, fn ($c, $s) => $c->filter(fn (EmployeeCertification $x) => $x->status($today) === $s))
            ->sortBy(fn (EmployeeCertification $c) => $c->expires_at?->format('Y-m-d') ?? '9999')->values();

        return Inertia::render('CoreHr/Certifications', [
            'rows' => $rows->map(fn (EmployeeCertification $c) => self::share($c, $today)),
            'counts' => collect(EmployeeCertification::STATUS)->map(fn ($l, $k) => $counts[$k] ?? 0),
            'filters' => $f,
            'types' => CertificationType::orderBy('sort_order')->get(['id', 'code', 'name', 'description', 'expires', 'is_active']),
            'employees' => Employee::where('status', '!=', Employee::STATUS_TERMINATED)->orderBy('name')->get(['id', 'employee_number', 'name'])
                ->map(fn (Employee $e) => ['id' => $e->id, 'label' => "{$e->employee_number} · {$e->name}"]),
            'locations' => AccessScope::locations(),
            'attachment_limits' => Attachments::limits(),
            'can_edit' => $request->user()->canEdit('certifications'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        Employee::findOrFail($data['employee_id']); // scoped
        $c = EmployeeCertification::create($data + ['created_by' => $request->user()->id]);
        Attachments::sync($request, $c, 'certification');
        Inertia::flash('success', 'Sertifikat dicatat.');

        return back();
    }

    public function update(Request $request, EmployeeCertification $certification): RedirectResponse
    {
        $data = $this->validated($request);
        Employee::findOrFail($data['employee_id']);
        // A new expiry date starts the reminders again.
        $certification->update($data + ($certification->expires_at?->format('Y-m-d') !== ($data['expires_at'] ?? null) ? ['reminded_days' => null] : []));
        Attachments::sync($request, $certification, 'certification');
        Inertia::flash('success', 'Sertifikat diperbarui.');

        return back();
    }

    public function destroy(EmployeeCertification $certification): RedirectResponse
    {
        $certification->attachments->each->delete();
        $certification->delete();
        Inertia::flash('success', 'Sertifikat dihapus.');

        return back();
    }

    public function storeType(Request $request): RedirectResponse
    {
        CertificationType::create($request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:certification_types,code'], 'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'], 'expires' => ['required', 'boolean'],
        ]) + ['sort_order' => (int) CertificationType::max('sort_order') + 10]);
        Inertia::flash('success', 'Jenis sertifikat ditambahkan.');

        return back();
    }

    public function export(Request $request): StreamedResponse
    {
        $today = CarbonImmutable::today();
        $rows = EmployeeCertification::with(['employee.position', 'employee.workLocation', 'type'])
            ->whereHas('employee', fn (Builder $q) => $q->where('status', '!=', Employee::STATUS_TERMINATED))->get()
            ->sortBy(fn ($c) => $c->expires_at?->format('Y-m-d') ?? '9999')->values();

        return TableExcel::download('certifications-'.$today->format('Y-m-d').'.xlsx', [[
            'title' => 'Certifications', 'info' => TableExcel::info(['Sertifikasi & Izin Kerja']), 'freeze' => 4,
            'columns' => [['NRP', 12, 'text'], ['Name', 26], ['Position', 24], ['Location', 18], ['Type', 28], ['Number', 20, 'text'], ['Issuer', 22],
                ['Issued', 12, 'date'], ['Expires', 12, 'date'], ['Days Left', 9, 'int'], ['Status', 14]],
            'rows' => $rows->map(fn (EmployeeCertification $c) => [
                $c->employee->employee_number, $c->employee->name, $c->employee->position?->name, $c->employee->workLocation?->name, $c->type->name, $c->number, $c->issuer,
                $c->issued_at?->format('Y-m-d'), $c->expires_at?->format('Y-m-d'), $c->daysLeft($today), EmployeeCertification::STATUS[$c->status($today)],
            ])->all(),
        ]]);
    }

    public static function share(EmployeeCertification $c, CarbonImmutable $today): array
    {
        return [
            'id' => $c->id, 'employee_id' => $c->employee_id, 'employee_number' => $c->employee?->employee_number, 'name' => $c->employee?->name,
            'position' => $c->employee?->position?->name, 'location' => $c->employee?->workLocation?->name,
            'type_id' => $c->certification_type_id, 'type' => $c->type?->name, 'type_code' => $c->type?->code,
            'number' => $c->number, 'issuer' => $c->issuer, 'issued_at' => $c->issued_at?->format('Y-m-d'), 'expires_at' => $c->expires_at?->format('Y-m-d'),
            'notes' => $c->notes, 'days_left' => $c->daysLeft($today), 'status' => $c->status($today),
            'attachments' => $c->attachments->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'mime' => $a->mime, 'size' => (int) $a->size, 'page_count' => (int) $a->page_count, 'url' => "/attachment/{$a->id}"])->values(),
        ];
    }

    private function validated(Request $request): array
    {
        $request->validate(Attachments::rules(), Attachments::messages());

        return $request->validate([
            'employee_id' => ['required', 'integer'],
            'certification_type_id' => ['required', 'integer', 'exists:certification_types,id'],
            'number' => ['nullable', 'string', 'max:80'],
            'issuer' => ['nullable', 'string', 'max:120'],
            'issued_at' => ['nullable', 'date_format:Y-m-d'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:issued_at'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], ['expires_at.after_or_equal' => 'Tanggal berlaku sampai harus sesudah tanggal terbit.'], ['certification_type_id' => 'Jenis']);
    }
}
