<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The first-run setup (sesi awal) of a workspace for a company's own data: which steps
 * were done or skipped. Only such workspaces have a row; without one there is no setup.
 */
#[Fillable(['status', 'steps', 'completed_at'])]
class WorkspaceSetup extends Model
{
    /** step => title, in order. */
    public const STEPS = [
        'company' => 'Profil perusahaan',
        'employee_number' => 'Nomor Induk',
        'organization' => 'Organisasi',
        'migration' => 'Migrasi data',
    ];

    protected function casts(): array
    {
        return ['steps' => 'array', 'completed_at' => 'datetime'];
    }

    /** The setup of this workspace, or null when it has none (the main application, system demo data). */
    public static function current(): ?self
    {
        return self::query()->first();
    }

    /** Still to be gone through: the Super Admin is taken to it after signing in. */
    public static function pending(): bool
    {
        return self::current()?->status === 'pending';
    }

    public function mark(string $step, string $state): void
    {
        $this->update(['steps' => [...($this->steps ?? []), $step => $state]]);
    }
}
