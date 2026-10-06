<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Karyawan;
use App\Models\SubLocation;
use App\Models\User;
use App\Models\WorkLocation;
use App\Support\Access\Menu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Akun & Akses (Super Admin only): admin accounts with their menus
 * (lihat / ubah) and locations, and employee accounts for the portal.
 */
class UserController extends Controller
{
    public function index(): Response
    {
        $accounts = User::where('role', User::ROLE_KARYAWAN)->get()->keyBy('karyawan_id');

        return Inertia::render('Settings/Users', [
            'admins' => User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_HR])->orderBy('role')->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'permissions' => (object) ($u->role === User::ROLE_ADMIN ? Menu::all() : ($u->permissions ?? [])),
                'work_location_ids' => $u->work_location_ids ?? [],
                'sub_location_ids' => $u->sub_location_ids ?? [],
                'is_active' => $u->is_active,
                'last_login_at' => $u->last_login_at?->format('d.m.Y H:i'),
            ]),
            'karyawans' => Karyawan::with(['jabatan:id,name', 'workLocation:id,name', 'subLocation:id,name', 'profile:id,karyawan_id,tanggal_lahir'])
                ->where('status', '!=', Karyawan::STATUS_KELUAR)
                ->orderBy('nrp')
                ->get()
                ->map(function (Karyawan $k) use ($accounts) {
                    $account = $accounts[$k->id] ?? null;

                    return [
                        'id' => $k->id,
                        'nrp' => $k->nrp,
                        'name' => $k->name,
                        'jabatan' => $k->jabatan?->name,
                        'work_location_id' => $k->work_location_id,
                        'work_location' => $k->workLocation?->name,
                        'sub_location' => $k->subLocation?->name,
                        'sub_location_id' => $k->sub_location_id,
                        'has_tanggal_lahir' => (bool) $k->profile?->tanggal_lahir,
                        'account' => $account ? [
                            'id' => $account->id,
                            'is_active' => $account->is_active,
                            'must_change_password' => $account->must_change_password,
                            'last_login_at' => $account->last_login_at?->format('d.m.Y H:i'),
                        ] : null,
                    ];
                }),
            'menus' => collect(Menu::MODULES)->map(fn (array $menus, string $module) => [
                'module' => $module,
                'menus' => collect($menus)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values(),
            ])->values(),
            'locations' => WorkLocation::orderBy('order')->get(['id', 'name']),
            'sub_locations' => SubLocation::orderBy('work_location_id')->orderBy('order')->orderBy('name')->get(['id', 'name', 'work_location_id']),
            'roles' => User::ROLE_LABELS,
        ]);
    }

    // --- admin accounts ----------------------------------------------------------------------

    public function store(Request $request): RedirectResponse
    {
        $data = $this->adminData($request);
        $user = User::create($data + ['must_change_password' => true]);
        Inertia::flash('success', "Akun {$user->name} dibuat. Password awal harus diganti saat login pertama.");

        return back();
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_if($user->isKaryawan(), 404);
        $data = $this->adminData($request, $user);
        $demoted = $user->isAdmin() && $data['role'] !== User::ROLE_ADMIN;
        if (($demoted || ! $data['is_active']) && $user->isAdmin() && User::where('role', User::ROLE_ADMIN)->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages(['role' => 'Harus ada minimal satu Super Admin yang aktif.']);
        }
        if ($user->is($request->user()) && ($demoted || ! $data['is_active'])) {
            throw ValidationException::withMessages(['role' => 'Anda tidak bisa menurunkan atau menonaktifkan akun Anda sendiri.']);
        }
        if (filled($data['password'] ?? null)) {
            $data['must_change_password'] = ! $user->is($request->user());
        } else {
            unset($data['password']);
        }
        $user->update($data);
        Inertia::flash('success', "Akun {$user->name} diperbarui.");

        return back();
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => 'Anda tidak bisa menghapus akun Anda sendiri.']);
        }
        if ($user->isAdmin() && User::where('role', User::ROLE_ADMIN)->count() <= 1) {
            throw ValidationException::withMessages(['user' => 'Super Admin terakhir tidak bisa dihapus.']);
        }
        $user->delete();
        Inertia::flash('success', "Akun {$user->name} dihapus.");

        return back();
    }

    private function adminData(Request $request, ?User $current = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($current?->id)],
            'password' => [$current ? 'nullable' : 'required', 'string', Password::min(8)],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_HR])],
            'is_active' => ['boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['nullable', Rule::in([Menu::VIEW, Menu::EDIT])],
            'work_location_ids' => ['nullable', 'array'],
            'work_location_ids.*' => ['integer', 'exists:work_locations,id'],
            'sub_location_ids' => ['nullable', 'array'],
            'sub_location_ids.*' => ['integer', 'exists:sub_locations,id'],
        ], [], ['name' => 'Nama', 'email' => 'Email', 'role' => 'Jenis akun']);

        $admin = $data['role'] === User::ROLE_ADMIN;
        $permissions = array_filter(array_intersect_key($data['permissions'] ?? [], array_flip(Menu::keys())));
        if (! $admin && ! $permissions) {
            throw ValidationException::withMessages(['permissions' => 'Centang minimal satu menu yang bisa diakses.']);
        }

        return [
            'name' => trim($data['name']),
            'email' => mb_strtolower(trim($data['email'])),
            'password' => $data['password'] ?? null,
            'role' => $data['role'],
            'is_active' => $data['is_active'] ?? true,
            // The Super Admin has everything everywhere; nothing to store.
            'permissions' => $admin ? null : $permissions,
            'work_location_ids' => $admin ? null : array_values(array_map('intval', $data['work_location_ids'] ?? [])),
            'sub_location_ids' => $admin ? null : array_values(array_map('intval', $data['sub_location_ids'] ?? [])),
        ];
    }

    // --- employee accounts -------------------------------------------------------------------

    /** Accounts for the chosen employees; the first password is their birth date (ddmmyyyy). */
    public function storeKaryawan(Request $request): RedirectResponse
    {
        $ids = $request->validate(['karyawan_ids' => ['required', 'array', 'min:1'], 'karyawan_ids.*' => ['integer']])['karyawan_ids'];
        $existing = User::whereIn('karyawan_id', $ids)->pluck('karyawan_id')->flip();
        $people = Karyawan::with('profile:id,karyawan_id,tanggal_lahir')->whereIn('id', $ids)->where('status', '!=', Karyawan::STATUS_KELUAR)->get();

        // Bulk: one insert per 500 rows, with the cheap first-time hash (see initialPasswordHash).
        $now = now();
        $rows = [];
        $withoutBirthDate = 0;
        foreach ($people as $k) {
            if ($existing->has($k->id)) {
                continue;
            }
            if (! $k->profile?->tanggal_lahir) {
                $withoutBirthDate++;
            }
            $rows[] = [
                'name' => $k->name,
                'email' => null,
                'password' => self::initialPasswordHash($k),
                'role' => User::ROLE_KARYAWAN,
                'karyawan_id' => $k->id,
                'is_active' => true,
                'must_change_password' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::transaction(fn () => collect($rows)->chunk(500)->each(fn ($chunk) => User::insert($chunk->values()->all())));
        $created = count($rows);

        Inertia::flash('success', "{$created} akun karyawan dibuat. Login dengan NRP, password awal tanggal lahir (ddmmyyyy)"
            .($withoutBirthDate ? "; {$withoutBirthDate} tanpa tanggal lahir memakai NRP sebagai password awal." : '.'));

        return back();
    }

    /** Back to the first password (birth date), to be changed again at the next sign-in. */
    public function resetKaryawan(User $user): RedirectResponse
    {
        abort_unless($user->isKaryawan() && $user->karyawan, 404);
        $user->update(['password' => self::initialPasswordHash($user->karyawan), 'must_change_password' => true]);
        Inertia::flash('success', "Password {$user->name} dikembalikan ke password awal.");

        return back();
    }

    public function toggleKaryawan(User $user): RedirectResponse
    {
        abort_unless($user->isKaryawan(), 404);
        $user->update(['is_active' => ! $user->is_active]);
        Inertia::flash('success', "Akun {$user->name} ".($user->is_active ? 'diaktifkan.' : 'dinonaktifkan.'));

        return back();
    }

    public function destroyKaryawan(User $user): RedirectResponse
    {
        abort_unless($user->isKaryawan(), 404);
        $user->delete();
        Inertia::flash('success', "Akun karyawan {$user->name} dihapus.");

        return back();
    }

    /** Birth date as ddmmyyyy, or the NRP when it is not filled in. */
    public static function initialPassword(Karyawan $k): string
    {
        $k->loadMissing('profile');

        return $k->profile?->tanggal_lahir?->format('dmY') ?? $k->nrp;
    }

    /**
     * The first-time password is a known value (birth date / NRP) that must be replaced at the
     * first sign-in, so a high bcrypt cost adds nothing there and makes creating hundreds of
     * accounts time out. The password the employee picks afterwards gets the normal cost.
     */
    public static function initialPasswordHash(Karyawan $k): string
    {
        return Hash::make(self::initialPassword($k), ['rounds' => 4]);
    }
}
