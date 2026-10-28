<?php

namespace App\Http\Controllers\CoreHr;

use App\Http\Controllers\Controller;
use App\Models\EmployeeChangeRequest;
use App\Support\Employees\DataUpdate;
use App\Support\Workflow\Workflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Pengajuan Update Data from the employee portal: HR checks each change and approves or rejects it. */
class ChangeRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(EmployeeChangeRequest::STATUS)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'pending';

        $items = EmployeeChangeRequest::with(['employee.position:id,name', 'employee.workLocation:id,name', 'employee.profile', 'attachments', 'verifier:id,name'])
            ->where('status', $status)
            ->when(trim($filters['q'] ?? ''), fn ($q, $s) => $q->whereHas('employee', fn ($k) => $k->where('name', 'like', "%{$s}%")->orWhere('employee_number', 'like', "%{$s}%")))
            ->orderBy($status === 'pending' ? 'created_at' : 'verified_at', $status === 'pending' ? 'asc' : 'desc')
            ->paginate(15)
            ->withQueryString();
        $flows = Workflow::pendingOf(EmployeeChangeRequest::class, $status === 'pending' ? $items->getCollection()->pluck('id') : []);
        $items->through(fn (EmployeeChangeRequest $p) => DataUpdate::row($p) + ['now' => $status === 'pending' ? $this->now($p) : null,
            'workflow' => Workflow::brief($flows->get($p->id), $request->user())]);

        return Inertia::render('CoreHr/ChangeRequests', [
            'items' => $items,
            'filters' => ['status' => $status, 'q' => $filters['q'] ?? ''],
            'counts' => EmployeeChangeRequest::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'fields' => collect(DataUpdate::fields())->map(fn (array $f) => ['label' => $f['label'], 'type' => $f['type'], 'section' => $f['section']]),
            'can_edit' => $request->user()->canEdit('data-change-requests'),
        ]);
    }

    public function verify(Request $request, EmployeeChangeRequest $submission): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'notes' => ['nullable', 'string', 'max:255', 'required_if:decision,reject'],
        ], ['notes.required_if' => 'Tulis alasan penolakan.']);
        if ($submission->status !== 'pending') {
            throw ValidationException::withMessages(['decision' => 'Pengajuan ini sudah diverifikasi.']);
        }
        $approve = $data['decision'] === 'approve';
        // In a Workflow: this account's step decided there (the last approval updates the profile).
        if (($done = Workflow::verify($submission, $request->user(), $approve, $data['notes'] ?? null)) !== null) {
            Inertia::flash('success', 'Pengajuan update data '.$submission->employee->name.' '.match (true) {
                ! $approve => 'ditolak.',
                $done => 'disetujui; profilnya sudah diperbarui.',
                default => 'disetujui; lanjut ke langkah berikutnya.',
            });

            return back();
        }

        DB::transaction(function () use ($submission, $approve, $data, $request) {
            if ($approve) {
                DataUpdate::apply($submission);
            }
            $submission->update([
                'status' => $approve ? 'approved' : 'rejected',
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
                'verification_notes' => $data['notes'] ?? null,
            ]);
        });
        Inertia::flash('success', 'Pengajuan update data '.$submission->employee->name.' '.($approve ? 'disetujui; profilnya sudah diperbarui.' : 'rejected.'));

        return back();
    }

    /** What is stored now for the fields asked, when it changed after the request was sent. */
    private function now(EmployeeChangeRequest $p): array
    {
        $stored = $p->employee->profile?->attributesToArray() ?? [];
        $changed = [];
        foreach ($p->changes ?? [] as $key => $c) {
            if ((string) ($stored[$key] ?? null) !== (string) $c['before']) {
                $changed[$key] = $stored[$key] ?? null;
            }
        }

        return $changed;
    }
}
