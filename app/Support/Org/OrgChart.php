<?php

namespace App\Support\Org;

use App\Models\Bod;
use App\Models\Jabatan;

/** The organisation units — BOD → Divisi → Departemen → Section — as one list for pickers. */
class OrgChart
{
    /**
     * Every unit for a picker, top-down, each indented under its parent.
     *
     * @return list<array{value: string, label: string, display: string, level: string, name: string}>
     */
    public static function unitOptions(): array
    {
        $options = [];
        $add = function (string $level, $model, int $depth, ?string $within = null) use (&$options) {
            $name = Jabatan::UNIT_LABELS[$level].' · '.$model->name;
            $options[] = [
                'value' => "{$level}:{$model->id}",
                'label' => str_repeat("\u{00A0}\u{00A0}\u{00A0}", $depth).$name,
                // Standalone text, e.g. in a table: sections carry their departemen.
                'display' => $within ? "{$name} ({$within})" : $name,
                'level' => $level,
                'name' => $model->name,
            ];
        };

        foreach (Bod::with('divisis.departemens.sections')->orderBy('order')->get() as $b) {
            $add('bod', $b, 0);
            foreach ($b->divisis as $dv) {
                $add('divisi', $dv, 1);
                foreach ($dv->departemens as $d) {
                    $add('departemen', $d, 2);
                    foreach ($d->sections as $s) {
                        $add('section', $s, 3, $d->name);
                    }
                }
            }
        }

        return $options;
    }
}
