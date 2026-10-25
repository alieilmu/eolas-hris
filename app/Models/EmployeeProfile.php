<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The 19 data groups of one employee. Usia, masa kerja and status akhir PKWT
 * are computed here on read and never stored.
 */
class EmployeeProfile extends Model
{
    protected $table = 'employee_profiles';

    /** Over 100 flat columns; request data is always validated before it gets here. */
    protected $guarded = ['id', 'employee_id'];

    public const PKWT_MAX_PERIODS = 6;

    /** Date columns, serialized as plain Y-m-d so no timezone shift can move them a day. */
    public const DATE_FIELDS = [
        'birth_date', 'hire_date', 'marriage_date', 'appointment_letter_date',
        'tax_id_registered_date', 'bpjs_employment_registered_date', 'spouse_birth_date',
        'father_birth_date', 'mother_birth_date', 'father_in_law_birth_date', 'mother_in_law_birth_date',
        'long_sick_start_date', 'long_sick_end_date',
    ];

    protected function casts(): array
    {
        return [
            ...array_fill_keys(self::DATE_FIELDS, 'immutable_date:Y-m-d'),
            'is_permanent' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmploymentContract::class)->orderBy('sequence_no');
    }

    public function children(): HasMany
    {
        return $this->hasMany(EmployeeChild::class)->orderBy('sort_order');
    }

    protected function age(): Attribute
    {
        return Attribute::get(function (): ?int {
            if (! $this->birth_date || $this->birth_date->isFuture()) {
                return null;
            }

            return $this->birth_date->age;
        });
    }

    /** "X tahun Y bulan" from tanggal_masuk until today. */
    protected function yearsOfService(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->yearsOfServiceOn(today()));
    }

    /** "X tahun Y bulan" from tanggal_masuk until $end, e.g. the exit date. */
    public function yearsOfServiceOn(CarbonInterface $end): ?string
    {
        if (! $this->hire_date || $this->hire_date->isAfter($end)) {
            return null;
        }

        $diff = $this->hire_date->diff($end);

        return "{$diff->y} tahun {$diff->m} bulan";
    }

    /** The contract that ends last, whether or not it is still running. */
    public function latestContract(): ?EmploymentContract
    {
        return $this->contracts->sortByDesc('end_date')->first();
    }

    /**
     * State of the latest contract only: "Aktif", "Berakhir" or "-". Deliberately
     * independent of status_pegawai_tetap so HR still sees a lapsed PKWT after
     * appointment, e.g. a renewal that was never signed. A contract is still
     * active on its end date.
     */
    protected function contractEndStatus(): Attribute
    {
        return Attribute::get(function (): string {
            $latest = $this->latestContract();

            if (! $latest) {
                return '-';
            }

            return $latest->end_date->gte(today()) ? 'Aktif' : 'Berakhir';
        });
    }
}
