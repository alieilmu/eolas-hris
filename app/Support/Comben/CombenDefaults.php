<?php

namespace App\Support\Comben;

use Illuminate\Support\Facades\DB;

/**
 * The Comben master data as first given by HR: golongan per group, Struktur
 * Skala Upah (2025, and 2026 for site RMTU), Plafon Pengobatan and Plafon
 * Kacamata per layer. Edited afterwards in Master Data Comben.
 */
class CombenDefaults
{
    /** Fills the Comben master data, once: nothing happens when golongan already exist. */
    public static function seed(): void
    {
        Entitlement::flush();
        if (DB::table('golongans')->exists()) {
            return;
        }
        $now = now();
        $golongan = [
            'NONOM' => ['1A', '1B', '1C', '1D', '1E', '2A', '2B', '2C', '2D', '2E'],
            'OPERATOR' => ['APP', 'D0', 'D1J', 'D1S', 'D2J', 'D2S', 'D3J', 'D3S', 'D4J', 'D4S', 'D5J', 'D5S'],
            'MEKANIK' => ['G0', 'G1J', 'G1S', 'G2J', 'G2S', 'G3J', 'G3S', 'G4J', 'G4S', 'G5J', 'G5S', 'G6J', 'G6S'],
            'WELDER' => ['W0', 'W1J', 'W1S', 'W2J', 'W2S', 'W3J', 'W3S', 'W4J', 'W4S', 'W5J', 'W5S', 'W6J', 'W6S'],
            'STAFF' => ['3A', '3B', '3C', '3D', '3E', '4A', '4B', '4C', '4D', '4E', '5A', '5B', '5C', '5D', '5E'],
        ];
        $urutan = 0;
        foreach ($golongan as $grup => $codes) {
            foreach ($codes as $kode) {
                DB::table('golongans')->insert(['grup' => $grup, 'kode' => $kode, 'urutan' => $urutan++, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $id = DB::table('golongans')->pluck('id', 'kode');

        // Struktur Skala Upah: [golongan, step, 2025, 2026 site RMTU].
        $k2025 = DB::table('skala_upah_kolom')->insertGetId(['tahun' => 2025, 'work_location_id' => null, 'urutan' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $rmtu = DB::table('work_locations')->where('name', 'RMTU')->orWhere('kode', 'RMTU')->value('id');
        $k2026 = DB::table('skala_upah_kolom')->insertGetId(['tahun' => 2026, 'work_location_id' => $rmtu, 'urutan' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $nonom = [
            ['1A', 'Step 1', 3850000, 4067140], ['1A', 'Step 2', 3851471, 4068611], ['1B', 'Step 3', 3852941, 4070081],
            ['1C', 'Step 4', 3854412, 4071552], ['1C', 'Step 5', 3855882, 4073022], ['1D', 'Step 6', 3857353, 4074493],
            ['1E', 'Step 7', 3858824, 4075964], ['1E', 'Step 8', 3860294, 4077434], ['2A', 'Step 9', 3861765, 4078905],
            ['2A', 'MID', 3862500, 4079640], ['2A', 'Step 10', 3863235, 4080375], ['2B', 'Step 11', 3864706, 4081846],
            ['2B', 'Step 12', 3866176, 4083316], ['2C', 'Step 13', 3867647, 4084787], ['2C', 'Step 14', 3869118, 4086258],
            ['2D', 'Step 15', 3870588, 4087728], ['2D', 'Step 16', 3872059, 4089199], ['2E', 'Step 17', 3873529, 4090669],
            ['2E', 'Step 18', 3875000, 4092140],
        ];
        $operator = [
            ['APP', 3952233, 4095936], ['D0', 3953233, 4096936], ['D1J', 3955233, 4098936], ['D1S', 3956233, 4099936],
            ['D2J', 3959233, 4102936], ['D2S', 3960233, 4103936], ['D3J', 3964233, 4107936], ['D3S', 3965233, 4108936],
            ['D4J', 3970233, 4113936], ['D4S', 3971233, 4114936], ['D5J', 3986233, 4129936], ['D5S', 3996233, 4139936],
        ];
        $mekanik = [
            ['0', 3952233, 4095936], ['1J', 3991057, 4134760], ['1S', 4029881, 4173584], ['2J', 4068705, 4212408],
            ['2S', 4107529, 4251232], ['3J', 4146353, 4290056], ['3S', 4185177, 4328880], ['4J', 4224001, 4367704],
            ['4S', 4262825, 4406528], ['5J', 4301649, 4445352], ['5S', 4340473, 4484176], ['6J', 4379296, 4522999],
            ['6S', 4418120, 4561823],
        ];
        $rows = [
            ...$nonom,
            ...array_map(fn ($r) => [$r[0], null, $r[1], $r[2]], $operator),
            ...array_map(fn ($r) => ['G'.$r[0], null, $r[1], $r[2]], $mekanik),
            ...array_map(fn ($r) => ['W'.$r[0], null, $r[1], $r[2]], $mekanik),
            ...array_map(fn ($kode) => [$kode, null, null, null], $golongan['STAFF']),
        ];
        foreach ($rows as $i => [$kode, $step, $v2025, $v2026]) {
            $skala = DB::table('skala_upahs')->insertGetId(['golongan_id' => $id[$kode], 'step' => $step, 'urutan' => $i, 'created_at' => $now, 'updated_at' => $now]);
            foreach ([$k2025 => $v2025, $k2026 => $v2026] as $kolom => $nominal) {
                if ($nominal !== null) {
                    DB::table('skala_upah_nilai')->insert(['skala_upah_id' => $skala, 'kolom_id' => $kolom, 'nominal' => $nominal]);
                }
            }
        }

        // Plafon rows: [layer, golongan codes, SubGroups, jabatan HO, jabatan Site, pengobatan lajang, keluarga, kacamata bingkai, lensa].
        $range = function (string $from, string $to) use ($golongan): array {
            $all = array_merge(...array_values($golongan));

            return array_slice($all, array_search($from, $all, true), array_search($to, $all, true) - array_search($from, $all, true) + 1);
        };
        $plafon = [
            ['L1', [...$golongan['NONOM'], ...$golongan['OPERATOR'], ...$golongan['MEKANIK'], ...$golongan['WELDER']], ['Nonom', 'Operator', 'Mekanik', 'Welder'], 'Non-Staff', 'Nonom / WOM', 1750000, 2250000, 625000, 625000],
            ['L2', $range('3A', '3E'), ['Junior Staff', 'FGDP'], 'Junior Officer', 'Junior Group Leader', 2500000, 3750000, 800000, 800000],
            ['L2', $range('4A', '4C'), ['Officer HO/ Group Leader'], 'Officer', 'Group Leader', 3250000, 5250000, 1000000, 1000000],
            ['L3', $range('4B', '4D'), ['Section Head Site'], null, 'Site Section Head', 5800000, 5800000, 1125000, 1125000],
            ['L3', $range('4C', '4E'), ['Section Head HO', 'Departement Head'], 'HO Section Head', 'Site Department Head', 7550000, 7550000, 1250000, 1250000],
            ['L4', $range('4E', '5B'), ['Manager', 'Deputy Project Manager', 'Project Manager'], 'HO Department Head', 'Deputy Project Manager / Project Manager', 9550000, 9550000, 1500000, 1500000],
            ['L5', $range('5A', '5D'), ['General Manager'], 'Division Head', null, 11000000, 11000000, 2000000, 2000000],
        ];
        $subGroups = DB::table('sub_groups')->pluck('id', 'nama');
        foreach ($plafon as $i => [$layer, $codes, $groups, $ho, $site, $lajang, $keluarga, $bingkai, $lensa]) {
            $common = [
                'layer' => $layer,
                'golongan_ids' => json_encode(array_map(fn ($k) => $id[$k], $codes)),
                'sub_group_ids' => json_encode(array_values(array_filter(array_map(fn ($g) => $subGroups[$g] ?? null, $groups)))),
                'jabatan_ho' => $ho,
                'jabatan_site' => $site,
                'urutan' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            DB::table('plafon_pengobatans')->insert($common + ['lajang' => $lajang, 'keluarga' => $keluarga]);
            DB::table('plafon_kacamatas')->insert($common + ['bingkai' => $bingkai, 'lensa' => $lensa]);
        }
    }
}
