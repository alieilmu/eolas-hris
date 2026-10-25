<?php

namespace App\Models;

use App\Support\Access\AccessScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'employee_number', 'attendance_pin', 'name', 'email', 'status', 'position_id', 'work_location_id', 'candidate_id',
    'sub_location_id', 'job_level_id', 'directorate_id', 'division_id', 'department_id', 'section_id', 'discipline',
    'termination_date', 'termination_reason', 'termination_notes',
])]
class Employee extends Model
{
    protected $table = 'employees';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** Excluded from the dashboard, Data Lengkap and org-chart slots. */
    public const STATUS_TERMINATED = 'terminated';

    /** How the statuses read on screen and in Excel. */
    public const STATUS_LABELS = [self::STATUS_ACTIVE => 'Aktif', self::STATUS_INACTIVE => 'Nonaktif', self::STATUS_TERMINATED => 'Keluar'];

    /**
     * The employee's own placement — where they work, their SubGroup and what it
     * names positions after. They hold a position (jabatan_id) once one matching
     * it exists in the plan; until then they wait to be placed in the chart.
     */
    public const PLACEMENT_COLUMNS = [
        'work_location_id', 'sub_location_id', 'job_level_id', 'directorate_id', 'division_id', 'department_id', 'section_id', 'discipline',
    ];

    protected function casts(): array
    {
        return [
            'termination_date' => 'immutable_date:Y-m-d',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** Where this employee works; a position can be filled at several sites. */
    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    /** A Sub Lokasi Kerja of their work location, when assigned away from the site itself. */
    public function subLocation(): BelongsTo
    {
        return $this->belongsTo(SubLocation::class);
    }

    public function jobLevel(): BelongsTo
    {
        return $this->belongsTo(JobLevel::class);
    }

    public function directorate(): BelongsTo
    {
        return $this->belongsTo(Directorate::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** @return array<string, mixed> the placement, as JabatanComposer reads it */
    public function placement(): array
    {
        return $this->only(self::PLACEMENT_COLUMNS);
    }

    /** The departemen of their placement: the one given, or the one of their section. */
    public function placementDepartment(): ?Department
    {
        return $this->department ?? $this->section?->department;
    }

    public function isPlaced(): bool
    {
        return $this->position_id !== null;
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /** The employee's number in the attendance machines: the PIN set, else the NRP. */
    public function pin(): string
    {
        return $this->attendance_pin ?: $this->employee_number;
    }

    public function salaries(): HasMany
    {
        return $this->hasMany(EmployeeSalary::class)->orderByDesc('effective_date')->orderByDesc('id');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    public function payrollProfile(): HasOne
    {
        return $this->hasOne(EmployeePayrollProfile::class);
    }

    /** Leave taken outside Cuti & Dinas (imported history, or typed by HR). */
    public function leaveTransactions(): HasMany
    {
        return $this->hasMany(LeaveTransaction::class);
    }

    protected static function booted(): void
    {
        // Administrasi → Pengguna & Akses: an Admin sees the employees of their locations, a Karyawan only themselves.
        static::addGlobalScope('access', fn (Builder $query) => AccessScope::restrict($query));
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeTermination(Builder $query): void
    {
        $query->where('status', self::STATUS_TERMINATED);
    }
}
