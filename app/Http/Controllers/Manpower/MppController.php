<?php

namespace App\Http\Controllers\Manpower;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Recruitment\FormController;
use App\Models\ApplicationForm;
use App\Models\Golongan;
use App\Models\Jabatan;
use App\Models\MppAturan;
use App\Models\MppPlan;
use App\Models\MppPlanItem;
use App\Models\MppRevisi;
use App\Models\WorkLocation;
use App\Support\Access\AccessScope;
use App\Support\Mpp\Mpp;
use App\Support\Mpp\MppExcel;
use App\Support\Notifikasi\Pengajuan;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Man Power Planning: one running plan per work location, per quarter or semester. An
 * account with "Persetujuan MPP" changes it straight away; anyone else sends a revision
 * that waits for an approver. Admins seeing every location also get the All Site overview.
 */
class MppController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $today = CarbonImmutable::today();
        $locations = AccessScope::locations();
        $allSites = AccessScope::workLocationIds() === null;
        $periode = Mpp::parseKode($request->query('periode')) ?? Mpp::periodeOf($today);
        $param = (string) $request->query('lokasi', '');
        $user = $request->user();

        $base = [
            'locations' => $locations->map(fn (WorkLocation $l) => ['id' => $l->id, 'name' => $l->name]),
            'all_sites' => $allSites,
            'periode' => ['kode' => Mpp::kode($periode), 'label' => Mpp::label($periode), 'jenis' => $periode['jenis']],
            'periodes' => Mpp::periodeOptions(null, $today),
            'aturan' => MppAturan::PERIODE[MppAturan::current()->periode],
            'can_edit' => $user->canEdit('mpp'),
            'can_approve' => $user->canEdit('mpp-approve'),
            'can_recruit' => $user->canEdit('recruitment'),
        ];

        if ($param === 'semua' && $allSites) {
            $rows = WorkLocation::orderBy('order')->get()->map(function (WorkLocation $l) use ($periode) {
                $plan = Mpp::plan($l, $periode);

                return ['id' => $l->id, 'name' => $l->name, 'type' => $l->type, ...Mpp::summary($plan),
                    'revisi' => $plan->revisis()->where('status', 'diajukan')->count()];
            });

            return Inertia::render('Manpower/Mpp', [...$base, 'mode' => 'semua', 'lokasi' => null, 'sites' => $rows]);
        }

        $location = $locations->firstWhere('id', (int) $param)
            ?? $locations->firstWhere('name', 'Head Office')
            ?? $locations->first();
        if (! $location && WorkLocation::doesntExist()) {
            // A new (empty) installation: the plan is made per work location, so those come first.
            Inertia::flash('error', 'Belum ada lokasi kerja. Buat lokasi kerja dulu di Master Data Personalia, lalu susun MPP-nya.');

            return redirect('/manpower/master-data');
        }
        abort_unless($location, 403);
        $location = WorkLocation::find($location->id);
        $plan = Mpp::plan($location, $periode);

        return Inertia::render('Manpower/Mpp', [
            ...$base,
            'mode' => 'lokasi',
            'lokasi' => ['id' => $location->id, 'name' => $location->name],
            'plan' => [
                'id' => $plan->id,
                'label' => $plan->label(),
                'mulai' => $plan->mulai->toDateString(),
                'selesai' => $plan->selesai->toDateString(),
                'editable' => $plan->selesai->gte($today),
                'dari' => $plan->dari_plan_id ? MppPlan::find($plan->dari_plan_id)?->label() : null,
            ],
            ...Mpp::table($plan, $today),
            'revisi_menunggu' => $plan->revisis()->where('status', 'diajukan')->with('pembuat:id,name')->get()->map(fn (MppRevisi $r) => self::revisiView($r))->values(),
            'riwayat' => $plan->revisis()->where('status', '!=', 'diajukan')->with(['pembuat:id,name', 'penyetuju:id,name'])->limit(20)->get()->map(fn (MppRevisi $r) => self::revisiView($r))->values(),
            'golongans' => Golongan::orderBy('urutan')->orderBy('id')->get(['id', 'kode']),
            'sumber_options' => MppPlanItem::SUMBER,
        ]);
    }

    /** Changes to the plan: saved by an approver, else sent as a revision. */
    public function save(Request $request, MppPlan $plan): RedirectResponse
    {
        $this->authorizePlan($plan);
        $perubahan = self::diff($plan, self::validated($request, $plan));
        $catatan = $request->validate(['catatan' => ['nullable', 'string', 'max:255']])['catatan'] ?? null;
        $msg = $this->submit($request, $plan, $perubahan, $catatan);
        Inertia::flash('success', $msg);

        return back();
    }

    public function verify(Request $request, MppRevisi $revisi): RedirectResponse
    {
        $this->authorizePlan($revisi->plan);
        $data = $request->validate([
            'keputusan' => ['required', Rule::in(['setujui', 'tolak'])],
            'catatan' => ['nullable', 'string', 'max:255', 'required_if:keputusan,tolak'],
        ], ['catatan.required_if' => 'Tulis alasan penolakan.']);
        if ($revisi->status !== 'diajukan') {
            throw ValidationException::withMessages(['revisi' => 'Revisi ini sudah diputuskan.']);
        }
        $setuju = $data['keputusan'] === 'setujui';
        DB::transaction(function () use ($revisi, $setuju, $data, $request) {
            if ($setuju) {
                self::apply($revisi->plan, $revisi->perubahan);
            }
            $revisi->update(['status' => $setuju ? 'disetujui' : 'ditolak', 'catatan_verifikasi' => $data['catatan'] ?? null, 'verified_by' => $request->user()->id, 'verified_at' => now()]);
        });
        Pengajuan::mppHasil($revisi->fresh());
        Inertia::flash('success', $setuju ? 'Revisi disetujui; plan diperbarui.' : 'Revisi ditolak.');

        return back();
    }

    /** Withdrawn by whoever sent it (or set aside by an approver). */
    public function cancel(Request $request, MppRevisi $revisi): RedirectResponse
    {
        $this->authorizePlan($revisi->plan);
        $user = $request->user();
        abort_unless($revisi->created_by === $user->id || $user->canEdit('mpp-approve'), 403);
        if ($revisi->status === 'diajukan') {
            $revisi->update(['status' => 'dibatalkan']);
        }
        Inertia::flash('success', 'Revisi dibatalkan.');

        return back();
    }

    /** A vacancy for a position still short of people, opened in the form editor. */
    public function lowongan(Request $request, MppPlan $plan, Jabatan $jabatan): RedirectResponse
    {
        $this->authorizePlan($plan);
        abort_unless($request->user()->canEdit('recruitment') && $jabatan->work_location_id === $plan->work_location_id, 403);
        $plan->load('workLocation');
        $kebutuhan = $request->validate(['kebutuhan' => ['nullable', 'integer', 'min:1', 'max:999']])['kebutuhan'] ?? null;
        $form = DB::transaction(function () use ($plan, $jabatan, $kebutuhan) {
            $form = ApplicationForm::create([
                'title' => $jabatan->name,
                'posisi' => $jabatan->name,
                'lokasi' => $plan->workLocation->name,
                'jabatan_id' => $jabatan->id,
                'description' => "Dibutuhkan {$jabatan->name} untuk {$plan->workLocation->name}".($kebutuhan ? " ({$kebutuhan} orang)" : '').'. Lengkapi kualifikasi dan benefit sebelum form dibuka.',
                'is_open' => false,
            ]);
            $form->fields()->createMany(FormController::defaultFields());

            return $form;
        });
        Inertia::flash('success', "Lowongan {$jabatan->name} dibuat (masih ditutup). Lengkapi lalu buka form-nya.");

        return redirect()->route('recruitment.form.edit', $form);
    }

    public function export(MppPlan $plan): StreamedResponse
    {
        $this->authorizePlan($plan);

        return MppExcel::download($plan);
    }

    /** The plan from the MPP Excel: a preview first (JSON), then saved like any other change. */
    public function import(Request $request, MppPlan $plan): JsonResponse|RedirectResponse
    {
        $this->authorizePlan($plan);
        $data = $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'], 'simpan' => ['nullable', 'boolean']]);
        $read = MppExcel::read($data['file']->getRealPath(), $plan);
        $perubahan = self::diff($plan, $read['items']);
        if (! $request->boolean('simpan')) {
            return response()->json([
                'cocok' => $read['cocok'],
                'tidak_cocok' => $read['tidak_cocok'],
                'perubahan' => self::perubahanView($perubahan),
            ]);
        }
        Inertia::flash('success', 'Import Excel: '.$this->submit($request, $plan, $perubahan, 'Import Excel'));

        return back();
    }

    /** Master Data Personalia → Man Power Planning. */
    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'periode' => ['required', Rule::in(array_keys(MppAturan::PERIODE))],
            'golongan_sub_group' => ['nullable', 'array'],
            'golongan_sub_group.*' => ['nullable', 'integer', 'exists:golongans,id'],
        ]);
        MppAturan::current()->update(['periode' => $data['periode'], 'golongan_sub_group' => array_filter($data['golongan_sub_group'] ?? [])]);
        MppAturan::forget();
        Inertia::flash('success', 'Pengaturan Man Power Planning disimpan.');

        return back();
    }

    // -------------------------------------------------------------------------------------------

    private function submit(Request $request, MppPlan $plan, array $perubahan, ?string $catatan): string
    {
        if (! $perubahan) {
            throw ValidationException::withMessages(['items' => 'Tidak ada perubahan.']);
        }
        if ($plan->selesai->lt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['items' => 'Periode ini sudah lewat; plan-nya tidak bisa diubah.']);
        }
        $user = $request->user();
        if ($user->canEdit('mpp-approve')) {
            DB::transaction(function () use ($plan, $perubahan, $catatan, $user) {
                self::apply($plan, $perubahan);
                MppRevisi::create(['mpp_plan_id' => $plan->id, 'status' => 'disetujui', 'perubahan' => $perubahan, 'langsung' => true, 'catatan' => $catatan,
                    'created_by' => $user->id, 'verified_by' => $user->id, 'verified_at' => now()]);
            });

            return count($perubahan).' jabatan diperbarui.';
        }
        if ($plan->revisis()->where('status', 'diajukan')->exists()) {
            throw ValidationException::withMessages(['items' => 'Masih ada revisi yang menunggu persetujuan untuk plan ini; tunggu keputusannya atau batalkan dulu.']);
        }
        $revisi = MppRevisi::create(['mpp_plan_id' => $plan->id, 'status' => 'diajukan', 'perubahan' => $perubahan, 'catatan' => $catatan, 'created_by' => $user->id]);
        Pengajuan::mppRevisi($revisi);

        return 'Revisi '.count($perubahan).' jabatan diajukan; plan berubah setelah disetujui.';
    }

    private function authorizePlan(MppPlan $plan): void
    {
        abort_unless(AccessScope::allowsWorkLocation($plan->work_location_id), 403);
    }

    /** @return array<int, array<string, mixed>> jabatan id => the fields sent */
    private static function validated(Request $request, MppPlan $plan): array
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.jabatan_id' => ['required', 'integer', Rule::exists('jabatans', 'id')->where('work_location_id', $plan->work_location_id)],
            'items.*.plan' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'items.*.promosi' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'items.*.rotasi' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'items.*.demosi' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'items.*.keluar' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'items.*.pensiun' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'items.*.sakit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'items.*.bulan_pindah' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'items.*.bulan_penuhi' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'items.*.sumber' => ['sometimes', 'nullable', Rule::in(array_keys(MppPlanItem::SUMBER))],
            'items.*.golongan_id' => ['sometimes', 'nullable', 'integer', 'exists:golongans,id'],
            'items.*.keterangan' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], ['items.required' => 'Tidak ada perubahan.'], ['items.*.plan' => 'Plan']);

        return collect($data['items'])->mapWithKeys(fn (array $i) => [(int) $i['jabatan_id'] => collect($i)->only(MppPlanItem::FIELDS)->all()])->all();
    }

    /**
     * {"<jabatan id>": {"<field>": [before, after]}} for what differs from the plan now.
     *
     * @param  array<int, array<string, mixed>>  $input
     */
    public static function diff(MppPlan $plan, array $input): array
    {
        $items = $plan->items()->get()->keyBy('jabatan_id');
        $out = [];
        foreach ($input as $jabatanId => $fields) {
            $item = $items[$jabatanId] ?? null;
            foreach ($fields as $field => $after) {
                $before = $item?->{$field} ?? (in_array($field, ['plan', 'promosi', 'rotasi', 'demosi', 'keluar'], true) ? 0 : null);
                $after = $after === '' ? null : (is_numeric($after) && $field !== 'keterangan' ? (int) $after : $after);
                if ($after !== null && in_array($field, ['plan', 'promosi', 'rotasi', 'demosi', 'keluar'], true)) {
                    $after = (int) $after;
                }
                if ($before !== $after) {
                    $out[(string) $jabatanId][$field] = [$before, $after];
                }
            }
        }

        return $out;
    }

    /** Writes the "after" values. */
    public static function apply(MppPlan $plan, array $perubahan): void
    {
        foreach ($perubahan as $jabatanId => $fields) {
            $values = array_map(fn (array $pair) => $pair[1], $fields);
            $item = MppPlanItem::firstOrNew(['mpp_plan_id' => $plan->id, 'jabatan_id' => (int) $jabatanId]);
            $item->fill($values);
            foreach (['plan', 'promosi', 'rotasi', 'demosi', 'keluar'] as $f) {
                $item->{$f} ??= 0;
            }
            $item->save();
        }
    }

    private static function revisiView(MppRevisi $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'langsung' => $r->langsung,
            'catatan' => $r->catatan,
            'catatan_verifikasi' => $r->catatan_verifikasi,
            'pembuat' => $r->pembuat?->name,
            'created_by' => $r->created_by,
            'penyetuju' => $r->penyetuju?->name,
            'waktu' => $r->created_at?->toIso8601String(),
            'diputuskan' => $r->verified_at?->toIso8601String(),
            'perubahan' => self::perubahanView($r->perubahan),
        ];
    }

    /** The changes with the position names, for the page. */
    private static function perubahanView(array $perubahan): array
    {
        $names = Jabatan::whereIn('id', array_keys($perubahan))->pluck('name', 'id');
        $golongan = Golongan::pluck('kode', 'id');
        $show = fn (string $field, $v) => match (true) {
            $v === null => $field === 'pensiun' || $field === 'sakit' ? 'otomatis' : '—',
            $field === 'golongan_id' => $golongan[$v] ?? $v,
            $field === 'sumber' => MppPlanItem::SUMBER[$v] ?? $v,
            default => $v,
        };

        return collect($perubahan)->map(fn (array $fields, $id) => [
            'jabatan_id' => (int) $id,
            'jabatan' => $names[$id] ?? "Jabatan #{$id}",
            'fields' => collect($fields)->map(fn (array $pair, string $field) => ['field' => $field, 'before' => $show($field, $pair[0]), 'after' => $show($field, $pair[1])])->values(),
        ])->values()->all();
    }
}
