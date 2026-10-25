<?php

namespace App\Support\Time;

use App\Models\PublicHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** A calendar month ("2026-10"): the unit Roster and Absensi are shown and saved in. */
class Period
{
    public const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    private function __construct(public readonly CarbonImmutable $start) {}

    /** "2026-10"; anything else (or nothing) is the current month. */
    public static function from(?string $value): self
    {
        if ($value && preg_match('/^(\d{4})-(\d{2})$/', $value, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12 && (int) $m[1] >= 2000 && (int) $m[1] <= 2100) {
            return new self(CarbonImmutable::create((int) $m[1], (int) $m[2], 1));
        }

        return new self(CarbonImmutable::today()->startOfMonth());
    }

    /**
     * The months from $dari to $sampai ("2026-10"), at most $max of them.
     *
     * @return list<self>
     */
    public static function range(string $dari, string $until, int $max): array
    {
        $from = self::from($dari);
        $to = self::from($until);
        if ($to->start->lt($from->start)) {
            throw ValidationException::withMessages(['until' => 'Bulan akhir tidak boleh sebelum bulan awal.']);
        }
        $months = [];
        for ($m = $from->start; $m->lte($to->start); $m = $m->addMonth()) {
            $months[] = new self($m);
        }
        if (count($months) > $max) {
            throw ValidationException::withMessages(['until' => "Paling banyak {$max} bulan sekali unduh."]);
        }

        return $months;
    }

    public function end(): CarbonImmutable
    {
        return $this->start->endOfMonth()->startOfDay();
    }

    public function value(): string
    {
        return $this->start->format('Y-m');
    }

    public function label(): string
    {
        return self::MONTHS[$this->start->month - 1].' '.$this->start->year;
    }

    public function dayCount(): int
    {
        return $this->start->daysInMonth;
    }

    public function contains(CarbonImmutable $date): bool
    {
        return $date->format('Y-m') === $this->value();
    }

    /** For the page: the month and its days, with weekends and holidays. */
    public function toArray(): array
    {
        $public_holidays = PublicHoliday::whereDate('date', '>=', $this->start->toDateString())->whereDate('date', '<=', $this->end()->toDateString())
            ->pluck('name', 'date')
            ->mapWithKeys(fn ($name, $tanggal) => [substr((string) $tanggal, 0, 10) => $name]);

        return [
            'value' => $this->value(),
            'label' => $this->label(),
            'prev' => $this->start->subMonth()->format('Y-m'),
            'next' => $this->start->addMonth()->format('Y-m'),
            'today' => CarbonImmutable::today()->toDateString(),
            'days' => collect(range(1, $this->dayCount()))->map(function (int $day) use ($public_holidays) {
                $date = $this->start->setDay($day);

                return [
                    'day' => $day,
                    'date' => $date->toDateString(),
                    // 0 = Minggu ... 6 = Sabtu
                    'weekday' => $date->dayOfWeek,
                    'holiday' => $public_holidays[$date->toDateString()] ?? null,
                ];
            })->all(),
        ];
    }

    /** The dropdown: two years back to one year ahead, newest first. */
    public static function options(): array
    {
        $now = CarbonImmutable::today()->startOfMonth();

        return collect(range(12, -24))->map(function (int $offset) use ($now) {
            $month = $now->addMonths($offset);

            return ['value' => $month->format('Y-m'), 'label' => self::MONTHS[$month->month - 1].' '.$month->year];
        })->all();
    }
}
