<?php

namespace App\Support\Payroll;

use App\Models\PayrollRate;
use App\Models\TerRate;

/**
 * PPh21 for permanent employees (PP 58/2023, PMK 168/2023):
 *  - January–November: the monthly TER of the PTKP category × the month's gross;
 *  - December: the year's tax by Pasal 17 (UU HPP) on the PKP, less what January–November withheld.
 * Without NPWP or NIK the tax is 20% higher. Amounts are whole rupiah, rounded down.
 */
class Pph21
{
    /** Pasal 17 layers: [upper bound of the layer, rate %]; null = and above. */
    public const PASAL_17 = [[60_000_000, 5], [250_000_000, 15], [500_000_000, 25], [5_000_000_000, 30], [null, 35]];

    /** @var array<string, list<array{0: int, 1: ?int, 2: float}>>|null */
    private static ?array $brackets = null;

    public static function flush(): void
    {
        self::$brackets = null;
    }

    /** The TER rate (%) of a monthly gross in category A, B or C. */
    public static function terRate(string $category, int $gross): float
    {
        self::$brackets ??= TerRate::orderBy('lower_bound')->get()->groupBy('category')
            ->map(fn ($rows) => $rows->map(fn (TerRate $t) => [$t->lower_bound, $t->upper_bound, $t->rate])->all())->all();
        foreach (self::$brackets[$category] ?? [] as [$lower, $upper, $rate]) {
            if ($gross >= $lower && ($upper === null || $gross <= $upper)) {
                return $rate;
            }
        }

        return 0.0;
    }

    /** January–November: TER × gross. */
    public static function monthly(string $category, int $gross, PayrollRate $r, bool $hasTaxId = true): int
    {
        return self::surcharged((int) floor($gross * self::terRate($category, $gross) / 100), $r, $hasTaxId);
    }

    /** PTKP a year for a status like "K/2". */
    public static function ptkp(string $status, PayrollRate $r): int
    {
        [$kind, $dependants] = array_pad(explode('/', $status), 2, '0');

        return $r->ptkp_base + ($kind === 'K' ? $r->ptkp_married : 0) + (int) $dependants * $r->ptkp_dependant;
    }

    /** Pasal 17 on a PKP. */
    public static function progressive(int $pkp): int
    {
        $tax = 0.0;
        $lower = 0;
        foreach (self::PASAL_17 as [$upper, $rate]) {
            if ($pkp <= $lower) {
                break;
            }
            $tax += (min($pkp, $upper ?? $pkp) - $lower) * $rate / 100;
            $lower = $upper ?? $pkp;
        }

        return (int) floor($tax);
    }

    /**
     * The tax of a whole year (December, or the last month of someone who leaves).
     *
     * @param  int  $gross  gross income of the months worked this year
     * @param  int  $employeeContributions  JHT + JP paid by the employee this year
     * @param  int  $months  months worked this year (caps the biaya jabatan)
     * @param  int  $withheld  PPh21 withheld in the earlier months of the year
     * @return array{gross: int, position_cost: int, contributions: int, net: int, ptkp: int, pkp: int, tax_year: int, withheld: int, tax: int}
     */
    public static function annual(int $gross, int $employeeContributions, int $months, string $ptkpStatus, PayrollRate $r, int $withheld, bool $hasTaxId = true): array
    {
        $positionCost = min((int) round($gross * $r->position_cost_rate / 100), $r->position_cost_monthly_cap * max(1, min(12, $months)));
        $net = $gross - $positionCost - $employeeContributions;
        $ptkp = self::ptkp($ptkpStatus, $r);
        $pkp = max(0, intdiv(max(0, $net - $ptkp), 1000) * 1000);
        $taxYear = self::surcharged(self::progressive($pkp), $r, $hasTaxId);

        return [
            'gross' => $gross, 'position_cost' => $positionCost, 'contributions' => $employeeContributions, 'net' => $net,
            'ptkp' => $ptkp, 'pkp' => $pkp, 'tax_year' => $taxYear, 'withheld' => $withheld, 'tax' => $taxYear - $withheld,
        ];
    }

    /**
     * Gross-up: the tax allowance A for which the tax on (gross + A) is A itself. TER is a step
     * function, so the iteration can swing between two values; the larger one is taken, so the
     * allowance always covers the tax.
     *
     * @param  callable(int): int  $taxOn  the tax of a given gross
     */
    public static function grossUp(int $gross, callable $taxOn): int
    {
        $allowance = 0;
        $seen = [];
        for ($i = 0; $i < 100; $i++) {
            $next = max(0, $taxOn($gross + $allowance));
            if ($next === $allowance) {
                return $allowance;
            }
            if (isset($seen[$next])) {
                return max($next, $allowance);
            }
            $seen[$allowance] = true;
            $allowance = $next;
        }

        return $allowance;
    }

    private static function surcharged(int $tax, PayrollRate $r, bool $hasTaxId): int
    {
        return $hasTaxId ? $tax : (int) floor($tax * (100 + $r->no_tax_id_surcharge) / 100);
    }
}
