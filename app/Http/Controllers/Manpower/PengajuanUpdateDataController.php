<?php

namespace App\Http\Controllers\Manpower;

use App\Http\Controllers\Controller;
use App\Models\PengajuanUpdateData;
use App\Support\Karyawan\DataUpdate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Pengajuan Update Data from the employee portal: HR checks each change and approves or rejects it. */
class PengajuanUpdateDataController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(PengajuanUpdateData::STATUS)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'diajukan';

        $items = PengajuanUpdateData::with(['karyawan.jabatan:id,name', 'karyawan.workLocation:id,name', 'karyawan.profile', 'lampirans', 'verifier:id,name'])
            ->where('status', $status)
            ->when(trim($filters['q'] ?? ''), fn ($q, $s) => $q->whereHas('karyawan', fn ($k) => $k->where('name', 'like', "%{$s}%")->orWhere('nrp', 'like', "%{$s}%")))
            ->orderBy($status === 'diajukan' ? 'created_at' : 'verified_at', $status === 'diajukan' ? 'asc' : 'desc')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (PengajuanUpdateData $p) => DataUpdate::row($p) + ['sekarang' => $status === 'diajukan' ? $this->now($p) : null]);

        return Inertia::render('Manpower/PengajuanData', [
            'items' => $items,
            'filters' => ['status' => $status, 'q' => $filters['q'] ?? ''],
            'counts' => PengajuanUpdateData::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'fields' => collect(DataUpdate::fields())->map(fn (array $f) => ['label' => $f['label'], 'type' => $f['type'], 'section' => $f['section']]),
            'can_edit' => $request->user()->canEdit('update-data'),
        ]);
    }

    public function verify(Request $request, PengajuanUpdateData $pengajuan): RedirectResponse
    {
        $data = $request->validate([
            'keputusan' => ['required', Rule::in(['setujui', 'tolak'])],
            'catatan' => ['nullable', 'string', 'max:255', 'required_if:keputusan,tolak'],
        ], ['catatan.required_if' => 'Tulis alasan penolakan.']);
        if ($pengajuan->status !== 'diajukan') {
            throw ValidationException::withMessages(['keputusan' => 'Pengajuan ini sudah diverifikasi.']);
        }
        $approve = $data['keputusan'] === 'setujui';

        DB::transaction(function () use ($pengajuan, $approve, $data, $request) {
            if ($approve) {
                DataUpdate::apply($pengajuan);
            }
            $pengajuan->update([
                'status' => $approve ? 'disetujui' : 'ditolak',
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
                'catatan_verifikasi' => $data['catatan'] ?? null,
            ]);
        });
        Inertia::flash('success', 'Pengajuan update data '.$pengajuan->karyawan->name.' '.($approve ? 'disetujui; profilnya sudah diperbarui.' : 'ditolak.'));

        return back();
    }

    /** What is stored now for the fields asked, when it changed after the request was sent. */
    private function now(PengajuanUpdateData $p): array
    {
        $stored = $p->karyawan->profile?->attributesToArray() ?? [];
        $changed = [];
        foreach ($p->perubahan ?? [] as $key => $c) {
            if ((string) ($stored[$key] ?? null) !== (string) $c['before']) {
                $changed[$key] = $stored[$key] ?? null;
            }
        }

        return $changed;
    }
}
