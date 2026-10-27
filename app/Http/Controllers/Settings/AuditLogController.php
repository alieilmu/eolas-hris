<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Administrasi → Audit Log (Super Admin): who did what to salary data, payroll runs, exports and accounts. */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate([
            'action' => ['nullable', 'string', 'max:50'],
            'group' => ['nullable', 'string', 'max:30'],
            'user' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $groupActions = isset($f['group']) ? array_keys(Audit::ACTIONS[$f['group']] ?? []) : null;
        $logs = AuditLog::query()
            ->when($f['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($groupActions, fn ($q) => $q->whereIn('action', $groupActions))
            ->when($f['user'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where(fn ($q) => $q->where('description', 'like', "%{$t}%")->orWhere('user_name', 'like', "%{$t}%")->orWhere('ip', 'like', "%{$t}%")))
            ->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return Inertia::render('Settings/AuditLogs', [
            'logs' => $logs->through(fn (AuditLog $l) => [
                'id' => $l->id, 'at' => $l->created_at?->toDateTimeString(), 'user' => $l->user_name, 'user_id' => $l->user_id,
                'action' => $l->action, 'label' => Audit::label($l->action), 'subject' => $l->subject_type ? "{$l->subject_type} #{$l->subject_id}" : null,
                'description' => $l->description, 'meta' => $l->meta, 'ip' => $l->ip, 'user_agent' => $l->user_agent,
            ]),
            'filters' => $f,
            'actions' => collect(Audit::ACTIONS)->map(fn ($items, $group) => ['group' => $group, 'items' => collect($items)->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values()])->values(),
            'users' => User::whereIn('id', AuditLog::query()->whereNotNull('user_id')->distinct()->pluck('user_id'))->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
