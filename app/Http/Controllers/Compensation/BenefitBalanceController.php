<?php

namespace App\Http\Controllers\Compensation;

use App\Http\Controllers\Controller;
use App\Models\BenefitClaim;
use App\Models\Department;
use App\Models\Employee;
use App\Models\SubLocation;
use App\Models\WorkLocation;
use App\Support\Access\AccessScope;
use App\Support\Time\Roll;
use App\Support\Compensation\AccountingMonth;
use App\Support\Compensation\BenefitEntitlement;
use App\Support\Compensation\Usage;
use App\Support\Excel\TableExcel;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sisa plafon per employee for one plafon year: Pengobatan per family, Kacamata
 * per family member, with what was claimed in each book month.
 */
class BenefitBalanceController extends Controller
{
    public function index(Request $request): Response
    {
        $current = AccountingMonth::currentYear();
        $year = $request->integer('year') ?: $current;
        // Per month as an object, also when nothing was claimed yet.
        $json = fn (array $rows) => array_map(fn (array $r) => ['month' => (object) $r['month']] + $r, $rows);
        [$pengobatanRows, $kacamataRows] = self::data($year);
        $first = (int) (BenefitClaim::min('benefit_year') ?? $current);

        return Inertia::render('Compensation/BenefitBalances', [
            'year' => $year,
            'years' => range(max($current + 1, $year), min($first, $year, $current - 1)),
            'months' => array_map(fn (string $b) => ['value' => $b, 'label' => AccountingMonth::label($b), 'span' => AccountingMonth::span($b)], AccountingMonth::months($year)),
            'current_month' => AccountingMonth::of(now()),
            'medical' => $json($pengobatanRows),
            'optical' => $json($kacamataRows),
            'locations' => AccessScope::locations(),
            'sub_locations' => AccessScope::subLocations(),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** The two tables with the filters of the page (lokasi, sub lokasi, departemen, search), one sheet each. */
    public function export(Request $request): StreamedResponse
    {
        $f = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'location' => ['nullable', 'integer'],
            'sub' => ['nullable', 'string', 'max:10'],
            'department' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $year = (int) ($f['year'] ?? AccountingMonth::currentYear());
        $location = isset($f['location']) ? (int) $f['location'] : null;
        $department = isset($f['department']) ? (int) $f['department'] : null;
        $sub = Roll::subLocationFilter($f['sub'] ?? null);
        $q = mb_strtolower(trim($f['q'] ?? ''));
        $keep = fn (array $r) => ($location === null || $r['work_location_id'] === $location)
            && ($sub === null || ($sub === Roll::NO_SUB_LOCATION ? $r['sub_location_id'] === null : $r['sub_location_id'] === $sub))
            && ($department === null || $r['department_id'] === $department)
            && ($q === '' || str_contains(mb_strtolower($r['name']), $q) || str_contains($r['employee_number'], $q) || str_contains(mb_strtolower($r['position'] ?? ''), $q));

        [$medical, $optical] = self::data($year);
        $medical = array_values(array_filter($medical, $keep));
        $optical = array_values(array_filter($optical, $keep));
        $months = AccountingMonth::months($year);
        $monthColumns = array_map(fn (string $b) => [AccountingMonth::label($b), 12, 'rupiah', 'sum' => true], $months);
        $perMonth = fn (array $r) => array_map(fn (string $b) => $r['month'][$b] ?? null, $months);
        $identity = fn (array $r) => [$r['employee_number'], $r['name'], $r['position'], $r['department'], $r['location'], $r['sub_location'], $r['grade'], $r['ptkp_status']];
        $identityColumns = [['Employee Number', 12], ['Name', 28], ['Position', 26], ['Department', 22], ['Location', 16], ['Sub Location', 16], ['Grade', 6], ['PTKP', 7]];

        $info = TableExcel::info([
            "Tahun plafon {$year}",
            $location ? 'Lokasi '.WorkLocation::whereKey($location)->value('name') : null,
            match (true) {
                $sub === Roll::NO_SUB_LOCATION => 'Tanpa sub lokasi',
                is_int($sub) => 'Sub lokasi '.SubLocation::whereKey($sub)->value('name'),
                default => null,
            },
            $department ? 'Departemen '.Department::whereKey($department)->value('name') : null,
            $q !== '' ? 'Cari "'.trim($f['q']).'"' : null,
        ]);

        return TableExcel::download("monitoring-benefit_limit-{$year}-".now()->format('Y-m-d').'.xlsx', [
            [
                'title' => "Medical Limit {$year}",
                'info' => $info,
                'freeze' => 3,
                'columns' => [
                    ['No', 5, 'int'], ...$identityColumns, ['Layer', 10], ['Family', 9],
                    ['Limit', 14, 'rupiah', 'sum' => true], ...$monthColumns,
                    ['Used', 14, 'rupiah', 'sum' => true], ['Pending', 12, 'rupiah', 'sum' => true], ['Remaining', 14, 'rupiah', 'sum' => true], ['Warning', 36],
                ],
                'rows' => array_map(fn (array $r, int $i) => [
                    $i + 1, ...$identity($r), $r['layer'], $r['family'] ? 'Ya' : 'Tidak',
                    $r['benefit_limit'], ...$perMonth($r),
                    $r['used'], $r['pending'] ?: null, $r['remaining'], $r['warning'],
                ], $medical, array_keys($medical)),
            ],
            [
                'title' => "Optical Limit {$year}",
                'info' => $info,
                'freeze' => 3,
                'columns' => [
                    ['No', 5, 'int'], ...$identityColumns, ['Member', 24], ['Relationship', 12],
                    ['Frame Limit', 13, 'rupiah'], ['Lens Limit', 13, 'rupiah'], ...$monthColumns,
                    ['Frame', 13, 'rupiah', 'sum' => true], ['Lens', 13, 'rupiah', 'sum' => true], ['Pending', 12, 'rupiah', 'sum' => true],
                    ['Frame Remaining', 13, 'rupiah'], ['Lens Remaining', 13, 'rupiah'], ['Last Frame', 14],
                ],
                'rows' => array_map(fn (array $r, int $i) => [
                    $i + 1, ...$identity($r), $r['members'], $r['relationship'],
                    $r['frame_limit'], $r['lens_limit'], ...$perMonth($r),
                    $r['frame'], $r['lens'], $r['pending'] ?: null, $r['frame_remaining'], $r['lens_remaining'], $r['last_frame'] ? AccountingMonth::label($r['last_frame']) : null,
                ], $optical, array_keys($optical)),
            ],
        ]);
    }

    /**
     * Every employee not yet out, with their plafon and use in $tahun.
     *
     * @return array{0: list<array>, 1: list<array>} Pengobatan rows, Kacamata rows
     */
    private static function data(int $year): array
    {
        $people = Employee::with(['position', 'jobLevel', 'department', 'section.department', 'profile.children', 'workLocation:id,name', 'subLocation:id,name'])
            ->where('status', '!=', Employee::STATUS_TERMINATED)
            ->orderBy('employee_number')
            ->get();
        $ids = $people->pluck('id');
        $e = BenefitEntitlement::shared();
        $medical = Usage::medical($ids, $year);
        $optical = Usage::optical($ids, $year);

        $pengobatanRows = [];
        $kacamataRows = [];
        foreach ($people as $k) {
            $department = $k->placementDepartment();
            $base = [
                'id' => $k->id,
                'employee_number' => $k->employee_number,
                'name' => $k->name,
                'position' => $k->position?->name,
                'job_level' => $k->jobLevel?->name,
                'department_id' => $department?->id,
                'department' => $department?->name,
                'work_location_id' => $k->work_location_id,
                'location' => $k->workLocation?->name,
                'sub_location_id' => $k->sub_location_id,
                'sub_location' => $k->subLocation?->name,
                'grade' => $k->profile?->grade,
                'ptkp_status' => BenefitEntitlement::statusPtkp($k),
                'warning' => $e->warning($k),
            ];
            $benefit_limit = $e->medicalBenefitLimit($k);
            $used = $medical[$k->id] ?? ['month' => [], 'total' => 0, 'pending' => 0];
            $pengobatanRows[] = $base + [
                'layer' => $e->medicalRule($k)?->layer,
                'family' => BenefitEntitlement::family($k),
                'benefit_limit' => $benefit_limit,
                'month' => $used['month'],
                'used' => $used['total'],
                // Pengajuan from the portal waiting for HR: they already hold their amount.
                'pending' => $used['pending'],
                'remaining' => $benefit_limit === null ? null : $benefit_limit - $used['total'] - $used['pending'],
            ];

            $rule = $e->opticalRule($k);
            foreach (BenefitEntitlement::members($k) as $m) {
                $u = $optical[$k->id][$m['key']] ?? Usage::emptyOptical();
                $kacamataRows[] = $base + [
                    'key' => "{$k->id}:{$m['key']}",
                    'members' => $m['name'],
                    'relationship' => $m['relationship'],
                    'frame_limit' => $rule?->frame,
                    'lens_limit' => $rule?->lens,
                    'month' => $u['month'],
                    'frame' => $u['frame'],
                    'lens' => $u['lens'],
                    'pending' => $u['frame_pending'] + $u['lens_pending'],
                    'frame_remaining' => $rule ? $rule->frame - $u['frame'] - $u['frame_pending'] : null,
                    'lens_remaining' => $rule ? $rule->lens - $u['lens'] - $u['lens_pending'] : null,
                    'last_frame' => $u['last_frame'],
                ];
            }
        }

        return [$pengobatanRows, $kacamataRows];
    }
}
