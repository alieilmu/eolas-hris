<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Access\Menu;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name', 'email', 'password', 'role', 'employee_id', 'permissions', 'work_location_ids', 'sub_location_ids',
    'is_active', 'must_change_password', 'last_login_at',
])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Super Admin: every module and location, and Settings (accounts). */
    public const ROLE_ADMIN = 'super_admin';

    /** Admin: the menus ticked in Settings, for the locations chosen there. */
    public const ROLE_HR = 'admin';

    /** An employee: the employee portal only (their own plafon, deklarasi, cuti and dinas). */
    public const ROLE_EMPLOYEE = 'employee';

    public const ROLE_LABELS = [self::ROLE_ADMIN => 'Super Admin', self::ROLE_HR => 'Admin', self::ROLE_EMPLOYEE => 'Karyawan'];

    /** A new account is active and keeps its password unless created with a first-time one. */
    protected $attributes = ['is_active' => true, 'must_change_password' => false];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
            'work_location_ids' => 'array',
            'sub_location_ids' => 'array',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** Signs in with a code from an authenticator app as well as the password. */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The Super Admin. */
    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isEmployee(): bool
    {
        return $this->role === self::ROLE_EMPLOYEE;
    }

    /** "edit", "view" or null for a menu (see Menu). */
    public function menuLevel(string $menu): ?string
    {
        return match ($this->role) {
            self::ROLE_ADMIN => Menu::EDIT,
            self::ROLE_HR => $this->permissions[$menu] ?? null,
            default => null,
        };
    }

    /** May look at any of these menus. */
    public function canView(string ...$menus): bool
    {
        foreach ($menus as $menu) {
            if ($this->menuLevel($menu) !== null) {
                return true;
            }
        }

        return false;
    }

    /** May change data in any of these menus. */
    public function canEdit(string ...$menus): bool
    {
        foreach ($menus as $menu) {
            if ($this->menuLevel($menu) === Menu::EDIT) {
                return true;
            }
        }

        return false;
    }

    /** Every menu with its level, for the page (menus not given are left out). */
    public function menus(): array
    {
        return array_filter(array_combine(Menu::keys(), array_map($this->menuLevel(...), Menu::keys())));
    }

    /** Limited to some locations (an Admin with locations chosen). */
    public function hasLocationScope(): bool
    {
        return $this->role === self::ROLE_HR && ($this->work_location_ids || $this->sub_location_ids);
    }

    /** NIK, NPWP, BPJS, bank accounts and family data: who may see Man Power. */
    public function canAccessSensitiveData(): bool
    {
        return $this->canView('employees');
    }
}
