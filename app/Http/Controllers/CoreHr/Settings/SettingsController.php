<?php

namespace App\Http\Controllers\CoreHr\Settings;

use App\Http\Controllers\Controller;
use App\Models\Directorate;
use App\Models\Department;
use App\Models\Division;
use App\Models\Grade;
use App\Models\Position;
use App\Models\EmployeeCategory;
use App\Models\Employee;
use App\Models\PointOfHire;
use App\Models\LookupValue;
use App\Models\WorkforcePlanSetting;
use App\Models\Section;
use App\Models\JobLevel;
use App\Models\SubLocation;
use App\Models\WorkLocation;
use App\Support\Employees\EmployeeFormOptions;
use App\Support\Employees\LookupValues;
use App\Support\WorkforcePlanning\WorkforcePlanning;
use App\Support\Organization\OrgChart;
use Inertia\Inertia;
use Inertia\Response;

/** Pengaturan Core HR: every list the employee record draws from, on one page. */
class SettingsController extends Controller
{
    public function index(): Response
    {
        $nrps = Employee::pluck('employee_number');
        $countByPrefix = fn (int $start, int $length) => $nrps->countBy(fn (string $employee_number) => substr($employee_number, $start, $length));
        $jenisUse = $countByPrefix(0, 1);
        $lokasiUse = $countByPrefix(1, 2);

        return Inertia::render('CoreHr/Settings/Index', [
            'employee_number_format' => EmployeeNumberFormatController::props(),
            'type' => EmployeeCategory::orderBy('code')->get()->map(fn (EmployeeCategory $j) => [
                'id' => $j->id,
                'code' => $j->code,
                'name' => $j->name,
                'employee_count' => $jenisUse[$j->code] ?? 0,
            ]),
            'location' => PointOfHire::with('workLocation')->orderBy('code')->get()->map(fn (PointOfHire $l) => [
                'id' => $l->id,
                'code' => $l->code,
                'name' => $l->name,
                'work_location_id' => $l->work_location_id,
                'employee_count' => $lokasiUse[$l->code] ?? 0,
            ]),
            'work_locations' => WorkLocation::withCount(['employees', 'pointsOfHire'])->orderBy('sort_order')->get()->map(fn (WorkLocation $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'code' => $w->code,
                'type' => $w->type,
                'employee_count' => $w->employees_count,
                'location_count' => $w->points_of_hire_count,
                'position_count' => Position::where('work_location_id', $w->id)->count(),
            ]),
            'directorates' => Directorate::withCount(['divisions', 'positions'])->orderBy('sort_order')->get()->map(fn (Directorate $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'division_count' => $b->divisions_count,
                'position_count' => $b->positions_count,
            ]),
            // Highest layer first: the higher the layer, the more senior.
            'job_levels' => JobLevel::withCount('positions')->orderByDesc('layer')->orderBy('name')->get()->map(fn (JobLevel $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'layer' => $g->layer,
                'source' => $g->source,
                'title_prefix' => $g->title_prefix,
                'title_prefix_head_office' => $g->title_prefix_head_office,
                'position_count' => $g->positions_count,
            ]),
            'source_options' => JobLevel::SUMBER,
            'divisions' => Division::withCount(['departments', 'positions'])->orderBy('sort_order')->get()->map(fn (Division $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'directorate_id' => $d->directorate_id,
                'department_count' => $d->departments_count,
                'position_count' => $d->positions_count,
            ]),
            'departments' => Department::withCount(['positions', 'sections'])->orderBy('division_id')->orderBy('sort_order')->get()->map(fn (Department $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'division_id' => $d->division_id,
                'position_count' => $d->positions_count,
                'section_count' => $d->sections_count,
            ]),
            'sub_locations' => SubLocation::withCount(['positions', 'employees'])->orderBy('work_location_id')->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (SubLocation $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'work_location_id' => $s->work_location_id,
                    'position_count' => $s->positions_count,
                    'employee_count' => $s->employees_count,
                ]),
            'sections' => Section::withCount('positions')->orderBy('department_id')->orderBy('sort_order')->get()->map(fn (Section $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'department_id' => $s->department_id,
                'position_count' => $s->positions_count,
            ]),
            'positions' => Position::with(['jobLevel', 'workLocation', ...Position::UNIT_RELATIONS])->withCount(['employees', 'children'])
                ->orderBy('sort_order')->get()->map(fn (Position $j) => [
                    'id' => $j->id,
                    'name' => $j->name,
                    'job_level' => $j->jobLevel?->name,
                    'work_location' => $j->workLocation?->name,
                    'unit_label' => $j->unitLabel(),
                    'employee_count' => $j->employees_count,
                    'children_count' => $j->children_count,
                ]),
            'unit_options' => OrgChart::unitOptions(),
            // For "Tambah Jabatan": the same placement picker as for employees.
            'placement_options' => EmployeeFormOptions::placementOptions(),
            // Man Power Planning: the period of a plan, and the golongan its costs are estimated with.
            'workforce_planning' => [
                'period' => WorkforcePlanSetting::current()->period,
                'period_options' => WorkforcePlanSetting::PERIODE,
                'grade_by_job_level' => (object) (WorkforcePlanSetting::current()->grade_by_job_level ?? []),
                'grade_default' => (object) WorkforcePlanning::gradeDefaults(),
                'grades' => Grade::orderBy('sort_order')->orderBy('id')->get(['id', 'code']),
            ],
            'options' => collect(LookupValues::CATEGORIES)->map(fn (array $config, string $category) => [
                'category' => $category,
                'label' => $config['label'],
                'values' => LookupValue::where('category', $category)->orderBy('sort_order')->orderBy('id')->get()
                    ->map(fn (LookupValue $o) => [
                        'id' => $o->id,
                        'value' => $o->value,
                        'is_system' => $o->is_system,
                        'usage' => LookupValues::usage($category, $o->value),
                    ]),
            ])->values(),
        ]);
    }
}
