<?php

namespace App\Support\Setup;

use App\Models\Directorate;
use App\Models\JobLevel;
use Illuminate\Validation\ValidationException;

/**
 * The organisation typed as an indented outline, one unit per line:
 *
 *   Direktur Utama            ← Direktorat (BOD)
 *     Operasional             ← Divisi
 *       Produksi              ← Departemen
 *         Cutting             ← Section
 *
 * Indentation (spaces or tabs, any width) sets the level. Units that already exist under the
 * same parent are kept, so the outline can be pasted again with additions.
 */
class OrganizationOutline
{
    public const LEVELS = ['directorate', 'division', 'department', 'section'];

    /** Generic SubGroups for a company starting from scratch: name => [layer, source, title prefix]. */
    public const STANDARD_JOB_LEVELS = [
        'Direktur' => [6, 'directorate', 'DIREKTUR'],
        'General Manager' => [5, 'division', 'GENERAL MANAGER'],
        'Manager' => [4, 'department', 'MANAGER'],
        'Supervisor' => [3, 'section', 'SUPERVISOR'],
        'Staff' => [2, 'discipline', 'STAFF'],
        'Pelaksana' => [1, 'discipline', null],
    ];

    /**
     * @return list<array{level: int, name: string, line: int}>
     */
    public static function parse(string $outline): array
    {
        $rows = [];
        $indents = [];
        foreach (preg_split('/\R/', $outline) as $i => $raw) {
            if (trim($raw) === '') {
                continue;
            }
            $indent = strlen(str_replace("\t", '    ', $raw)) - strlen(ltrim(str_replace("\t", '    ', $raw)));
            // The level is the number of distinct indents shallower than this one.
            $indents = array_values(array_filter($indents, fn (int $w) => $w < $indent));
            $level = count($indents);
            $indents[] = $indent;
            $name = trim(preg_replace('/^[-*•]\s*/u', '', trim($raw)));
            if ($level > 3) {
                throw ValidationException::withMessages(['outline' => 'Baris '.($i + 1)." \"{$name}\": paling dalam 4 tingkat (Direktorat → Divisi → Departemen → Section)."]);
            }
            if (mb_strlen($name) > 100) {
                throw ValidationException::withMessages(['outline' => 'Baris '.($i + 1).': nama maksimal 100 karakter.']);
            }
            $rows[] = ['level' => $level, 'name' => $name, 'line' => $i + 1];
        }

        return $rows;
    }

    /** Creates the units of the outline that are not there yet. @return array<string, int> created per level */
    public static function apply(string $outline): array
    {
        $created = array_fill_keys(self::LEVELS, 0);
        $parents = [];
        foreach (self::parse($outline) as ['level' => $level, 'name' => $name, 'line' => $line]) {
            $parent = $level ? ($parents[$level - 1] ?? null) : null;
            if ($level && ! $parent) {
                throw ValidationException::withMessages(['outline' => "Baris {$line} \"{$name}\" menjorok tanpa induk di atasnya."]);
            }
            $query = match ($level) {
                0 => Directorate::query(),
                1 => $parent->divisions(),
                2 => $parent->departments(),
                3 => $parent->sections(),
            };
            $unit = (clone $query)->where('name', $name)->first();
            if (! $unit) {
                $unit = $query->create(['name' => $name, 'sort_order' => (clone $query)->count()]);
                $created[self::LEVELS[$level]]++;
            }
            $parents[$level] = $unit;
            $parents = array_slice($parents, 0, $level + 1, true);
        }

        return $created;
    }

    /** The current organisation as an outline (to edit on). */
    public static function current(): string
    {
        $lines = [];
        foreach (Directorate::with('divisions.departments.sections')->orderBy('sort_order')->get() as $d) {
            $lines[] = $d->name;
            foreach ($d->divisions as $v) {
                $lines[] = '  '.$v->name;
                foreach ($v->departments as $p) {
                    $lines[] = '    '.$p->name;
                    foreach ($p->sections as $s) {
                        $lines[] = '      '.$s->name;
                    }
                }
            }
        }

        return implode("\n", $lines);
    }

    public static function seedStandardJobLevels(): int
    {
        $n = 0;
        foreach (self::STANDARD_JOB_LEVELS as $name => [$layer, $source, $prefix]) {
            $n += JobLevel::firstOrCreate(['name' => $name], ['layer' => $layer, 'source' => $source, 'title_prefix' => $prefix])->wasRecentlyCreated ? 1 : 0;
        }

        return $n;
    }
}
