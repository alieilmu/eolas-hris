<?php

namespace App\Support\Kehadiran;

use App\Models\Karyawan;
use App\Models\Lembur;
use App\Models\ShiftCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Writes Kehadiran into Absensi and Lembur, for days before today:
 *  - Absensi: a day with clock-ins becomes its roster code (or, on a day off, the work
 *    shift whose start is nearest to CI); a scheduled day without any becomes Mangkir;
 *    an unworked day off stays Off. Days set by hand (manual / import) or by a trip are
 *    left alone; the others are marked sumber "mesin".
 *  - Lembur: overtime of employees entitled to it, waiting for approval. A day already
 *    approved or rejected is kept; a waiting one without overtime any more is removed.
 */
class Proses
{
    private const DIBIARKAN = ['manual', 'import', 'trip'];

    /** @return array{absensi: int, lembur: int} */
    public static function run(CarbonImmutable $dari, CarbonImmutable $sampai, ?array $karyawanIds = null): array
    {
        $sampai = $sampai->min(CarbonImmutable::yesterday());
        if ($sampai->lt($dari)) {
            return ['absensi' => 0, 'lembur' => 0];
        }
        $karyawans = Karyawan::withoutGlobalScope('akses')->with(['workLocation', 'subGroup', 'profile'])
            ->when($karyawanIds !== null, fn ($q) => $q->whereIn('id', $karyawanIds))
            ->where(fn ($q) => $q->where('status', Karyawan::STATUS_AKTIF)->orWhere('tanggal_keluar', '>=', $dari->toDateString()))
            ->get();
        $codes = ShiftCode::orderBy('order')->orderBy('id')->get();
        $mangkir = $codes->firstWhere('jenis', 'mangkir');
        $hadir = $codes->filter(fn (ShiftCode $c) => $c->jenis === 'hadir');
        $byKode = $codes->keyBy('kode');
        $now = now();
        $absensi = 0;
        $lemburCount = 0;

        foreach ($karyawans->chunk(50) as $chunk) {
            $rows = Resume::build($chunk, $dari, $sampai);
            $existing = DB::table('absensis')->whereIn('karyawan_id', $chunk->pluck('id'))
                ->whereBetween('tanggal', [$dari->toDateString(), $sampai->toDateString()])
                ->get(['karyawan_id', 'tanggal', 'sumber'])->keyBy(fn ($r) => $r->karyawan_id.'|'.substr($r->tanggal, 0, 10));
            $lemburs = Lembur::whereIn('karyawan_id', $chunk->pluck('id'))
                ->whereBetween('tanggal', [$dari->toDateString(), $sampai->toDateString()])->get()
                ->keyBy(fn (Lembur $l) => $l->karyawan_id.'|'.$l->tanggal->toDateString());

            $write = [];
            foreach ($rows as $r) {
                $key = $r['karyawan_id'].'|'.$r['tanggal'];
                $sumber = $existing[$key]->sumber ?? null;
                if (! in_array($sumber, self::DIBIARKAN, true) && ! in_array($r['status'], ['dinas', 'cuti', 'sakit', 'izin'], true)) {
                    $code = match ($r['status']) {
                        'lengkap', 'tanpa_co', 'tanpa_ci' => $byKode[$r['roster']] ?? null,
                        'tidak_sesuai' => $r['roster_jenis'] === 'hadir' ? ($byKode[$r['roster']] ?? null) : self::nearest($hadir, $r['ci_at'] ?? $r['co_at']),
                        'tidak_hadir' => $mangkir,
                        'off' => $byKode[$r['roster']] ?? null,
                        default => null,
                    };
                    if ($code) {
                        $write[] = ['karyawan_id' => $r['karyawan_id'], 'tanggal' => $r['tanggal'], 'shift_code_id' => $code->id,
                            'sumber' => $r['status'] === 'off' ? 'roster' : 'mesin', 'trip_id' => null, 'created_at' => $now, 'updated_at' => $now];
                    }
                }

                $l = $lemburs[$key] ?? null;
                $ot = $r['berhak_lembur'] ? $r['lembur'] : null;
                if ($l && $l->status !== 'menunggu') {
                    continue; // decided: kept as it is
                }
                if (! $ot || $ot['menit_lembur'] <= 0) {
                    if ($l && $l->sumber === 'mesin') {
                        $l->delete();
                    }

                    continue;
                }
                Lembur::updateOrCreate(['karyawan_id' => $r['karyawan_id'], 'tanggal' => $r['tanggal']], [
                    'jenis_hari' => $r['jenis_hari'], 'mulai' => $r['ci_at'], 'selesai' => $r['co_at'],
                    'menit_kerja' => $r['menit_kerja'], 'menit_lembur' => $ot['menit_lembur'],
                    'jam_dibayar' => $ot['jam_dibayar'], 'jam_terhitung' => $ot['jam_terhitung'],
                    'status' => 'menunggu', 'sumber' => 'mesin',
                ]);
                $lemburCount++;
            }
            foreach (array_chunk($write, 500) as $part) {
                DB::table('absensis')->upsert($part, ['karyawan_id', 'tanggal'], ['shift_code_id', 'sumber', 'trip_id', 'updated_at']);
            }
            $absensi += count($write);
        }

        return ['absensi' => $absensi, 'lembur' => $lemburCount];
    }

    /** The work shift whose start is nearest to $at (time of day), for a day off worked. */
    private static function nearest($hadir, ?string $at): ?ShiftCode
    {
        if (! $at) {
            return $hadir->first();
        }
        $t = CarbonImmutable::parse($at);
        $minutes = $t->hour * 60 + $t->minute;

        return $hadir->filter(fn (ShiftCode $c) => $c->terjadwal())
            ->sortBy(function (ShiftCode $c) use ($minutes) {
                [$h, $m] = array_map('intval', explode(':', $c->jam_masuk));
                $diff = abs($h * 60 + $m - $minutes);

                return min($diff, 1440 - $diff);
            })->first() ?? $hadir->first();
    }
}
