<?php

namespace App\Support\Kehadiran;

use App\Models\ShiftCode;
use Carbon\CarbonImmutable;

/**
 * The schedule of a shift on a date: start, end (the next day for a night shift) and the
 * break, also moved to the next day when it falls after midnight.
 */
final class Jadwal
{
    public function __construct(
        public readonly CarbonImmutable $masuk,
        public readonly CarbonImmutable $keluar,
        public readonly ?CarbonImmutable $istirahatMulai,
        public readonly ?CarbonImmutable $istirahatSelesai,
    ) {}

    public static function of(ShiftCode $code, CarbonImmutable $tanggal): ?self
    {
        if (! $code->terjadwal()) {
            return null;
        }
        $at = fn (string $time) => CarbonImmutable::parse($tanggal->toDateString().' '.$time);
        $masuk = $at($code->jam_masuk);
        $keluar = $at($code->jam_keluar);
        if ($keluar->lte($masuk)) {
            $keluar = $keluar->addDay();
        }
        [$mulai, $selesai] = [null, null];
        if ($code->istirahat_mulai && $code->istirahat_selesai) {
            $mulai = $at($code->istirahat_mulai);
            if ($mulai->lt($masuk)) {
                $mulai = $mulai->addDay();
            }
            $selesai = $at($code->istirahat_selesai);
            while ($selesai->lte($mulai)) {
                $selesai = $selesai->addDay();
            }
        }

        return new self($masuk, $keluar, $mulai, $selesai);
    }

    /** Minutes of the break inside [$a, $b]: these are never working time. */
    public function istirahatDalam(CarbonImmutable $a, CarbonImmutable $b): int
    {
        return $this->istirahatMulai ? self::overlap($a, $b, $this->istirahatMulai, $this->istirahatSelesai) : 0;
    }

    /** Scheduled working minutes (the shift minus its break). */
    public function menitKerja(): int
    {
        return (int) $this->masuk->diffInMinutes($this->keluar) - $this->istirahatDalam($this->masuk, $this->keluar);
    }

    public static function overlap(CarbonImmutable $a1, CarbonImmutable $a2, CarbonImmutable $b1, CarbonImmutable $b2): int
    {
        $start = $a1->max($b1);
        $end = $a2->min($b2);

        return $end->gt($start) ? (int) $start->diffInMinutes($end) : 0;
    }
}
