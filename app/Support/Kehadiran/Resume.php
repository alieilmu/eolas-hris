<?php

namespace App\Support\Kehadiran;

use App\Models\AbsenLog;
use App\Models\Absensi;
use App\Models\Holiday;
use App\Models\Karyawan;
use App\Models\LemburAturan;
use App\Models\Roster;
use App\Models\ShiftCode;
use App\Models\Trip;
use App\Models\WorkLocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kehadiran per employee per day, from the machines' clock in / clock out (CI/CO) set
 * against the Roster:
 *
 *  1. Every scheduled work day (a roster code with hours) takes the logs in its window,
 *     from 4 hours before the start to 6 hours after the end (never into the next or
 *     previous scheduled shift): the earliest is CI, the latest CO. A single log is CI
 *     or CO by whichever end of the shift it is closer to. A night shift's CO is on the
 *     next calendar day and still belongs to the night it started.
 *  2. Days without a schedule (OFF, no roster) take the logs left over between 04:00
 *     that day and 04:00 the next: working on them does not match the roster.
 *
 * Effective working time runs from CI (or the scheduled start, when work before it does
 * not count) to CO, minus the break; on a day without a schedule an hour is taken off
 * when the span is over 5 hours. Overtime follows LemburHitung.
 */
class Resume
{
    public const STATUS = [
        'lengkap' => 'Lengkap',
        'tanpa_co' => 'Tidak ada CO',
        'tanpa_ci' => 'Tidak ada CI',
        'tidak_hadir' => 'Tidak hadir',
        'tidak_sesuai' => 'Tidak sesuai roster',
        'off' => 'Off',
        'tanpa_roster' => 'Tanpa roster',
        'dinas' => 'Dinas',
        'cuti' => 'Cuti',
        'sakit' => 'Sakit',
        'izin' => 'Izin',
    ];

    /** Statuses that need HR's attention. */
    public const MASALAH = ['tanpa_co', 'tanpa_ci', 'tidak_hadir', 'tidak_sesuai'];

    private const SEBELUM_MENIT = 240;

    private const SESUDAH_MENIT = 360;

    /** CI this far from the scheduled start means another shift was worked. */
    private const BEDA_SHIFT_MENIT = 180;

    /**
     * @param  Collection<int, Karyawan>  $karyawans  with workLocation and subGroup
     * @return list<array<string, mixed>>
     */
    public static function build(Collection $karyawans, CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        if ($karyawans->isEmpty()) {
            return [];
        }
        $ids = $karyawans->pluck('id')->all();
        $from = $dari->subDay();
        $to = $sampai->addDay();
        $codes = ShiftCode::all()->keyBy('id');
        $aturan = LemburAturan::current();
        $holidays = Holiday::whereBetween('tanggal', [$from->toDateString(), $to->toDateString()])->pluck('nama', 'tanggal')
            ->mapWithKeys(fn ($nama, $tgl) => [CarbonImmutable::parse($tgl)->toDateString() => $nama]);
        $hak = DB::table('sub_group_lembur')->get()->mapWithKeys(fn ($r) => ["{$r->sub_group_id}|{$r->tipe_lokasi}" => (bool) $r->berhak]);

        $rosters = [];
        foreach (Roster::whereIn('karyawan_id', $ids)->whereBetween('tanggal', [$from->toDateString(), $to->toDateString()])->get(['karyawan_id', 'tanggal', 'shift_code_id']) as $r) {
            $rosters[$r->karyawan_id][$r->tanggal->toDateString()] = $codes[$r->shift_code_id] ?? null;
        }
        $absensi = [];
        foreach (Absensi::whereIn('karyawan_id', $ids)->whereBetween('tanggal', [$dari->toDateString(), $sampai->toDateString()])->get(['karyawan_id', 'tanggal', 'shift_code_id', 'sumber']) as $a) {
            $absensi[$a->karyawan_id][$a->tanggal->toDateString()] = ['code' => $codes[$a->shift_code_id] ?? null, 'sumber' => $a->sumber];
        }
        $trips = Trip::whereIn('karyawan_id', $ids)->where('status', 'disetujui')
            ->where('tanggal_awal', '<=', $sampai->toDateString())->where('tanggal_akhir', '>=', $dari->toDateString())
            ->get(['karyawan_id', 'jenis', 'tanggal_awal', 'tanggal_akhir'])->groupBy('karyawan_id');
        $logs = AbsenLog::whereIn('karyawan_id', $ids)
            ->whereBetween('waktu', [$from->toDateString().' 00:00:00', $to->addDay()->toDateString().' 12:00:00'])
            ->orderBy('waktu')->get(['id', 'karyawan_id', 'waktu', 'sumber'])->groupBy('karyawan_id');

        $out = [];
        foreach ($karyawans as $k) {
            $tipe = $k->workLocation?->type === WorkLocation::TYPE_HEAD_OFFICE ? WorkLocation::TYPE_HEAD_OFFICE : 'Site';
            $ctx = [
                'skema' => $k->workLocation?->skema_kerja ?? '5_hari',
                'jam_normal' => (float) ($k->workLocation?->jam_kerja_harian ?? 8),
                'berhak' => $hak["{$k->sub_group_id}|{$tipe}"] ?? true,
            ];
            $days = self::assign($rosters[$k->id] ?? [], ($logs[$k->id] ?? collect())->all(), $from, $to);
            for ($d = $dari; $d->lte($sampai); $d = $d->addDay()) {
                $key = $d->toDateString();
                $trip = ($trips[$k->id] ?? collect())->first(fn ($t) => $t->tanggal_awal->lte($d) && $t->tanggal_akhir->gte($d));
                $out[] = self::day($k, $d, $rosters[$k->id][$key] ?? null, $days[$key] ?? ['logs' => [], 'jadwal' => null],
                    $absensi[$k->id][$key] ?? null, $trip, isset($holidays[$key]), $ctx, $aturan);
            }
        }

        return $out;
    }

    /**
     * Gives each day its logs (step 1 and 2 of the class comment).
     *
     * @param  array<string, ?ShiftCode>  $roster  date => code
     * @param  list<AbsenLog>  $logs  in time order
     * @return array<string, array{logs: list<AbsenLog>, jadwal: ?Jadwal}>
     */
    private static function assign(array $roster, array $logs, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $jadwal = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $code = $roster[$d->toDateString()] ?? null;
            if ($code && $code->jenis === 'hadir' && ($j = Jadwal::of($code, $d))) {
                $jadwal[$d->toDateString()] = $j;
            }
        }
        $used = [];
        $days = [];
        $keys = array_keys($jadwal);
        foreach ($keys as $i => $key) {
            $j = $jadwal[$key];
            $start = $j->masuk->subMinutes(self::SEBELUM_MENIT);
            $end = $j->keluar->addMinutes(self::SESUDAH_MENIT);
            if ($i > 0) {
                $start = $start->max($jadwal[$keys[$i - 1]]->keluar->addHour());
            }
            if (isset($keys[$i + 1])) {
                $end = $end->min($jadwal[$keys[$i + 1]]->masuk->subHours(2));
            }
            $mine = [];
            foreach ($logs as $n => $log) {
                if (! isset($used[$n]) && $log->waktu->gte($start) && $log->waktu->lte($end)) {
                    $mine[] = $log;
                    $used[$n] = true;
                }
            }
            $days[$key] = ['logs' => $mine, 'jadwal' => $j];
        }
        // Days without a schedule: the logs left over, 04:00 to 04:00.
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $key = $d->toDateString();
            if (isset($jadwal[$key])) {
                continue;
            }
            $start = $d->setTime(4, 0);
            $end = $start->addDay();
            $mine = [];
            foreach ($logs as $n => $log) {
                if (! isset($used[$n]) && $log->waktu->gte($start) && $log->waktu->lt($end)) {
                    $mine[] = $log;
                    $used[$n] = true;
                }
            }
            $days[$key] = ['logs' => $mine, 'jadwal' => null];
        }

        return $days;
    }

    /** @param array{logs: list<AbsenLog>, jadwal: ?Jadwal} $slot */
    private static function day(Karyawan $k, CarbonImmutable $d, ?ShiftCode $roster, array $slot, ?array $absensi, ?Trip $trip, bool $libur, array $ctx, LemburAturan $aturan): array
    {
        $j = $slot['jadwal'];
        $logs = $slot['logs'];
        [$ci, $co] = [null, null];
        if ($logs) {
            $first = $logs[0]->waktu;
            $last = end($logs)->waktu;
            if (count($logs) > 1 && $first->diffInMinutes($last) >= 30) {
                [$ci, $co] = [$first, $last];
            } elseif ($j && abs($first->diffInMinutes($j->keluar)) < abs($first->diffInMinutes($j->masuk))) {
                $co = $first;
            } else {
                $ci = $first;
            }
        }

        $absensiJenis = ($absensi['code'] ?? null)?->jenis;
        $status = match (true) {
            $trip !== null => $trip->jenis,
            in_array($absensi['sumber'] ?? null, ['manual', 'import'], true) && in_array($absensiJenis, ['sakit', 'izin', 'cuti'], true) && ! $logs => $absensiJenis,
            $roster === null => $logs ? 'tidak_sesuai' : 'tanpa_roster',
            $roster->jenis !== 'hadir' => $logs ? 'tidak_sesuai' : 'off',
            $ci && $co => $j && abs($j->masuk->diffInMinutes($ci)) > self::BEDA_SHIFT_MENIT ? 'tidak_sesuai' : 'lengkap',
            $ci !== null => 'tanpa_co',
            $co !== null => 'tanpa_ci',
            default => 'tidak_hadir',
        };

        $menitKerja = 0;
        if ($ci && $co) {
            $start = $j && ! $aturan->hitung_sebelum_jadwal ? $ci->max($j->masuk) : $ci;
            $span = $start->lt($co) ? (int) $start->diffInMinutes($co) : 0;
            $menitKerja = $j ? $span - $j->istirahatDalam($start, $co) : ($span > 300 ? $span - 60 : $span);
        }
        $jenisHari = $libur ? 'libur' : ($j ? 'kerja' : 'istirahat');
        $lembur = $menitKerja > 0 && ! in_array($status, ['dinas', 'cuti'], true)
            ? LemburHitung::hitung($jenisHari, max(0, $menitKerja), $ctx['skema'], $ctx['jam_normal'], $aturan)
            : null;

        $fmt = fn (?CarbonImmutable $t) => $t ? ($t->isSameDay($d) ? $t->format('H:i') : $t->format('d/m H:i')) : null;

        return [
            'karyawan_id' => $k->id,
            'nrp' => $k->nrp,
            'nama' => $k->name,
            'lokasi' => $k->workLocation?->name,
            'tanggal' => $d->toDateString(),
            'roster' => $roster?->kode,
            'roster_jenis' => $roster?->jenis,
            'jadwal' => $j ? $j->masuk->format('H:i').'–'.$j->keluar->format('H:i') : null,
            'ci' => $fmt($ci),
            'co' => $fmt($co),
            'ci_at' => $ci?->toDateTimeString(),
            'co_at' => $co?->toDateTimeString(),
            'jumlah_log' => count($logs),
            'koreksi' => collect($logs)->contains(fn (AbsenLog $l) => $l->sumber === 'manual'),
            'status' => $status,
            'terlambat' => $j && $ci && $ci->gt($j->masuk) ? (int) $j->masuk->diffInMinutes($ci) : 0,
            'pulang_cepat' => $j && $co && $co->lt($j->keluar) ? (int) $co->diffInMinutes($j->keluar) : 0,
            'menit_kerja' => max(0, $menitKerja),
            'jenis_hari' => $jenisHari,
            'libur' => $libur,
            'berhak_lembur' => $ctx['berhak'],
            'lembur' => $lembur,
            'absensi' => ($absensi['code'] ?? null)?->kode,
            'absensi_sumber' => $absensi['sumber'] ?? null,
        ];
    }
}
