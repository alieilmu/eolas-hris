<?php

namespace App\Http\Controllers\Manpower;

use App\Http\Controllers\Controller;
use App\Models\Karyawan;
use App\Support\Karyawan\ColumnGroups;
use App\Support\Karyawan\ExcelReader;
use App\Support\Karyawan\ExcelWorkbook;
use App\Support\Karyawan\KaryawanImporter;
use App\Support\Karyawan\KaryawanRow;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Excel template, export, per-group update template and import (Admin / HR only, see routes). */
class KaryawanExcelController extends Controller
{
    /** Keeps a single upload to a size one request can process comfortably. */
    private const MAX_IMPORT_ROWS = 3000;

    public function template(): StreamedResponse
    {
        return ExcelWorkbook::download(ExcelWorkbook::template(), 'template-import-karyawan-lengkap.xlsx');
    }

    /** Every column, not just the visible groups, so the file can be edited and imported back. */
    public function export(Request $request): StreamedResponse
    {
        $karyawans = KaryawanRow::activeEmployees();
        $rows = $this->filteredRows($karyawans, $request->string('q')->trim()->lower()->value());

        return ExcelWorkbook::download(
            ExcelWorkbook::export($rows, KaryawanRow::maxAnak($karyawans)),
            'data-karyawan-lengkap-'.today()->format('Y-m-d').'.xlsx',
        );
    }

    public function groupTemplate(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'groups' => ['required', 'array', 'min:1'],
            'groups.*' => ['string', Rule::in(ColumnGroups::ids())],
            'prefill' => ['boolean'],
        ]);

        $karyawans = KaryawanRow::activeEmployees();
        $prefill = $request->boolean('prefill');

        return ExcelWorkbook::download(
            ExcelWorkbook::groupTemplate(
                $validated['groups'],
                max(KaryawanRow::maxAnak($karyawans), ColumnGroups::MIN_ANAK_COLUMNS),
                $prefill ? $this->filteredRows($karyawans, '') : null,
            ),
            $prefill ? 'data-karyawan-update.xlsx' : 'template-update-karyawan.xlsx',
        );
    }

    /**
     * With dry_run the rows are applied inside a transaction that is then rolled
     * back: the preview is produced by the very code that will save them.
     */
    public function import(Request $request, KaryawanImporter $importer): JsonResponse
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
    private function filteredRows($karyawans, string $q): Collection
    {
        $today = CarbonImmutable::today();

        return $karyawans
            ->filter(fn (Karyawan $k) => $q === ''
                || str_contains(mb_strtolower($k->nrp), $q)
                || str_contains(mb_strtolower($k->name), $q)
                || str_contains(mb_strtolower($k->jabatan?->name ?? ''), $q))
            ->map(fn (Karyawan $k) => KaryawanRow::flatten($k, $today))
            ->values()
            ->toBase();
    }
}
