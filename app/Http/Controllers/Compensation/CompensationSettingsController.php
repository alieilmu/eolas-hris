<?php

namespace App\Http\Controllers\Compensation;

use App\Support\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\LeavePolicy;
use App\Models\FieldLeaveEntitlement;
use App\Models\Grade;
use App\Models\PointOfHire;
use App\Models\FieldLeaveAllowance;
use App\Models\OpticalBenefitLimit;
use App\Models\MedicalBenefitLimit;
use App\Models\SalaryScale;
use App\Models\SalaryScalePeriod;
use App\Models\SalaryScaleAmount;
use App\Models\JobLevel;
use App\Models\SubLocation;
use App\Models\JobLevelAllowance;
use App\Models\WorkLocation;
use App\Models\PayrollRate;
use App\Models\PayrollSetting;
use App\Models\TerRate;
use App\Support\Employees\LookupValues;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pengaturan Kompensasi: Golongan per group (held by employees' profiles),
 * Struktur Skala Upah, Plafon Pengobatan and Plafon Kacamata.
 */
class CompensationSettingsController extends Controller
{
    private const PLAFON = ['medical' => MedicalBenefitLimit::class, 'optical' => OpticalBenefitLimit::class];

    public function index(): Response
    {
        $profiles = DB::table('employee_profiles')->whereNotNull('grade')->select('grade', DB::raw('count(*) as n'))->groupBy('grade')->pluck('n', 'grade');
        $plafons = [...MedicalBenefitLimit::all(), ...OpticalBenefitLimit::all()];
        $grades = Grade::withCount('salaryScales')->orderBy('sort_order')->orderBy('id')->get();

        return Inertia::render('Compensation/Settings', [
            'grades' => $grades->map(fn (Grade $g) => [
                'id' => $g->id,
                'grade_group' => $g->grade_group,
                'code' => $g->code,
                'employee_count' => (int) ($profiles[$g->code] ?? 0),
                'scale_count' => $g->salary_scales_count,
                'benefit_limit_count' => count(array_filter($plafons, fn ($p) => in_array($g->id, $p->grade_ids, true))),
            ]),
            'grade_groups' => array_values(array_unique([...Grade::GRUP, ...$grades->pluck('grade_group')->all()])),
            'period_column' => SalaryScalePeriod::with('workLocation')->orderBy('sort_order')->orderBy('year')->get()->map(fn (SalaryScalePeriod $k) => [
                'id' => $k->id, 'year' => $k->year, 'work_location_id' => $k->work_location_id, 'label' => $k->label(),
            ]),
            'scale' => SalaryScale::with(['grade', 'value'])->get()
                ->sortBy([fn ($a, $b) => $a->grade->sort_order <=> $b->grade->sort_order, fn ($a, $b) => $a->sort_order <=> $b->sort_order])
                ->values()
                ->map(fn (SalaryScale $s) => [
                    'id' => $s->id,
                    'grade_id' => $s->grade_id,
                    'code' => $s->grade->code,
                    'grade_group' => $s->grade->grade_group,
                    'step' => $s->step,
                    'value' => (object) $s->value->pluck('amount', 'salary_scale_period_id')->all(),
                ]),
            'medical_benefit_limit' => MedicalBenefitLimit::orderBy('sort_order')->orderBy('id')->get()->map(fn (MedicalBenefitLimit $p) => self::benefitLimitRow($p) + ['single' => $p->single, 'family' => $p->family]),
            'optical_benefit_limit' => OpticalBenefitLimit::orderBy('sort_order')->orderBy('id')->get()->map(fn (OpticalBenefitLimit $p) => self::benefitLimitRow($p) + ['frame' => $p->frame, 'lens' => $p->lens]),
            'job_levels' => JobLevel::orderByDesc('layer')->orderBy('name')->get(['id', 'name', 'layer']),
            // Tunjangan Jabatan per SubGroup (fixed monthly allowance, part of the upah tetap).
            'position_allowance' => (object) JobLevelAllowance::pluck('amount', 'job_level_id')->map(fn ($n) => (int) $n)->all(),
            'work_locations' => WorkLocation::orderBy('sort_order')->get(['id', 'name']),
            // Cuti Tahunan & Besar.
            'leave_policy' => LeavePolicy::current()->only(['annual_entitlement_days', 'annual_validity_years', 'long_service_interval_years', 'long_service_days', 'long_service_taken_salary_multiplier', 'long_service_cashout_salary_multiplier', 'long_service_decision_months']),
            'field_leave' => FieldLeaveEntitlement::with(['workLocation:id,name,sort_order', 'subLocation:id,name'])->get()
                ->sortBy(fn (FieldLeaveEntitlement $l) => sprintf('%05d-%s', $l->workLocation?->sort_order ?? 0, $l->subLocation?->name ?? ''))
                ->values()
                ->map(fn (FieldLeaveEntitlement $l) => [
                    'id' => $l->id,
                    'work_location_id' => $l->work_location_id,
                    'sub_location_id' => $l->sub_location_id,
                    'location' => $l->workLocation?->name,
                    'sub_location' => $l->subLocation?->name,
                    'leave_days' => $l->leave_days,
                    'outbound_travel_days' => $l->outbound_travel_days,
                    'return_travel_days' => $l->return_travel_days,
                    'total' => $l->total(),
                ]),
            'sub_locations' => SubLocation::orderBy('name')->get(['id', 'name', 'work_location_id']),
            // Lumpsum Cuti Lapangan: site × POH × klasifikasi.
            'field_leave_allowance' => FieldLeaveAllowance::get(['work_location_id', 'point_of_hire_id', 'employee_class', 'amount']),
            'point_of_hire' => PointOfHire::orderBy('code')->get(['id', 'code', 'name']),
            'employee_class' => LookupValues::values('employee_class'),
            // Payroll: periods, tax method, potong gapok, BPJS / PPh21 figures per year, JKK per location.
            'payroll' => [
                'settings' => PayrollSetting::current()->only(['cutoff_day', 'default_tax_method', 'absence_divisor_mode', 'absence_divisor_days', 'payslip_password', 'loan_max_ratio', 'company_tax_id', 'company_nitku', 'payer_bank', 'payer_account']),
                'journal_accounts' => collect(\App\Support\Payroll\Reports\Accounts::chart())->map(fn ($a, $g) => ['group' => $g, 'number' => $a[0], 'name' => $a[1]])->values(),
                'components' => \App\Models\PayrollComponent::orderBy('sort_order')->get(['id', 'code', 'name', 'kind', 'nature', 'taxable', 'sort_order', 'is_active', 'is_system']),
                'component_kinds' => \App\Models\PayrollComponent::KINDS,
                'component_natures' => \App\Models\PayrollComponent::NATURES,
                'tax_methods' => PayrollSetting::TAX_METHODS,
                'divisor_modes' => PayrollSetting::DIVISOR_MODES,
                'rates' => PayrollRate::orderByDesc('year')->get()->map(fn (PayrollRate $r) => $r->only(['year', ...PayrollRate::RATES, ...PayrollRate::AMOUNTS])),
                'jkk' => WorkLocation::orderBy('sort_order')->get(['id', 'name', 'type', 'jkk_rate', 'minimum_wage']),
                'ter' => TerRate::orderBy('category')->orderBy('lower_bound')->get(['category', 'lower_bound', 'upper_bound', 'rate'])->groupBy('category'),
            ],
        ]);
    }

    // --- Payroll -----------------------------------------------------------------------

    public function updatePayroll(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cutoff_day' => ['required', 'integer', 'min:1', 'max:31'],
            'default_tax_method' => ['required', Rule::in(array_keys(PayrollSetting::TAX_METHODS))],
            'absence_divisor_mode' => ['required', Rule::in(array_keys(PayrollSetting::DIVISOR_MODES))],
            'absence_divisor_days' => ['required', 'integer', 'min:1', 'max:31'],
            'jkk' => ['present', 'array'],
            'jkk.*' => ['required', 'numeric', 'min:0', 'max:10'],
            'umk' => ['sometimes', 'array'],
            'umk.*' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'payslip_password' => ['sometimes', 'boolean'],
            'loan_max_ratio' => ['sometimes', 'integer', 'min:10', 'max:100'],
            'company_tax_id' => ['nullable', 'string', 'max:20', 'regex:/^[0-9.\-\s]*$/'],
            'company_nitku' => ['nullable', 'string', 'max:30', 'regex:/^[0-9.\-\s]*$/'],
            'payer_bank' => ['nullable', 'string', 'max:30'],
            'payer_account' => ['nullable', 'string', 'max:40'],
            'journal_accounts' => ['sometimes', 'array'],
            'journal_accounts.*.number' => ['required', 'string', 'max:20'],
            'journal_accounts.*.name' => ['required', 'string', 'max:80'],
        ], [], ['cutoff_day' => 'Tanggal cutoff', 'absence_divisor_days' => 'Pembagi potong gapok', 'jkk.*' => 'Tarif JKK', 'umk.*' => 'UMK']);
        if (isset($data['journal_accounts'])) {
            $data['journal_accounts'] = array_intersect_key($data['journal_accounts'], \App\Support\Payroll\Reports\Accounts::GROUPS);
        }
        PayrollSetting::current()->update(collect($data)->except(['jkk', 'umk'])->all());
        foreach ($data['jkk'] as $id => $rate) {
            WorkLocation::whereKey((int) $id)->update(['jkk_rate' => $rate]);
        }
        foreach ($data['umk'] ?? [] as $id => $wage) {
            WorkLocation::whereKey((int) $id)->update(['minimum_wage' => $wage ?: null]);
        }
        Audit::log('payroll.settings_changed', null, null, collect($data)->except(['jkk', 'umk'])->all() + ['jkk' => $data['jkk'], 'umk' => $data['umk'] ?? null]);
        Inertia::flash('success', 'Pengaturan payroll disimpan.');

        return back();
    }

    /** The BPJS / PPh21 figures of one year; a year not there yet is added. */
    public function updatePayrollRates(Request $request): RedirectResponse
    {
        $rules = ['year' => ['required', 'integer', 'min:2020', 'max:2100']];
        foreach (PayrollRate::RATES as $f) {
            $rules[$f] = ['required', 'numeric', 'min:0', 'max:100'];
        }
        foreach (PayrollRate::AMOUNTS as $f) {
            $rules[$f] = ['required', 'integer', 'min:0', 'max:100000000000'];
        }
        $data = $request->validate($rules);
        PayrollRate::updateOrCreate(['year' => $data['year']], $data);
        Audit::log('payroll.rates_changed', null, "Tarif {$data['year']}", $data);
        Inertia::flash('success', "Tarif payroll {$data['year']} disimpan.");

        return back();
    }

    // --- Golongan ------------------------------------------------------------------------

    public function storeGrade(Request $request): RedirectResponse
    {
        $data = $this->gradeData($request);
        // New codes go to the end of their group.
        $last = Grade::where('grade_group', $data['grade_group'])->max('sort_order') ?? Grade::max('sort_order') ?? -1;
        Grade::where('sort_order', '>', $last)->increment('sort_order');
        $grade = Grade::create([...$data, 'sort_order' => $last + 1]);
        Inertia::flash('success', "Golongan {$grade->code} ditambahkan ke {$grade->grade_group}.");

        return back();
    }

    public function updateGrade(Request $request, Grade $grade): RedirectResponse
    {
        $data = $this->gradeData($request, $grade);
        $old = $grade->code;
        DB::transaction(function () use ($grade, $data, $old) {
            $grade->update($data);
            if ($old !== $grade->code) {
                // Employees hold the code: they follow the rename.
                DB::table('employee_profiles')->where('grade', $old)->update(['grade' => $grade->code]);
            }
        });
        Inertia::flash('success', "Golongan {$grade->code} diperbarui.");

        return back();
    }

    public function destroyGrade(Grade $grade): RedirectResponse
    {
        $used = DB::table('employee_profiles')->where('grade', $grade->code)->count();
        if ($used) {
            throw ValidationException::withMessages(['code' => "Golongan {$grade->code} masih dipakai {$used} karyawan."]);
        }
        DB::transaction(function () use ($grade) {
            foreach ([...MedicalBenefitLimit::all(), ...OpticalBenefitLimit::all()] as $p) {
                if (in_array($grade->id, $p->grade_ids, true)) {
                    $p->update(['grade_ids' => array_values(array_diff($p->grade_ids, [$grade->id]))]);
                }
            }
            $grade->delete(); // its skala upah lines go with it
        });
        Inertia::flash('success', "Golongan {$grade->code} dihapus.");

        return back();
    }

    private function gradeData(Request $request, ?Grade $current = null): array
    {
        $data = $request->validate([
            'grade_group' => ['required', 'string', 'max:30'],
            'code' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9\-]+$/'],
        ], ['code.regex' => 'Kode golongan hanya huruf, angka dan "-".'], ['grade_group' => 'Group', 'code' => 'Kode golongan']);
        $data['grade_group'] = mb_strtoupper(trim($data['grade_group']));
        $data['code'] = mb_strtoupper(trim($data['code']));
        if (Grade::whereRaw('UPPER(code) = ?', [$data['code']])->when($current, fn ($q) => $q->whereKeyNot($current->id))->exists()) {
            throw ValidationException::withMessages(['code' => "Golongan {$data['code']} sudah ada."]);
        }

        return $data;
    }

    // --- Struktur Skala Upah ---------------------------------------------------------------

    public function storeScale(Request $request): RedirectResponse
    {
        $data = $this->scaleData($request);
        DB::transaction(function () use ($data) {
            $scale = SalaryScale::create(['grade_id' => $data['grade_id'], 'step' => $data['step'], 'sort_order' => (SalaryScale::max('sort_order') ?? -1) + 1]);
            $this->saveValue($scale, $data['value']);
        });
        Inertia::flash('success', 'Baris skala upah ditambahkan.');

        return back();
    }

    public function updateScale(Request $request, SalaryScale $salaryScale): RedirectResponse
    {
        $data = $this->scaleData($request);
        DB::transaction(function () use ($salaryScale, $data) {
            $salaryScale->update(['grade_id' => $data['grade_id'], 'step' => $data['step']]);
            $this->saveValue($salaryScale, $data['value']);
        });
        Inertia::flash('success', 'Skala upah diperbarui.');

        return back();
    }

    public function destroyScale(SalaryScale $salaryScale): RedirectResponse
    {
        $salaryScale->delete();
        Inertia::flash('success', 'Baris skala upah dihapus.');

        return back();
    }

    public function storePeriodColumn(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'work_location_id' => ['nullable', 'integer', 'exists:work_locations,id'],
        ], [], ['year' => 'Tahun', 'work_location_id' => 'Lokasi']);
        $taken = SalaryScalePeriod::where('year', $data['year'])
            ->when($data['work_location_id'] ?? null, fn ($q, $id) => $q->where('work_location_id', $id), fn ($q) => $q->whereNull('work_location_id'))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['year' => 'Kolom untuk tahun dan lokasi itu sudah ada.']);
        }
        $period_column = SalaryScalePeriod::create([...$data, 'sort_order' => (SalaryScalePeriod::max('sort_order') ?? -1) + 1]);
        Inertia::flash('success', "Kolom {$period_column->label()} ditambahkan.");

        return back();
    }

    public function destroyPeriodColumn(SalaryScalePeriod $period_column): RedirectResponse
    {
        $period_column->delete(); // its wages go with it
        Inertia::flash('success', "Kolom {$period_column->label()} dihapus.");

        return back();
    }

    private function scaleData(Request $request): array
    {
        $data = $request->validate([
            'grade_id' => ['required', 'integer', 'exists:grades,id'],
            'step' => ['nullable', 'string', 'max:20'],
            'value' => ['nullable', 'array'],
            'value.*' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
        ], [], ['grade_id' => 'Golongan', 'value.*' => 'Nominal']);
        $data['step'] = filled($data['step'] ?? null) ? trim($data['step']) : null;
        $data['value'] ??= [];

        return $data;
    }

    private function saveValue(SalaryScale $scale, array $value): void
    {
        $period_column = SalaryScalePeriod::pluck('id')->flip();
        foreach ($value as $kolomId => $amount) {
            if (! $period_column->has((int) $kolomId)) {
                continue;
            }
            if ($amount === null || $amount === '') {
                SalaryScaleAmount::where(['salary_scale_id' => $scale->id, 'salary_scale_period_id' => $kolomId])->delete();
            } else {
                SalaryScaleAmount::updateOrCreate(['salary_scale_id' => $scale->id, 'salary_scale_period_id' => (int) $kolomId], ['amount' => (int) $amount]);
            }
        }
    }

    // --- Plafon Pengobatan / Kacamata ------------------------------------------------------

    public function storeBenefitLimit(Request $request, string $type): RedirectResponse
    {
        $model = self::PLAFON[$type];
        $model::create([...$this->benefitLimitData($request, $type), 'sort_order' => ($model::max('sort_order') ?? -1) + 1]);
        Inertia::flash('success', 'Baris plafon '.$type.' ditambahkan.');

        return back();
    }

    public function updateBenefitLimit(Request $request, string $type, int $id): RedirectResponse
    {
        $benefit_limit = self::PLAFON[$type]::findOrFail($id);
        $benefit_limit->update($this->benefitLimitData($request, $type, $benefit_limit));
        Inertia::flash('success', "Plafon {$type} {$benefit_limit->layer} diperbarui.");

        return back();
    }

    public function destroyBenefitLimit(string $type, int $id): RedirectResponse
    {
        $benefit_limit = self::PLAFON[$type]::findOrFail($id);
        $benefit_limit->delete();
        Inertia::flash('success', "Plafon {$type} {$benefit_limit->layer} dihapus.");

        return back();
    }

    private function benefitLimitData(Request $request, string $type, ?Model $current = null): array
    {
        $amounts = $type === 'medical' ? ['single' => 'Lajang', 'family' => 'Keluarga'] : ['frame' => 'Bingkai', 'lens' => 'Lensa'];
        $data = $request->validate([
            'layer' => ['required', 'string', 'max:10'],
            'grade_ids' => ['present', 'array'],
            'grade_ids.*' => ['integer', 'exists:grades,id'],
            'job_level_ids' => ['required', 'array', 'min:1'],
            'job_level_ids.*' => ['integer', 'exists:job_levels,id'],
            'position_label_head_office' => ['nullable', 'string', 'max:100'],
            'position_label_site' => ['nullable', 'string', 'max:100'],
            ...array_map(fn () => ['required', 'integer', 'min:0', 'max:9999999999'], $amounts),
        ], ['job_level_ids.required' => 'Pilih minimal satu SubGroup.'], ['layer' => 'Layer', 'job_level_ids' => 'SubGroup', ...$amounts]);

        $data['grade_ids'] = array_values(array_unique(array_map('intval', $data['grade_ids'])));
        $data['job_level_ids'] = array_values(array_unique(array_map('intval', $data['job_level_ids'])));
        $data['layer'] = mb_strtoupper(trim($data['layer']));

        // A SubGroup belongs to one plafon row, so an employee's plafon is never ambiguous.
        $others = self::PLAFON[$type]::when($current, fn ($q) => $q->whereKeyNot($current->id))->get();
        $taken = [];
        foreach ($others as $other) {
            foreach (array_intersect($data['job_level_ids'], $other->job_level_ids) as $id) {
                $taken[] = JobLevel::find($id)?->name." (sudah di baris {$other->layer})";
            }
        }
        if ($taken) {
            throw ValidationException::withMessages(['job_level_ids' => 'SubGroup hanya boleh di satu baris plafon: '.implode(', ', $taken).'.']);
        }

        return $data;
    }

    private static function benefitLimitRow(Model $p): array
    {
        return [
            'id' => $p->id,
            'layer' => $p->layer,
            'grade_ids' => $p->grade_ids,
            'job_level_ids' => $p->job_level_ids,
            'position_label_head_office' => $p->position_label_head_office,
            'position_label_site' => $p->position_label_site,
        ];
    }

    /** Saves the Tunjangan Jabatan of every SubGroup at once ({sub_group_id: nominal}). */
    public function updateAllowance(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['present', 'array'],
            'amount.*' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
        ]);
        $ids = JobLevel::pluck('id')->all();
        foreach ($data['amount'] as $id => $amount) {
            if (in_array((int) $id, $ids, true)) {
                JobLevelAllowance::updateOrCreate(['job_level_id' => (int) $id], ['amount' => (int) ($amount ?? 0)]);
            }
        }
        Inertia::flash('success', 'Tunjangan Jabatan disimpan.');

        return back();
    }
}
