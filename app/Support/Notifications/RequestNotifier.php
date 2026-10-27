<?php

namespace App\Support\Notifications;

use App\Models\Applicant;
use App\Models\LongServiceLeave;
use App\Models\BenefitClaim;
use App\Models\BenefitClaimItem;
use App\Models\Employee;
use App\Models\WorkforcePlanRevision;
use App\Models\EmployeeChangeRequest;
use App\Models\LeaveTravelRequest;
use App\Models\User;
use App\Notifications\RequestReceived;
use App\Support\Access\AccessScope;
use App\Support\Leave\LeaveLedger;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Notifications for a pengajuan from the employee portal. They go to the admins who may
 * verify it — "ubah" on its menu, and the employee inside their locations — and are
 * closed (read for everyone) once HR has decided, or the employee withdrew it.
 */
class RequestNotifier
{
    public static function updateData(EmployeeChangeRequest $p): void
    {
        $n = count($p->changes ?? []) + count($p->children ?? []);
        self::send('data-change-requests', "data-change-requests:{$p->id}", 'data-change-requests', $p->employee,
            'Pengajuan Update Data', "mengajukan {$n} perubahan data", '/core-hr/change-requests');
    }

    public static function claim(BenefitClaim $d): void
    {
        $d->loadMissing('items');
        $optical = $d->items->whereIn('type', BenefitClaimItem::KACAMATA)->isNotEmpty();
        $medical = $d->items->whereIn('type', BenefitClaimItem::PENGOBATAN)->isNotEmpty();
        $title = 'Deklarasi '.match (true) {
            $optical && $medical => 'Pengobatan & Kacamata',
            $optical => 'Kacamata',
            default => 'Pengobatan',
        };
        self::send('claim', "claim:{$d->id}", 'benefit-claims', $d->employee,
            $title, "{$d->claim_number} · Rp ".number_format((int) $d->items->sum('amount'), 0, ',', '.'), '/compensation/claims?status=pending');
    }

    public static function leaveTravel(LeaveTravelRequest $t): void
    {
        $date = $t->start_date->format('d.m.Y').($t->end_date->ne($t->start_date) ? ' – '.$t->end_date->format('d.m.Y') : '');
        $body = $t->type === 'leave'
            ? (LeaveLedger::KATEGORI[$t->leave_category] ?? 'Cuti').' '.($t->leave_days ?? $t->durationDays())." hari, {$date}"
            : "ke {$t->destination_city}, {$date}".($t->needs_cash_advance ? ' · uang muka Rp '.number_format((int) $t->cash_advance_amount, 0, ',', '.') : '');
        self::send($t->type, "leave_travel:{$t->id}", 'travel-requests', $t->employee,
            'Pengajuan '.LeaveTravelRequest::JENIS[$t->type], $body, "/time/travel-requests?type={$t->type}&status=pending");
    }

    public static function longServiceLeave(LongServiceLeave $c): void
    {
        self::send('long-service-leave', "long-service-leave:{$c->id}", 'leave-balances', $c->employee,
            'Pilihan Cuti Besar', 'memilih '.(LongServiceLeave::KEPUTUSAN[$c->decision] ?? $c->decision)." (periode ke-{$c->sequence_no})", '/time/leave-balances?tab=long_service&status=pending');
    }

    /** A loan asked for in the portal: for whoever approves payroll for the employee. */
    public static function loan(\App\Models\Loan $l): void
    {
        $l->loadMissing('employee');
        self::send('loan', "loan:{$l->id}", 'payroll-approval', $l->employee, 'Pengajuan Pinjaman',
            'mengajukan pinjaman Rp '.number_format($l->principal, 0, ',', '.')." untuk {$l->tenor} bulan", '/compensation/loans?status=pending');
    }

    /** A new application from the public form (no employee yet: every admin of Recruitment). */
    public static function applicants(Applicant $a): void
    {
        $a->loadMissing('form');
        $to = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_HR])->where('is_active', true)->get()->filter(fn (User $u) => $u->canEdit('recruitment'))->values();
        $position_title = $a->answers('position_title') ?? $a->form?->position_title ?? $a->form?->title;
        if ($to->isNotEmpty()) {
            self::safely(fn () => Notification::send($to, new RequestReceived('applicants', "applicants:{$a->id}", 'Pelamar Baru',
                $a->name().' melamar '.$position_title.($a->form && $a->form->title !== $position_title ? " ({$a->form->title})" : ''),
                "/recruitment/applicants?view={$a->id}", ['id' => null, 'employee_number' => null, 'name' => $a->name()])));
        }
    }

    /** A plan revision from an account without approval: for the approvers of that location. */
    public static function workforcePlanRevision(WorkforcePlanRevision $r): void
    {
        $r->loadMissing(['plan.workLocation', 'creator']);
        $locationId = $r->plan->work_location_id;
        $to = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_HR])->where('is_active', true)->get()
            ->filter(fn (User $u) => $u->id !== $r->created_by && $u->canEdit('workforce-planning-approval')
                && (($ids = AccessScope::workLocationIds($u)) === null || in_array($locationId, $ids, true)))
            ->values();
        if ($to->isNotEmpty()) {
            self::safely(fn () => Notification::send($to, new RequestReceived('workforce_planning', "workforce_planning:{$r->id}", 'Revisi Man Power Planning',
                "{$r->plan->workLocation->name} · {$r->plan->label()}: ".count($r->changes).' jabatan diubah oleh '.($r->creator?->name ?? '-'),
                "/core-hr/workforce-planning?location={$locationId}&period={$r->plan->code()}", ['id' => null, 'employee_number' => null, 'name' => $r->creator?->name])));
        }
    }

    /** The decision, for whoever sent the revision. */
    public static function workforcePlanResult(WorkforcePlanRevision $r): void
    {
        $r->loadMissing(['plan.workLocation', 'approver', 'creator']);
        if (! $r->creator || $r->creator->id === $r->verified_by) {
            return;
        }
        $agree = $r->status === 'approved';
        self::safely(fn () => $r->creator->notify(new RequestReceived('workforce_planning-result', "workforce_planning-result:{$r->id}", 'Revisi MPP '.($agree ? 'disetujui' : 'ditolak'),
            "{$r->plan->workLocation->name} · {$r->plan->label()} oleh ".($r->approver?->name ?? '-').($r->verification_notes ? " — {$r->verification_notes}" : ''),
            "/core-hr/workforce-planning?location={$r->plan->work_location_id}&period={$r->plan->code()}", ['id' => null, 'employee_number' => null, 'name' => $r->approver?->name])));
    }

    /** HR decided, or the employee withdrew it: nothing left to do for anyone. */
    public static function close(string $ref): void
    {
        self::safely(fn () => DatabaseNotification::where('ref', $ref)->whereNull('read_at')->update(['read_at' => now()]));
    }

    /** Active admins with "ubah" on $menu whose locations include the employee's. */
    public static function recipients(string $menu, Employee $k): Collection
    {
        return User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_HR])
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $u) => $u->canEdit($menu) && (! $u->hasLocationScope()
                || in_array($k->work_location_id, array_map('intval', $u->work_location_ids ?? []), true)
                || in_array($k->sub_location_id, array_map('intval', $u->sub_location_ids ?? []), true)))
            ->values();
    }

    private static function send(string $type, string $ref, string $menu, ?Employee $k, string $title, string $body, string $url): void
    {
        if (! $k) {
            return;
        }
        $to = self::recipients($menu, $k);
        if ($to->isNotEmpty()) {
            self::safely(fn () => Notification::send($to, new RequestReceived($type, $ref, $title, "{$k->name} ({$k->employee_number}) {$body}", $url, ['id' => $k->id, 'employee_number' => $k->employee_number, 'name' => $k->name])));
        }
    }

    /**
     * A notification is extra: when it cannot be written (e.g. the notifications table is not
     * migrated yet on a server) the pengajuan / decision already saved must not fail with it.
     */
    private static function safely(callable $write): void
    {
        rescue($write, report: true);
    }
}
