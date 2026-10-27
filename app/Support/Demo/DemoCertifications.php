<?php

namespace App\Support\Demo;

use App\Models\CertificationType;
use App\Models\Employee;
use App\Models\EmployeeCertification;
use Carbon\CarbonImmutable;

/**
 * Demo certificates per industry: who holds which (by position), and expiry dates spread so
 * the page shows some expired, some running out within 30 / 90 days and most still valid.
 */
class DemoCertifications
{
    /** industry => [[type code, position keywords (empty: anyone), share of them], …] */
    private const PLAN = [
        'mining' => [['SIMPER', [], 0.6], ['KIMPER', ['OPERATOR', 'DRIVER'], 1.0], ['POP', ['GROUP LEADER', 'SUPERVISOR', 'FOREMAN'], 1.0], ['K3', ['SAFETY', 'HSE'], 1.0]],
        'hospital' => [['STR', ['PERAWAT', 'DOKTER', 'BIDAN', 'APOTEKER', 'ANALIS', 'RADIOGRAFER', 'FISIOTERAPIS', 'NERS'], 1.0], ['SIP', ['PERAWAT', 'DOKTER', 'BIDAN', 'APOTEKER', 'NERS'], 1.0], ['K3', [], 0.05]],
        'construction' => [['SKK', ['ENGINEER', 'PELAKSANA', 'SUPERVISOR', 'MANAGER'], 1.0], ['SIO', ['OPERATOR', 'MEKANIK'], 1.0], ['K3', [], 0.1]],
        'manufacturing' => [['SIO', ['OPERATOR', 'FORKLIFT'], 0.5], ['K3', [], 0.1]],
        'garment' => [['K3', [], 0.1]],
        'fnb' => [['HALAL', ['COOK', 'KITCHEN', 'CHEF', 'QC'], 0.6], ['K3', [], 0.05]],
        'logistics' => [['SIO', ['DRIVER', 'OPERATOR', 'FORKLIFT'], 0.7], ['K3', [], 0.08]],
        'plantation' => [['SIO', ['OPERATOR', 'DRIVER'], 0.6], ['K3', [], 0.08]],
        'retail' => [['K3', [], 0.05]],
        'education' => [['K3', [], 0.05]],
    ];

    public static function seed(string $industry): void
    {
        EmployeeCertification::withoutGlobalScopes()->delete();
        $types = CertificationType::pluck('id', 'code');
        $today = CarbonImmutable::today();
        mt_srand(crc32($industry));
        $employees = Employee::withoutGlobalScopes()->with('position:id,name')->where('status', Employee::STATUS_ACTIVE)->get(['id', 'position_id']);
        $rows = [];
        foreach (self::PLAN[$industry] ?? self::PLAN['mining'] as [$code, $keywords, $share]) {
            if (! isset($types[$code])) {
                continue;
            }
            foreach ($employees as $k) {
                $title = strtoupper((string) $k->position?->name);
                $fits = ! $keywords || collect($keywords)->contains(fn ($w) => str_contains($title, $w));
                if (! $fits || mt_rand() / mt_getrandmax() > $share) {
                    continue;
                }
                // ~6% expired, ~8% within 30 days, ~12% within 90 days, the rest later.
                $roll = mt_rand(1, 100);
                $days = match (true) {
                    $roll <= 6 => -mt_rand(5, 120),
                    $roll <= 14 => mt_rand(1, 30),
                    $roll <= 26 => mt_rand(31, 90),
                    default => mt_rand(91, 900),
                };
                $expires = $today->addDays($days);
                $rows[] = [
                    'employee_id' => $k->id, 'certification_type_id' => $types[$code],
                    'number' => $code.'-'.$today->year.'-'.str_pad((string) mt_rand(1, 99999), 5, '0', STR_PAD_LEFT),
                    'issuer' => self::issuer($code), 'issued_at' => $expires->subYears(in_array($code, ['STR'], true) ? 5 : 2)->toDateString(),
                    'expires_at' => $expires->toDateString(), 'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            EmployeeCertification::insert($chunk);
        }
    }

    private static function issuer(string $code): string
    {
        return match ($code) {
            'STR' => 'Konsil Kesehatan Indonesia', 'SIP' => 'Dinas Kesehatan Kota', 'SIMPER', 'KIMPER' => 'Departemen HSE Site',
            'POP', 'POM' => 'Kementerian ESDM', 'SKK' => 'LPJK', 'SIO', 'K3' => 'Kemnaker RI', 'HALAL' => 'BPJPH', default => 'Lembaga sertifikasi',
        };
    }
}
