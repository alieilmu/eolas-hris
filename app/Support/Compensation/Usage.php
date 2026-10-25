<?php

namespace App\Support\Compensation;

use App\Models\BenefitClaimItem;
use App\Models\Employee;
use App\Models\OpticalBenefitLimit;
use Illuminate\Support\Facades\DB;

/**
 * How much of each plafon has been claimed. Pengobatan counts perawatan and
 * pengobatan of the plafon year; Kacamata counts lensa of the year and bingkai of
 * the year and the one before (a frame is once in two years), per family member.
 * Approved deklarasi are "terpakai"; those still waiting for HR ("diajukan", from
 * the employee portal) are "menunggu" and hold their amount; rejected ones count
 * for nothing.
 */
class Usage
{
    public const COUNTED = ['approved', 'pending'];

    /**
     * @param  iterable<int>  $karyawanIds
     * @return array<int, array{bulan: array<string, int>, total: int, menunggu: int}>
     */
    public static function medical(iterable $employeeIds, int $year, ?int $exceptDeklarasi = null): array
    {
        $usage = [];
        foreach (self::items($employeeIds, [$year], BenefitClaimItem::PENGOBATAN, $exceptDeklarasi) as $i) {
            $u = $usage[$i->employee_id] ?? ['month' => [], 'total' => 0, 'pending' => 0];
            if ($i->status === 'pending') {
                $u['pending'] += $i->amount;
            } else {
                $u['month'][$i->accounting_month] = ($u['month'][$i->accounting_month] ?? 0) + $i->amount;
                $u['total'] += $i->amount;
            }
            $usage[$i->employee_id] = $u;
        }

        return $usage;
    }

    /**
     * @param  iterable<int>  $karyawanIds
     * @return array<int, array<string, array{bingkai: int, lensa: int, bingkai_menunggu: int, lensa_menunggu: int, bulan: array<string, int>, bingkai_terakhir: ?string}>> karyawan id => member key => usage
     */
    public static function optical(iterable $employeeIds, int $year, ?int $exceptDeklarasi = null): array
    {
        $years = range($year - OpticalBenefitLimit::BINGKAI_TAHUN + 1, $year);
        $usage = [];
        foreach (self::items($employeeIds, $years, BenefitClaimItem::KACAMATA, $exceptDeklarasi) as $i) {
            $key = $i->beneficiary === 'children' ? "children:{$i->employee_child_id}" : $i->beneficiary;
            $u = $usage[$i->employee_id][$key] ?? self::emptyOptical();
            $pending = $i->status === 'pending';
            $thisYear = (int) $i->benefit_year === $year;
            if ($i->type === 'frame') {
                $u[$pending ? 'frame_pending' : 'frame'] += $i->amount;
                $u['last_frame'] = max($u['last_frame'] ?? '', $i->accounting_month);
            } elseif ($thisYear) {
                $u[$pending ? 'lens_pending' : 'lens'] += $i->amount;
            }
            if ($thisYear && ! $pending) {
                $u['month'][$i->accounting_month] = ($u['month'][$i->accounting_month] ?? 0) + $i->amount;
            }
            $usage[$i->employee_id][$key] = $u;
        }

        return $usage;
    }

    public static function emptyOptical(): array
    {
        return ['frame' => 0, 'lens' => 0, 'frame_pending' => 0, 'lens_pending' => 0, 'month' => [], 'last_frame' => null];
    }

    /** Pengobatan and Kacamata of one employee, with plafon and what is left (for the forms and the checks). */
    public static function summary(Employee $k, int $year, ?int $exceptDeklarasi = null): array
    {
        $e = BenefitEntitlement::shared();
        $benefit_limit = $e->medicalBenefitLimit($k);
        $used = self::medical([$k->id], $year, $exceptDeklarasi)[$k->id] ?? ['total' => 0, 'pending' => 0];
        $rule = $e->opticalRule($k);
        $optical = self::optical([$k->id], $year, $exceptDeklarasi)[$k->id] ?? [];
        $allowance = fn (?int $benefit_limit, int $terpakai, int $menunggu, array $extra = []) => [
            'benefit_limit' => $benefit_limit,
            'used' => $terpakai,
            'pending' => $menunggu,
            'remaining' => $benefit_limit === null ? null : $benefit_limit - $terpakai - $menunggu,
        ] + $extra;

        return [
            'year' => $year,
            'ptkp_status' => BenefitEntitlement::statusPtkp($k),
            'family' => BenefitEntitlement::family($k),
            'warning' => $e->warning($k),
            'medical' => ['layer' => $e->medicalRule($k)?->layer] + $allowance($benefit_limit, $used['total'], $used['pending']),
            'optical' => array_map(function (array $m) use ($rule, $optical, $allowance) {
                $u = $optical[$m['key']] ?? self::emptyOptical();

                return $m + [
                    'frame' => $allowance($rule?->frame, $u['frame'], $u['frame_pending'], ['last' => $u['last_frame']]),
                    'lens' => $allowance($rule?->lens, $u['lens'], $u['lens_pending']),
                ];
            }, BenefitEntitlement::members($k)),
        ];
    }

    /** Items of the employees' counted deklarasi in the plafon years, with the deklarasi's month, year and status. */
    private static function items(iterable $employeeIds, array $years, array $type, ?int $exceptDeklarasi)
    {
        return DB::table('benefit_claim_items')
            ->join('benefit_claims', 'benefit_claims.id', '=', 'benefit_claim_items.benefit_claim_id')
            ->whereIn('benefit_claims.employee_id', collect($employeeIds)->all())
            ->whereIn('benefit_claims.benefit_year', $years)
            ->whereIn('benefit_claims.status', self::COUNTED)
            ->whereIn('benefit_claim_items.type', $type)
            ->when($exceptDeklarasi, fn ($q) => $q->where('benefit_claims.id', '!=', $exceptDeklarasi))
            ->get(['benefit_claims.employee_id', 'benefit_claims.accounting_month', 'benefit_claims.benefit_year', 'benefit_claims.status', 'benefit_claim_items.type', 'benefit_claim_items.beneficiary', 'benefit_claim_items.employee_child_id', 'benefit_claim_items.amount']);
    }
}
