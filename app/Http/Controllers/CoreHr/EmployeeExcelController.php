<?php

namespace App\Http\Controllers\CoreHr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\Employees\ColumnGroups;
use App\Support\Employees\ExcelReader;
use App\Support\Employees\ExcelWorkbook;
use App\Support\Employees\EmployeeImporter;
use App\Support\Employees\EmployeeRow;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Excel template, export, per-group update template and import (Admin / HR only, see routes). */
class EmployeeExcelController extends Controller
{
    /** Keeps a single upload to a size one request can process comfortably. */
    private const MAX_IMPORT_ROWS = 3000;

    public function template(): StreamedResponse
    {
        return ExcelWorkbook::download(ExcelWorkbook::template(), 'template-import-employee-complete.xlsx');
    }

    /** Every column, not just the visible groups, so the file can be edited and imported back. */
    public function export(Request $request): StreamedResponse
    {
        $employees = EmployeeRow::activeEmployees();
        $rows = $this->filteredRows($employees, $request->string('q')->trim()->lower()->value());

        return ExcelWorkbook::download(
            ExcelWorkbook::export($rows, EmployeeRow::maxChildren($employees)),
            'data-employee-complete-'.today()->format('Y-m-d').'.xlsx',
        );
    }

    public function groupTemplate(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'groups' => ['required', 'array', 'min:1'],
            'groups.*' => ['string', Rule::in(ColumnGroups::ids())],
            'prefill' => ['boolean'],
        ]);

        $employees = EmployeeRow::activeEmployees();
        $prefill = $request->boolean('prefill');

        return ExcelWorkbook::download(
            ExcelWorkbook::groupTemplate(
                $validated['groups'],
                max(EmployeeRow::maxChildren($employees), ColumnGroups::MIN_ANAK_COLUMNS),
                $prefill ? $this->filteredRows($employees, '') : null,
            ),
            $prefill ? 'data-employee-update.xlsx' : 'template-update-employee.xlsx',
        );
    }

    /**
     * With dry_run the rows are applied inside a transaction that is then rolled
     * back: the preview is produced by the very code that will save them.
     */
    public function import(Request $request, EmployeeImporter $importer): JsonResponse
    {
        $request->validate([
            // By extension: content sniffing reports some valid .xlsx files as plain zip archives.
            'file' => ['required', 'file', 'max:10240', 'extensions:xlsx,xls,csv'],
            'dry_run' => ['boolean'],
            'update_only' => ['boolean'],
        ], [
            'file.required' => 'Pilih file Excel terlebih dahulu.',
            'file.extensions' => 'File harus berformat .xlsx, .xls, atau .csv.',
            'file.max' => 'Ukuran file maksimal 10 MB.',
        ]);

        try {
            $rows = ExcelReader::read($request->file('file'));
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'File tidak bisa dibaca. Pastikan formatnya .xlsx dan tidak rusak.'], 422);
        }

        if ($rows === []) {
            return response()->json(['message' => 'File tidak berisi baris data.'], 422);
        }
        if (count($rows) > self::MAX_IMPORT_ROWS) {
            return response()->json(['message' => 'File berisi lebih dari '.self::MAX_IMPORT_ROWS.' baris. Bagi menjadi beberapa file.'], 422);
        }

        $dryRun = $request->boolean('dry_run');

        DB::beginTransaction();
        try {
            $outcome = $importer->run($rows, $request->boolean('update_only'));
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $dryRun ? DB::rollBack() : DB::commit();

        return response()->json(['dry_run' => $dryRun, ...$outcome]);
    }

    /** @param  \Illuminate\Database\Eloquent\Collection<int, Karyawan>  $karyawans */
    private function filteredRows($employees, string $q): Collection
    {
        $today = CarbonImmutable::today();

        return $employees
            ->filter(fn (Employee $k) => $q === ''
                || str_contains(mb_strtolower($k->employee_number), $q)
                || str_contains(mb_strtolower($k->name), $q)
                || str_contains(mb_strtolower($k->position?->name ?? ''), $q))
            ->map(fn (Employee $k) => EmployeeRow::flatten($k, $today))
            ->values()
            ->toBase();
    }
}
