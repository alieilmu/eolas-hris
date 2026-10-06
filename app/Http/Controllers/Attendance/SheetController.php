<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Departemen;
use App\Models\RosterPattern;
use App\Models\SubLocation;
use App\Support\Access\AccessScope;
use App\Support\Attendance\Period;
use App\Support\Attendance\Roll;
use App\Support\Attendance\Sheet;
use App\Support\Attendance\SheetExcel;
use App\Support\Attendance\SheetWriter;
use App\Support\Karyawan\ExcelWorkbook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Roster (the attendance plan) and Absensi (actual attendance): a month per
 * page, edited in the browser and saved as one batch.
 */
class SheetController extends Controller
{
    public function show(Request $request, string $type): Response
    {
        $period = Period::from($request->query('periode'));

        return Inertia::render('Attendance/Sheet', [
            'type' => $type,
            'period' => $period->toArray(),
            'periods' => Period::options(),
            'codes' => Sheet::codes(),
            'patterns' => $type === 'roster'
                ? RosterPattern::orderBy('nama')->get()->map(fn (RosterPattern $p) => ['id' => $p->id, 'nama' => $p->nama, 'langkah' => $p->langkah])
                : [],
            'rows' => Sheet::rows($type, $period),
            'locations' => AccessScope::locations(),
            'sub_locations' => AccessScope::subLocations(),
            'departemens' => Departemen::orderBy('name')->get(['id', 'name']),
            'can_edit' => $request->user()->canEdit($type),
        ]);
    }

    public function save(Request $request, string $type): RedirectResponse
    {
        $data = $request->validate([
            'changes' => ['required', 'array', 'max:60000'],
            'changes.*.karyawan_id' => ['required', 'integer'],
            'changes.*.tanggal' => ['required', 'date_format:Y-m-d'],
            'changes.*.shift_code_id' => ['nullable', 'integer'],
            'changes.*.sumber' => ['nullable', 'string', 'max:10'],
        ], [], ['changes' => 'Perubahan']);

        $result = SheetWriter::save($type, $data['changes']);
        $what = $type === 'roster' ? 'Roster' : 'Absensi';
        Inertia::flash('success', "{$what} disimpan: {$result['saved']} hari diisi"
            .($result['cleared'] ? ", {$result['cleared']} dikosongkan" : '')
            .($result['skipped'] ? "; {$result['skipped']} hari dilewati (belum masuk / sudah keluar)." : '.'));

        return back();
    }

    /**
     * Reads filled tables (one month or several) without saving: the page shows what is
     * in the file, then puts one open month into its draft or saves several at once.
     */
    public function import(Request $request, string $type): JsonResponse
    {
        $this->validateFile($request);

        return response()->json(SheetExcel::read($request->file('file')));
    }

    /** Saves every month of an imported file at once (e.g. a quarter or a year of roster). */
    public function importSave(Request $request, string $type): RedirectResponse
    {
        $this->validateFile($request);
        $read = SheetExcel::read($request->file('file'));
        if (! $read['changes']) {
            throw ValidationException::withMessages(['file' => $read['errors'][0]['message'] ?? 'Tidak ada sel yang bisa diimport.']);
        }

        $result = SheetWriter::save($type, array_map(fn (array $c) => [...$c, 'sumber' => 'import'], $read['changes']));
        $months = collect($read['months']);
        $span = $months->count() === 1 ? $months[0]['label'] : $months->first()['label'].' – '.$months->last()['label'];
        $skipped = collect($read['errors'])->where('row', '>', 0)->count();
        Inertia::flash('success', ($type === 'roster' ? 'Roster' : 'Absensi')." {$span} diimport: {$result['saved']} hari diisi"
            .($result['cleared'] ? ", {$result['cleared']} dikosongkan" : '')
            .($result['skipped'] ? "; {$result['skipped']} hari dilewati (belum masuk / sudah keluar)" : '')
            .($skipped ? "; {$skipped} baris bermasalah dilewati." : '.'));

        return back();
    }

    private function validateFile(Request $request): void
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'extensions:xlsx,xls,csv']], [], ['file' => 'File']);
    }

    /** One month, or a range of months ("dari" … "sampai", side by side on one sheet). */
    public function export(Request $request, string $type): StreamedResponse
    {
        $data = $request->validate([
            'periode' => ['nullable', 'date_format:Y-m'],
            'dari' => ['nullable', 'date_format:Y-m'],
            'sampai' => ['nullable', 'date_format:Y-m'],
            'format' => ['nullable', Rule::in(['kode', 'dws'])],
            'bentuk' => ['nullable', Rule::in(['bulanan', 'harian'])],
            'lokasi' => ['nullable', 'integer'],
            'sub' => ['nullable', 'string', 'max:10'],
            'departemen' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $single = Period::from($data['periode'] ?? null)->value();
        $periods = Period::range($data['dari'] ?? $single, $data['sampai'] ?? $data['dari'] ?? $single, SheetExcel::MAX_MONTHS);
        $format = $data['format'] ?? 'kode';
        $q = mb_strtolower(trim($data['q'] ?? ''));
        $sub = Roll::subLocationFilter($data['sub'] ?? null);

        $keep = fn (array $r) => (empty($data['lokasi']) || $r['work_location_id'] === (int) $data['lokasi'])
            && ($sub === null || ($sub === Roll::NO_SUB_LOCATION ? $r['sub_location_id'] === null : $r['sub_location_id'] === $sub))
            && (empty($data['departemen']) || $r['departemen_id'] === (int) $data['departemen'])
            && ($q === '' || str_contains(mb_strtolower($r['name']), $q) || str_contains($r['nrp'], $q));
        $months = array_map(fn (Period $p) => [$p, array_values(array_filter(Sheet::rows($type, $p), $keep))], $periods);

        $book = ($data['bentuk'] ?? 'bulanan') === 'harian'
            ? SheetExcel::daily($type, $months, $format)
            : SheetExcel::monthly($type, $months, $format);
        $span = $periods[0]->value().(count($periods) > 1 ? '_'.end($periods)->value() : '');

        return ExcelWorkbook::download($book, "{$type}-{$span}".($format === 'dws' ? '-dws' : '').'.xlsx');
    }

    /** Sub Lokasi Kerja per work location, for the filter. */
    public static function subLocations(): array
    {
        return SubLocation::orderBy('work_location_id')->orderBy('order')->orderBy('name')->get(['id', 'name', 'work_location_id'])->all();
    }
}
