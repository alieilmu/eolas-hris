<?php

namespace App\Support\Payroll;

use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Models\Payslip;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employee loans: the schedule of monthly installments once approved, a month put off, paying
 * the rest at once (through a payroll period or outside pay), and the cap on what installments
 * may take from the pay.
 */
class Loans
{
    /** "2026-11" + n months. */
    public static function addMonths(string $period, int $n): string
    {
        [$y, $m] = array_map('intval', explode('-', $period));
        $i = $y * 12 + ($m - 1) + $n;

        return sprintf('%04d-%02d', intdiv($i, 12), $i % 12 + 1);
    }

    /** Equal installments, the last one taking the rest. */
    public static function amounts(int $principal, int $tenor): array
    {
        $each = (int) ceil($principal / max(1, $tenor) / 1000) * 1000;
        $rows = [];
        $left = $principal;
        for ($i = 1; $i <= $tenor && $left > 0; $i++) {
            $rows[] = $i === $tenor ? $left : min($each, $left);
            $left -= end($rows);
        }

        return $rows;
    }

    /** Approved: the installments from the start period on. */
    public static function activate(Loan $loan, int $userId): void
    {
        DB::transaction(function () use ($loan, $userId) {
            $loan->installments()->delete();
            foreach (self::amounts($loan->principal, $loan->tenor) as $i => $amount) {
                $loan->installments()->create(['sequence' => $i + 1, 'period' => self::addMonths($loan->start_period, $i), 'amount' => $amount]);
            }
            $loan->update(['status' => 'active', 'approved_by' => $userId, 'approved_at' => now(), 'rejection_note' => null]);
        });
    }

    /** One installment and every later one move a month on. */
    public static function postpone(LoanInstallment $installment): void
    {
        self::assertOpen($installment);
        DB::transaction(function () use ($installment) {
            $later = LoanInstallment::where('loan_id', $installment->loan_id)->where('status', 'scheduled')
                ->where('sequence', '>=', $installment->sequence)->orderByDesc('sequence')->get();
            foreach ($later as $i) {
                $i->update(['period' => self::addMonths($i->period, 1), 'notes' => $i->id === $installment->id ? 'Ditunda 1 bulan' : $i->notes]);
            }
        });
    }

    /** Everything left is deducted in one period. */
    public static function payOffIn(Loan $loan, string $period): void
    {
        $left = $loan->installments()->where('status', 'scheduled')->get();
        abort_if($left->isEmpty(), 422, 'Tidak ada angsuran tersisa.');
        DB::transaction(function () use ($left, $period) {
            $first = $left->first();
            $first->update(['period' => $period, 'amount' => (int) $left->sum('amount'), 'notes' => 'Pelunasan sisa pinjaman']);
            LoanInstallment::whereIn('id', $left->skip(1)->pluck('id'))->delete();
        });
    }

    /** Paid in cash or by transfer: what is left is settled, the loan paid off. */
    public static function settle(Loan $loan, ?string $note): void
    {
        DB::transaction(function () use ($loan, $note) {
            $loan->installments()->where('status', 'scheduled')->update(['status' => 'settled', 'notes' => $note ?: 'Dilunasi di luar gaji']);
            $loan->update(['status' => 'paid_off']);
        });
    }

    /**
     * Installments may take at most loan_max_ratio % of the take home pay before loans: the
     * latest approved slip, else the fixed pay.
     */
    public static function assertAffordable(Employee $k, int $installment, ?Loan $except = null): void
    {
        $ratio = PayrollSetting::current()->loan_max_ratio;
        $slip = Payslip::where('employee_id', $k->id)->whereNull('error')
            ->whereHas('run', fn ($q) => $q->whereIn('status', PayrollRun::FINAL))->latest('id')->first();
        $pay = $slip
            ? $slip->take_home_pay + (int) $slip->lines()->where('code', 'loan')->sum('amount')
            : (Salary::fixedPay($k) ?? 0);
        $running = (int) Loan::where('employee_id', $k->id)->where('status', 'active')->when($except, fn ($q) => $q->whereKeyNot($except->id))->sum('installment');
        $cap = (int) floor($pay * $ratio / 100);
        if ($pay > 0 && $installment + $running > $cap) {
            throw ValidationException::withMessages(['tenor' => sprintf(
                'Angsuran Rp %s%s melebihi batas %d%% dari gaji bersih (Rp %s). Perpanjang tenor atau kecilkan pinjaman.',
                number_format($installment, 0, ',', '.'), $running ? ' + angsuran berjalan Rp '.number_format($running, 0, ',', '.') : '',
                $ratio, number_format($cap, 0, ',', '.'))]);
        }
    }

    private static function assertOpen(LoanInstallment $i): void
    {
        abort_unless($i->status === 'scheduled' && $i->loan->status === 'active', 422, 'Angsuran ini sudah dipotong atau dilunasi.');
    }
}
