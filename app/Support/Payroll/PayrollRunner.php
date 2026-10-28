<?php

namespace App\Support\Payroll;

use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\PayrollInput;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Models\Payslip;
use App\Models\PayslipLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Works out a payroll run: every employee on the payroll in its period (and location) gets a
 * payslip with a snapshot of who they were then, the full calculation and its lines. Slips of
 * an earlier calculation of the same run are replaced.
 */
class PayrollRunner
{
    /** A slip whose take home pay moved more than this against last month is flagged. */
    private const SWING = 0.10;

    /**
     * Who a run pays. Regular: active in the period or leaving within it, joined by its end, and
     * not paid off already by a final pay run of the period. THR: of the holiday's religion, with
     * a full month of service and still employed on the day. Final pay: the employees chosen.
     */
    public static function employees(PayrollRun $run): Builder
    {
        $start = $run->period_start->toDateString();
        $end = $run->period_end->toDateString();
        if ($run->type === 'final') {
            return Employee::query()->whereIn('id', $run->employee_ids ?: [0]);
        }
        if ($run->type === 'thr') {
            $day = ($run->holiday_date ?? $run->period_end)->toDateString();
            $religions = PayrollRun::HOLIDAYS[$run->holiday][1] ?? null;
            $joinedBy = CarbonImmutable::parse($day)->subMonth()->toDateString();

            return Employee::query()
                ->when($run->work_location_id, fn ($q) => $q->where('work_location_id', $run->work_location_id))
                ->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $day))
                ->where('status', '!=', Employee::STATUS_TERMINATED)
                ->whereHas('profile', fn ($q) => $q->where(fn ($q) => $q->whereNull('hire_date')->orWhere('hire_date', '<=', $joinedBy))
                    ->when($religions, fn ($q) => $q->whereIn('religion', $religions)));
        }
        $finalled = PayrollRun::where('type', 'final')->where('period', $run->period)->pluck('employee_ids')->flatten()->filter()->all();

        return Employee::query()
            ->when($finalled, fn ($q) => $q->whereNotIn('id', $finalled))
            ->when($run->work_location_id, fn ($q) => $q->where('work_location_id', $run->work_location_id))
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('status', '!=', Employee::STATUS_TERMINATED)->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $start)))
                ->orWhereBetween('termination_date', [$start, $end]))
            ->where(fn ($q) => $q->whereDoesntHave('profile')->orWhereHas('profile', fn ($q) => $q->whereNull('hire_date')->orWhere('hire_date', '<=', $end)));
    }

    public static function calculate(PayrollRun $run, ?int $userId = null): void
    {
        Salary::flush();
        Pph21::flush();
        $period = $run->periodOf();
        $ids = self::employees($run)->orderBy('employee_number')->pluck('id');
        $run->update(['status' => 'calculating', 'progress' => 0, 'employee_count' => $ids->count(), 'rejection_note' => null]);
        Payslip::where('payroll_run_id', $run->id)->delete();
        $previous = self::previousPay($run, $ids->all());
        $password = (bool) PayrollSetting::current()->payslip_password;

        foreach ($ids->chunk(50) as $n => $chunk) {
            $employees = Employee::with(['profile.children', 'workLocation', 'jobLevel', 'position', 'department', 'salaries.salaryScale.grade', 'payrollProfile'])
                ->whereIn('id', $chunk)->get()->keyBy('id');
            DB::transaction(function () use ($chunk, $employees, $period, $run, $previous, $password) {
                foreach ($chunk as $id) {
                    $result = $run->type === 'thr' ? ThrCalculator::for($employees[$id], $run) : PayrollCalculator::month($employees[$id], $period, $run->id);
                    self::store($run, $employees[$id], $result, $run->type === 'regular' ? ($previous[$id] ?? null) : null, $password);
                }
            });
            $run->update(['progress' => min($ids->count(), ($n + 1) * 50)]);
        }

        $run->update([
            'status' => 'calculated', 'progress' => $ids->count(), 'totals' => self::totals($run),
            'calculated_by' => $userId, 'calculated_at' => now(),
        ]);
    }

    private static function store(PayrollRun $run, Employee $k, array $r, ?int $before, bool $password = false): void
    {
        $p = $k->profile;
        $warnings = $r['warnings'];
        if (! isset($r['error'])) {
            if (blank($p?->bank_account_number)) {
                $warnings[] = 'Nomor rekening kosong: tidak bisa masuk file transfer bank.';
            }
            if ($password && blank($p?->birth_date)) {
                $warnings[] = 'Tanggal lahir kosong: PDF slip di portal tidak berpassword.';
            }
            if (! $r['has_tax_id']) {
                $warnings[] = 'NPWP/NIK kosong: PPh21 dikenakan 20% lebih tinggi.';
            }
            if ($before && abs($r['take_home_pay'] - $before) > $before * self::SWING) {
                $warnings[] = sprintf('Take home pay %s %d%% dibanding bulan lalu (Rp %s).', $r['take_home_pay'] > $before ? 'naik' : 'turun',
                    (int) round(abs($r['take_home_pay'] - $before) / $before * 100), number_format($before, 0, ',', '.'));
            }
        }
        $company = isset($r['company']) ? array_sum(array_column($r['company'], 'amount')) : 0;

        $slip = Payslip::create([
            'payroll_run_id' => $run->id, 'employee_id' => $k->id, 'work_location_id' => $k->work_location_id,
            'employee_number' => $k->employee_number, 'name' => $k->name,
            'snapshot' => [
                'position' => $k->position?->name, 'job_level' => $k->jobLevel?->name, 'department' => $k->department?->name,
                'location' => $k->workLocation?->name, 'ptkp' => $r['ptkp'], 'ter_category' => $r['ter_category'], 'has_tax_id' => $r['has_tax_id'],
                'tax_method' => $r['tax_method'], 'mode' => $r['mode'], 'tax_id_number' => $p?->tax_id_number, 'national_id_number' => $p?->national_id_number,
                'bank_name' => $p?->bank_name, 'bank_account_number' => $p?->bank_account_number, 'bank_account_holder' => $p?->bank_account_holder,
                'hire_date' => $p?->hire_date ? substr((string) $p->hire_date, 0, 10) : null,
                'termination_date' => $k->termination_date?->toDateString(),
                'bpjs_employment_number' => $p?->bpjs_employment_number, 'bpjs_health_number' => $p?->bpjs_health_number,
            ],
            'wage' => $r['wage'] ?? 0, 'gross' => $r['gross'] ?? 0,
            'total_earnings' => $r['total_earnings'] ?? 0, 'total_deductions' => $r['total_deductions'] ?? 0,
            'tax' => $r['tax'] ?? 0, 'tax_withheld' => $r['tax_withheld'] ?? 0, 'employee_contributions' => $r['employee_contributions'] ?? 0,
            'company_cost' => $company, 'take_home_pay' => $r['take_home_pay'] ?? 0, 'prorate' => $r['prorate']['factor'] ?? 1,
            'error' => $r['error'] ?? null, 'warnings' => $warnings,
            'detail' => collect($r)->only(['period', 'earnings', 'deductions', 'company', 'tax_steps', 'absence', 'overtime', 'prorate'])->all(),
        ]);

        $rows = [];
        $sort = 0;
        foreach (['earning' => 'earnings', 'deduction' => 'deductions', 'company' => 'company'] as $kind => $key) {
            foreach ($r[$key] ?? [] as $l) {
                if (! $l['amount']) {
                    continue;
                }
                $rows[] = ['payslip_id' => $slip->id, 'kind' => $kind, 'code' => $l['code'] ?? 'other', 'label' => $l['label'],
                    'amount' => $l['amount'], 'note' => isset($l['note']) ? mb_substr((string) $l['note'], 0, 150) : null, 'sort' => $sort++, 'ref' => $l['ref'] ?? null];
            }
        }
        PayslipLine::insert($rows);
    }

    /**
     * An approved run settles what it paid: its Input Variabel are marked paid and its loan
     * installments deducted (a loan with nothing left is paid off).
     */
    public static function settle(PayrollRun $run): void
    {
        $lines = PayslipLine::whereIn('payslip_id', Payslip::where('payroll_run_id', $run->id)->select('id'))
            ->whereNotNull('ref')->get(['payslip_id', 'ref']);
        DB::transaction(function () use ($lines) {
            foreach ($lines as $l) {
                [$type, $id] = explode(':', $l->ref) + [null, null];
                if ($type === 'input') {
                    PayrollInput::whereKey((int) $id)->update(['payslip_id' => $l->payslip_id]);
                } elseif ($type === 'installment') {
                    LoanInstallment::whereKey((int) $id)->where('status', 'scheduled')->update(['status' => 'deducted', 'payslip_id' => $l->payslip_id]);
                }
            }
            $loans = LoanInstallment::whereIn('id', $lines->filter(fn ($l) => str_starts_with($l->ref, 'installment:'))->map(fn ($l) => (int) substr($l->ref, 12)))->pluck('loan_id')->unique();
            foreach (Loan::whereIn('id', $loans)->where('status', 'active')->get() as $loan) {
                if (! $loan->installments()->where('status', 'scheduled')->exists()) {
                    $loan->update(['status' => 'paid_off']);
                }
            }
        });
    }

    /** Take home pay of each employee in the latest earlier approved run. @return array<int, int> */
    private static function previousPay(PayrollRun $run, array $ids): array
    {
        $last = PayrollRun::where('type', 'regular')->whereIn('status', PayrollRun::FINAL)->where('period', '<', $run->period)->max('period');
        if (! $last) {
            return [];
        }

        return Payslip::whereIn('employee_id', $ids)->whereNull('error')
            ->whereHas('run', fn ($q) => $q->where('period', $last)->whereIn('status', PayrollRun::FINAL))
            ->pluck('take_home_pay', 'employee_id')->map(fn ($v) => (int) $v)->all();
    }

    /** Sums of the run, as a whole and per work location. */
    public static function totals(PayrollRun $run): array
    {
        $slips = Payslip::where('payroll_run_id', $run->id)
            ->get(['work_location_id', 'error', 'warnings', 'total_earnings', 'total_deductions', 'tax', 'company_cost', 'take_home_pay']);
        $sum = fn ($rows) => [
            'employees' => $rows->count(),
            'errors' => $rows->whereNotNull('error')->count(),
            'warnings' => $rows->whereNull('error')->filter(fn ($s) => ! empty($s->warnings))->count(),
            'total_earnings' => (int) $rows->sum('total_earnings'),
            'total_deductions' => (int) $rows->sum('total_deductions'),
            'tax' => (int) $rows->sum('tax'),
            'company_cost' => (int) $rows->sum('company_cost'),
            'take_home_pay' => (int) $rows->sum('take_home_pay'),
        ];

        return ['all' => $sum($slips), 'locations' => $slips->groupBy(fn ($s) => (string) ($s->work_location_id ?? 0))->map($sum)->all()];
    }
}
