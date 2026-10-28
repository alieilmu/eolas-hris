<?php

namespace App\Support\Workflow;

use App\Models\JobLevel;
use App\Models\WorkLocation;

/**
 * When a step applies. A step's conditions are a list of rules, all of which must hold:
 * {"field": "amount", "op": ">", "value": 5000000}. A step without conditions always applies.
 * The facts come from the pengajuan (amount, days, category) and the employee (work location,
 * job level), fixed when it starts. A rule on a fact the pengajuan does not have fails.
 */
class Conditions
{
    public const FIELDS = [
        'amount' => 'Nominal',
        'days' => 'Jumlah hari',
        'work_location' => 'Lokasi kerja',
        'category' => 'Jenis cuti / klaim',
        'job_level' => 'Golongan jabatan',
    ];

    public const OPS = ['>' => 'lebih dari', '>=' => 'minimal', '<' => 'kurang dari', '<=' => 'maksimal', 'in' => 'salah satu dari', 'not_in' => 'bukan'];

    public static function pass(?array $conditions, array $facts): bool
    {
        foreach ($conditions ?? [] as $rule) {
            if (! self::holds($rule, $facts)) {
                return false;
            }
        }

        return true;
    }

    private static function holds(array $rule, array $facts): bool
    {
        $fact = $facts[$rule['field'] ?? ''] ?? null;
        if ($fact === null) {
            return false;
        }
        $value = $rule['value'] ?? null;
        $list = array_map('strval', (array) $value);

        return match ($rule['op'] ?? '') {
            '>' => (float) $fact > (float) $value,
            '>=' => (float) $fact >= (float) $value,
            '<' => (float) $fact < (float) $value,
            '<=' => (float) $fact <= (float) $value,
            'in' => in_array((string) $fact, $list, true),
            'not_in' => ! in_array((string) $fact, $list, true),
            default => false,
        };
    }

    /** "Nominal lebih dari Rp 5.000.000 · Lokasi kerja salah satu dari Site A, Site B" */
    public static function describe(?array $conditions): ?string
    {
        if (! $conditions) {
            return null;
        }

        return collect($conditions)->map(function (array $rule) {
            $field = $rule['field'] ?? '';
            $value = $rule['value'] ?? null;
            $shown = match ($field) {
                'amount' => 'Rp '.number_format((float) $value, 0, ',', '.'),
                'days' => "{$value} hari",
                'work_location' => WorkLocation::whereIn('id', (array) $value)->pluck('name')->join(', '),
                'job_level' => JobLevel::whereIn('id', (array) $value)->pluck('name')->join(', '),
                default => implode(', ', (array) $value),
            };

            return (self::FIELDS[$field] ?? $field).' '.(self::OPS[$rule['op'] ?? ''] ?? $rule['op'] ?? '').' '.$shown;
        })->join(' · ');
    }
}
