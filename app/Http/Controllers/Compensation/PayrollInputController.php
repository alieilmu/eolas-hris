<?php

namespace App\Http\Controllers\Compensation;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollComponent;
use App\Models\PayrollInput;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Support\Audit\Audit;
use App\Support\Payroll\PayrollInputExcel;
use App\Support\Payroll\PayrollPeriod;
use App\Support\Payroll\PayrollPeriods;
use App\Support\Payroll\PayrollSources;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Input Variabel: bonus, insentif, potongan … per employee per period, entered by hand or from
 * Excel ("payroll-inputs"), approved or rejected by "payroll-approval" before Proses Gaji pays
 * them. Who entered an amount does not approve it, except the Super Admin. Employees are those
 * of the account's locations (AccessScope).
 */
class PayrollInputController extends Controller
{
    public function index(Request $request): Response
    {
        $settings = PayrollSetting::current();
        $today = CarbonImmutable::today();
        $current = PayrollPeriod::of($today->year, $today->month, $settings->cutoff_day);
        // This month's pay approved for every location already: open on the next one.
        if (PayrollRun::where('type', 'regular')->where('period', $current->code())->whereNull('work_location_id')->whereIn('status', PayrollRun::FINAL)->exists()) {
            $next = $today->addMonthNoOverflow();
            $current = PayrollPeriod::of($next->year, $next->month, $settings->cutoff_day);
        }
        $period = PayrollPeriod::fromCode($request->query('period'), $settings->cutoff_day) ?? $current;
        $periods = [];
        for ($d = $today->subMonths(6)->startOfMonth(); $d->lte($today->addMonths(3)); $d = $d->addMonth()) {
            $p = PayrollPeriod::of($d->year, $d->month, $settings->cutoff_day);
            $periods[] = ['code' => $p->code(), 'label' => $p->label()];
        }
        $employees = Employee::query()->where('status', '!=', Employee::STATUS_TERMINATED)->orderBy('name')->get(['id', 'employee_number', 'name', 'work_location_id']);
        $inputs = PayrollInput::with(['employee:id,employee_number,name,work_location_id', 'component', 'creator:id,name'])
            ->whereIn('employee_id', Employee::query()->select('id'))
            ->where('period', $period->code())->orderByDesc('id')->get();
        $user = $request->user();

        return Inertia::render('Compensation/PayrollInputs', [
            'period' => $period->code(),
            'period_label' => $period->label(),
            'periods' => array_reverse($periods),
            'inputs' => $inputs->map(fn (PayrollInput $i) => [
                'id' => $i->id, 'employee_id' => $i->employee_id, 'employee_number' => $i->employee?->employee_number, 'name' => $i->employee?->name,
                'component_id' => $i->payroll_component_id, 'component' => $i->component->name, 'kind' => $i->component->kind,
                'amount' => (int) $i->amount, 'notes' => $i->notes, 'status' => $i->status, 'status_label' => PayrollInput::STATUS[$i->status],
                'source' => $i->source, 'created_by' => $i->creator?->name, 'own' => $i->created_by === $user->id, 'locked' => $i->locked(),
                'rejection_note' => $i->rejection_note,
            ]),
            'employees' => $employees->map(fn (Employee $e) => ['id' => $e->id, 'label' => "{$e->employee_number} · {$e->name}"]),
            'components' => PayrollComponent::where('is_active', true)->orderBy('sort_order')->get(['id', 'code', 'name', 'kind', 'taxable']),
            'can' => [
                'edit' => $user->canEdit('payroll-inputs'),
                'approve' => $user->canEdit('payroll-approval'),
                'approve_own' => $user->isAdmin(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $employee = Employee::findOrFail($data['employee_id']); // scoped
        $this->assertOpen($employee, $data['period']);
        $input = PayrollInput::create($data + ['status' => 'pending', 'source' => 'manual', 'created_by' => $request->user()->id]);
        Audit::log('payroll.input_created', $input, "{$employee->employee_number} {$employee->name} · {$input->component->name} Rp ".number_format($input->amount, 0, ',', '.')." · {$input->period}");
        Inertia::flash('success', 'Input disimpan, menunggu persetujuan.');

        return back();
    }

    public function update(Request $request, PayrollInput $input): RedirectResponse
    {
        $this->assertVisible($input);
        abort_unless($input->status !== 'approved' && ! $input->locked(), 422, 'Input yang sudah disetujui tidak bisa diubah.');
        $data = $this->validated($request);
        $employee = Employee::findOrFail($data['employee_id']);
        $this->assertOpen($employee, $data['period']);
        $input->update($data + ['status' => 'pending', 'rejection_note' => null]);
        Inertia::flash('success', 'Input diperbarui.');

        return back();
    }

    public function destroy(PayrollInput $input): RedirectResponse
    {
        $this->assertVisible($input);
        abort_if($input->locked(), 422, 'Input ini sudah dibayar lewat proses gaji.');
        abort_if($input->status === 'approved' && PayrollPeriods::closed($input->employee, $input->period), 422);
        Audit::log('payroll.input_deleted', $input, "{$input->employee->employee_number} · {$input->component->name} Rp ".number_format($input->amount, 0, ',', '.'));
        $input->delete();
        Inertia::flash('success', 'Input dihapus.');

        return back();
    }

    /** Approves or rejects the inputs ticked (waiting ones only). */
    public function decide(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer'],
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'required_if:decision,rejected', 'string', 'max:255'],
        ], ['note.required_if' => 'Tulis alasan penolakan.']);
        $user = $request->user();
        $inputs = PayrollInput::with('employee', 'component')->whereIn('id', $data['ids'])->where('status', 'pending')
            ->whereIn('employee_id', Employee::query()->select('id'))->get();
        $own = $inputs->filter(fn (PayrollInput $i) => $i->created_by === $user->id && ! $user->isAdmin());
        if ($own->isNotEmpty()) {
            throw ValidationException::withMessages(['ids' => "{$own->count()} input Anda buat sendiri; persetujuannya oleh akun lain."]);
        }
        DB::transaction(function () use ($inputs, $data, $user) {
            foreach ($inputs as $i) {
                $i->update($data['decision'] === 'approved'
                    ? ['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'rejection_note' => null]
                    : ['status' => 'rejected', 'rejection_note' => $data['note']]);
            }
        });
        Audit::log($data['decision'] === 'approved' ? 'payroll.inputs_approved' : 'payroll.inputs_rejected', null,
            "{$inputs->count()} input · Rp ".number_format($inputs->sum('amount'), 0, ',', '.'), ['ids' => $inputs->pluck('id')->all()]);
        Inertia::flash('success', $inputs->count().' input '.($data['decision'] === 'approved' ? 'disetujui.' : 'ditolak.'));

        return back();
    }

    /** Deklarasi, Cuti Besar and lumpsum cuti lapangan of the period, as waiting inputs. */
    public function pull(Request $request): RedirectResponse
    {
        $period = PayrollPeriod::fromCode((string) $request->input('period'), PayrollSetting::current()->cutoff_day);
        abort_unless($period, 422);
        $n = PayrollSources::pull($period, $request->user()->id);
        $made = $n['claims'] + $n['long_service'] + $n['field_leave'];
        Audit::log('payroll.inputs_pulled', null, "{$period->code()}: {$n['claims']} deklarasi, {$n['long_service']} Cuti Besar, {$n['field_leave']} lumpsum");
        Inertia::flash('success', $made
            ? "Ditarik untuk {$period->label()}: {$n['claims']} deklarasi, {$n['long_service']} Cuti Besar, {$n['field_leave']} lumpsum cuti lapangan. Menunggu persetujuan."
            : "Tidak ada data baru untuk ditarik di {$period->label()}.");

        return back();
    }

    public function template(Request $request): StreamedResponse
    {
        return PayrollInputExcel::template((string) $request->query('period', now()->format('Y-m')));
    }

    /** Excel rows become waiting inputs of the period; nothing is saved when a row is wrong. */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'file' => ['required', 'file', 'max:5120', 'extensions:xlsx,xls,csv'],
        ], ['file.required' => 'Pilih file Excel.', 'file.extensions' => 'File harus .xlsx, .xls atau .csv.']);
        $period = $request->input('period');
        try {
            $rows = PayrollInputExcel::read($request->file('file'));
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['file' => $e instanceof \InvalidArgumentException ? $e->getMessage() : 'File tidak bisa dibaca. Pakai template .xlsx.']);
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'File tidak berisi baris data.']);
        }
        $employees = Employee::query()->where('status', '!=', Employee::STATUS_TERMINATED)->get(['id', 'employee_number', 'work_location_id'])->keyBy(fn ($e) => (string) $e->employee_number);
        $components = PayrollComponent::where('is_active', true)->get()->keyBy('code');
        $errors = [];
        $create = [];
        foreach ($rows as $r) {
            $e = $employees[$r['employee_number']] ?? null;
            $c = $components[$r['code']] ?? null;
            $amount = is_numeric($r['amount']) ? (int) round((float) $r['amount']) : null;
            $why = match (true) {
                ! $e => "NRP {$r['employee_number']} tidak ditemukan di lokasi Anda",
                ! $c => "kode komponen \"{$r['code']}\" tidak dikenal",
                $amount === null || $amount <= 0 => 'jumlah harus angka lebih dari 0',
                PayrollPeriods::closed($e, $period) => 'gaji periode ini sudah disetujui',
                default => null,
            };
            if ($why) {
                $errors[] = "Baris {$r['row']}: {$why}.";

                continue;
            }
            $create[] = ['employee_id' => $e->id, 'period' => $period, 'payroll_component_id' => $c->id, 'amount' => $amount, 'notes' => $r['notes']];
        }
        if ($errors) {
            // One key per row, so the page lists every row to fix (the first 15).
            $shown = array_slice($errors, 0, 15);
            throw ValidationException::withMessages(array_combine(array_map(fn ($i) => "file.{$i}", array_keys($shown)), $shown));
        }
        DB::transaction(function () use ($create, $request) {
            foreach ($create as $row) {
                PayrollInput::create($row + ['status' => 'pending', 'source' => 'import', 'created_by' => $request->user()->id]);
            }
        });
        Audit::log('payroll.inputs_imported', null, $request->file('file')->getClientOriginalName()." · {$period} · ".count($create).' baris',
            ['total' => array_sum(array_column($create, 'amount'))]);
        Inertia::flash('success', count($create).' input diimpor, menunggu persetujuan.');

        return back();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'employee_id' => ['required', 'integer'],
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'payroll_component_id' => ['required', 'integer', Rule::exists(PayrollComponent::class, 'id')->where('is_active', true)],
            'amount' => ['required', 'integer', 'min:1', 'max:10000000000'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['payroll_component_id' => 'Komponen', 'amount' => 'Jumlah']);
    }

    private function assertOpen(Employee $employee, string $period): void
    {
        if (PayrollPeriods::closed($employee, $period)) {
            throw ValidationException::withMessages(['period' => "Gaji {$employee->name} untuk periode ini sudah disetujui. Masukkan ke periode berikutnya."]);
        }
    }

    private function assertVisible(PayrollInput $input): void
    {
        abort_unless(Employee::whereKey($input->employee_id)->exists(), 404); // scoped
    }
}
