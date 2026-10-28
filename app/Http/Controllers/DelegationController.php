<?php

namespace App\Http\Controllers;

use App\Models\ApprovalDelegation;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Workflow\Delegations;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Delegasi in Persetujuan Saya: an approver names a substitute who decides in their name, for a
 * range of dates or on every day of their approved leave; and sees whom they stand in for today.
 */
class DelegationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = CarbonImmutable::today();
        $own = ApprovalDelegation::with('delegate:id,name')->where('user_id', $user->id)->where('is_active', true)->latest('id')->get()
            ->reject(fn (ApprovalDelegation $d) => $d->expired($today));
        $principals = Delegations::principalsOf($user);

        return response()->json([
            'own' => $own->map(fn (ApprovalDelegation $d) => [
                'id' => $d->id, 'delegate' => $d->delegate?->name, 'mode' => $d->mode, 'period' => $d->period(), 'notes' => $d->notes,
                'applies_today' => in_array($user->id, Delegations::principalsOf($d->delegate ?? new User, $today), true),
            ])->values(),
            'acting_for' => User::whereIn('id', $principals)->orderBy('name')->pluck('name'),
            'users' => User::where('is_active', true)->whereKeyNot($user->id)->with('employee:id,employee_number,position_id', 'employee.position:id,name')
                ->orderBy('name')->get()
                ->map(fn (User $u) => ['id' => $u->id, 'label' => $u->name.($u->employee ? " · {$u->employee->employee_number}".($u->employee->position ? " · {$u->employee->position->name}" : '') : ' · admin')]),
            'modes' => collect(ApprovalDelegation::MODES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'delegate_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true), Rule::notIn([$user->id])],
            'mode' => ['required', Rule::in(array_keys(ApprovalDelegation::MODES))],
            'starts_on' => ['nullable', 'required_if:mode,'.ApprovalDelegation::RANGE, 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'required_if:mode,'.ApprovalDelegation::RANGE, 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], ['delegate_id.not_in' => 'Pilih akun lain sebagai pengganti.'], ['delegate_id' => 'Pengganti', 'starts_on' => 'Tanggal mulai', 'ends_on' => 'Tanggal selesai']);
        if ($data['mode'] === ApprovalDelegation::ON_LEAVE && ! $user->employee_id) {
            throw ValidationException::withMessages(['mode' => 'Akun ini tidak terhubung ke data karyawan, jadi tidak punya cuti. Pakai rentang tanggal.']);
        }
        if ($data['mode'] === ApprovalDelegation::ON_LEAVE) {
            $data['starts_on'] = $data['ends_on'] = null;
        }
        $delegation = ApprovalDelegation::create($data + ['user_id' => $user->id, 'is_active' => true]);
        $delegate = User::find($data['delegate_id']);
        Audit::log('workflow.delegation', $delegation, "Pengganti {$delegate->name} · {$delegation->period()}");

        return response()->json(['ok' => true], 201);
    }

    public function destroy(Request $request, ApprovalDelegation $delegation): JsonResponse
    {
        abort_unless($delegation->user_id === $request->user()->id, 404);
        $delegation->update(['is_active' => false]);
        Audit::log('workflow.delegation', $delegation, "Delegasi ke {$delegation->delegate?->name} dihentikan");

        return response()->json(['ok' => true]);
    }
}
