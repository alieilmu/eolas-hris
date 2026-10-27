<?php

namespace App\Support\Payroll\Reports;

use App\Models\PayrollSetting;

/**
 * Where each payslip line goes: the group of the payroll journal and the line of the
 * 1721-A1. Account numbers are the company's (Pengaturan Kompensasi → Payroll), with a
 * common chart of accounts as the default.
 */
class Accounts
{
    public const GROUPS = [
        'salary_expense' => ['6110', 'Beban Gaji'],
        'allowance_expense' => ['6120', 'Beban Tunjangan'],
        'overtime_expense' => ['6130', 'Beban Lembur'],
        'bonus_expense' => ['6140', 'Beban Bonus, Insentif & THR'],
        'tax_allowance_expense' => ['6150', 'Beban Tunjangan PPh 21'],
        'bpjs_expense' => ['6160', 'Beban BPJS'],
        'tax_expense' => ['6170', 'Beban PPh 21 Ditanggung Perusahaan'],
        'salary_payable' => ['2110', 'Utang Gaji'],
        'tax_payable' => ['2120', 'Utang PPh Pasal 21'],
        'bpjs_payable' => ['2130', 'Utang BPJS'],
        'loan_receivable' => ['1140', 'Piutang Karyawan'],
        'other_payable' => ['2140', 'Utang Potongan Karyawan'],
    ];

    private const EARNING_GROUP = [
        'base_salary' => 'salary_expense', 'absence_deduction' => 'salary_expense',
        'overtime' => 'overtime_expense',
        'bonus' => 'bonus_expense', 'incentive' => 'bonus_expense', 'commission' => 'bonus_expense', 'thr' => 'bonus_expense',
        'tax_allowance' => 'tax_allowance_expense',
    ];

    /** @return array<string, array{0: string, 1: string}> group => [number, name] */
    public static function chart(): array
    {
        $own = PayrollSetting::current()->journal_accounts ?? [];
        $chart = [];
        foreach (self::GROUPS as $group => [$number, $name]) {
            $chart[$group] = [(string) ($own[$group]['number'] ?? $number), (string) ($own[$group]['name'] ?? $name)];
        }

        return $chart;
    }

    /** The debit (expense) group of an earning or company line, by its code. */
    public static function expenseGroup(string $kind, string $code): string
    {
        if ($kind === 'company') {
            return $code === 'pph21_company' ? 'tax_expense' : 'bpjs_expense';
        }

        return self::EARNING_GROUP[$code] ?? 'allowance_expense';
    }

    /** The credit group of a deduction or company line. */
    public static function creditGroup(string $kind, string $code): string
    {
        return match (true) {
            $code === 'pph21' || $code === 'pph21_company' => 'tax_payable',
            str_starts_with($code, 'bpjs') => 'bpjs_payable',
            $code === 'loan' => 'loan_receivable',
            default => 'other_payable',
        };
    }
}
