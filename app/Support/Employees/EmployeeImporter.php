<?php

namespace App\Support\Employees;

use App\Models\Position;
use App\Models\Employee;
use App\Models\EmployeeProfile;
use App\Models\EmploymentContract;
use App\Support\Access\AccessScope;
use App\Support\Organization\PositionComposer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies spreadsheet rows (keyed by header label) to the database, one by one,
 * and reports what happened to each. The same code backs the preview and the
 * real import — the caller wraps it in a transaction and rolls back for a
 * preview — so what the preview promises is exactly what gets saved.
 *
 * Rules: rows are matched by NRP; a blank cell never overwrites existing data;
 * PKWT and child blocks, when present, replace the stored list; an exited
 * employee is never reactivated from a spreadsheet.
 */
class EmployeeImporter
{
    /** Handled on the Karyawan record itself rather than the profile. */
    private const KARYAWAN_KEYS = ['employee_number', 'name', 'email', ...self::PLACEMENT_KEYS];

    /** Column key => JabatanComposer::fromNames() key. */
    private const PLACEMENT = [
        'work_location_name' => 'work_location', 'sub_location_name' => 'sub_location', 'job_level' => 'job_level', 'directorate_name' => 'directorate', 'division_name' => 'division',
        'department_name' => 'department', 'section_name' => 'section', 'discipline' => 'discipline',
    ];

    private const PLACEMENT_KEYS = ['work_location_name', 'sub_location_name', 'job_level', 'directorate_name', 'division_name', 'department_name', 'section_name', 'discipline'];

    /** @var array<string, string> column key => header label */
    private array $labelOf = [];

    /** @var array<string, string> group id => title */
    private array $groupTitle = [];

    /**
     * @param  list<array<string, mixed>>  $rows  header label => cell value; optional "__row" = sheet row number
     */
    public function run(array $rows, bool $updateOnly = false): array
    {
        $anakInFile = $this->widestChildBlock($rows);
        $columns = ExcelSchema::columns(max($anakInFile, ColumnGroups::MIN_ANAK_COLUMNS));
        $this->labelOf = array_column($columns, 'label', 'key');
        $this->groupTitle = array_column(ColumnGroups::build(), 'title', 'id');

        $writable = array_filter($columns, fn (array $c) => ! $c['read_only']);
        $isBlock = fn (string $key) => str_starts_with($key, 'contract_') || str_starts_with($key, 'child_');
        $profileColumns = array_filter($writable, fn (array $c) => ! in_array($c['key'], self::KARYAWAN_KEYS, true) && ! $isBlock($c['key']));
        $trackedColumns = array_filter($writable, fn (array $c) => $c['key'] !== 'employee_number' && ! $isBlock($c['key']));

        $results = [];
        $seenNrp = [];
        $counts = ['new' => 0, 'update' => 0, 'unchanged' => 0];

        foreach ($rows as $idx => $row) {
            $rowNum = $row['__row'] ?? $idx + 2; // the header is row 1
            $employee_number = EmployeeNumber::normalize($this->text($row[ExcelSchema::KEY_LABEL] ?? null));
            $nama = $this->text($row[ExcelSchema::header('name')] ?? null) ?? '';
            $placementCells = [];
            foreach (self::PLACEMENT as $key => $name) {
                $placementCells[$name] = $this->text($row[$this->labelOf[$key]] ?? null);
            }
            $emailCell = $this->text($row[$this->labelOf['email']] ?? null);

            $skip = function (string $reason) use (&$results, $rowNum, $employee_number, $nama): void {
                $results[] = $this->result($rowNum, $employee_number, $nama, 'skipped', reason: $reason);
            };

            if ($employee_number === '') {
                $skip('Kolom "'.ExcelSchema::KEY_LABEL.'" wajib diisi.');

                continue;
            }
            if (isset($seenNrp[mb_strtolower($employee_number)])) {
                $skip("NRP \"{$employee_number}\" muncul lebih dari sekali di file ini.");

                continue;
            }

            $existing = Employee::where('employee_number', $employee_number)->first();
            // Outside the account's locations (Administrasi → Pengguna & Akses): neither updated nor created again.
            if (! $existing && Employee::withoutGlobalScope('access')->where('employee_number', $employee_number)->exists()) {
                $skip("NRP \"{$employee_number}\" milik karyawan di luar lokasi akun Anda.");

                continue;
            }
            $isNew = ! $existing;

            if ($isNew && $updateOnly) {
                $skip("NRP \"{$employee_number}\" belum terdaftar (mode \"hanya perbarui\" aktif — karyawan baru tidak dibuat).");

                continue;
            }

            // --- Placement -----------------------------------------------------
            // Work Location + Sub Lokasi + SubGroup + unit / Nama Jabatan; cells left blank keep the
            // current placement. It fills the plan's matching position; without one the employee
            // waits to be placed in Struktur Organisasi.
            $placementAttributes = null;
            $placementNote = null;
            if (array_filter($placementCells, fn ($v) => $v !== null)) {
                [$placement, $placementError] = PositionComposer::fromNames($placementCells, $existing?->placement() ?? []);
                // Only inside the account's locations (Administrasi → Pengguna & Akses).
                if ($placement && ! AccessScope::allowsWorkLocation((int) ($placement['work_location_id'] ?? 0))) {
                    $skip('Penempatan: lokasi kerja ini di luar akses akun Anda.');

                    continue;
                }
                [$position, $placementError, $positionName, $created] = $placement ? PositionComposer::findForEmployee($placement, $existing?->position) : [null, $placementError, null, false];
                if ($placementError) {
                    $skip("Penempatan: {$placementError}");

                    continue;
                }
                $placementAttributes = [...PositionComposer::employeePlacement($placement), 'position_id' => $position?->id];
                if (! $position) {
                    $placementNote = "Jabatan {$positionName} belum ada di Plan Struktur Organisasi — karyawan menunggu ditempatkan di Struktur Organisasi.";
                } elseif ($created) {
                    $placementNote = "Jabatan {$position->name} dibuat di Sub Lokasi {$position->subLocation?->name}.";
                }
            } elseif ($isNew) {
                $skip("NRP \"{$employee_number}\" belum terdaftar. Untuk menambah karyawan baru, isi Nama, Work Location dan SubGroup (plus kolom unit-nya)"
                    .'; untuk memperbarui karyawan yang ada, periksa kembali NRP-nya.');

                continue;
            }
            if ($isNew && $nama === '') {
                $skip("Karyawan baru (NRP \"{$employee_number}\") wajib mengisi Nama.");

                continue;
            }

            // A new NRP must follow J LL YY NNN with registered codes; existing ones are left as they are.
            if ($isNew && ($nrpError = EmployeeNumber::error($employee_number))) {
                $skip($nrpError);

                continue;
            }

            // --- profile values --------------------------------------------
            $profileData = [];
            $error = null;
            foreach ($profileColumns as $col) {
                $raw = $row[$col['label']] ?? null;
                if ($this->isBlank($raw)) {
                    continue; // blank never overwrites existing data
                }
                [$value, $error] = $this->parse($col, $raw);
                if ($error) {
                    break;
                }
                $profileData[$col['key']] = $value;
            }
            $error ??= ProfileRules::numberFormatError($profileData)
                ?? ProfileRules::duplicateNumberError($profileData, $existing?->id)
                ?? $this->missingRequiredError($existing, $nama, $placementAttributes !== null, $profileData);
            if ($error) {
                $skip($error);

                continue;
            }

            [$periods, $pkwtError] = $this->readContract($row);
            $children = $this->readChildren($row, $anakInFile);

            // --- write -------------------------------------------------------
            // New employees start Aktif; an existing status (incl. Keluar) is never changed from a spreadsheet.
            $finalStatus = $existing->status ?? Employee::STATUS_ACTIVE;
            $finalJabatan = $placementAttributes ? $placementAttributes['position_id'] : $existing->position_id;
            $warnings = $placementNote ? [$placementNote] : [];

            // An import may put several people on one position, even one normally held by a single
            // person (SubGroup layer 3 and up): the row goes through, with a note.
            $becomesHolder = $isNew || $finalJabatan !== $existing->position_id;
            if ($finalJabatan && $finalStatus === Employee::STATUS_ACTIVE && $becomesHolder && ($shared = $this->sharedPositionNote($finalJabatan, $existing?->id))) {
                $warnings[] = $shared;
            }
            if ($existing?->status === Employee::STATUS_TERMINATED) {
                $warnings[] = "NRP \"{$employee_number}\" berstatus Keluar ({$existing->termination_reason}). Datanya tetap diperbarui, tapi karyawan ini tidak diaktifkan kembali.";
            }

            try {
                $before = $existing ? $this->snapshot($existing->id) : null;

                // A savepoint per row: a failure undoes only this row.
                $employee = DB::transaction(function () use ($existing, $employee_number, $nama, $emailCell, $finalStatus, $placementAttributes, $profileData, $periods, $pkwtError, $children) {
                    $employee = $existing ?? new Employee(['employee_number' => $employee_number]);
                    $employee->fill([
                        'name' => $nama !== '' ? $nama : $employee->name,
                        'email' => $emailCell ?? $employee->email,
                        'status' => $finalStatus,
                        ...($placementAttributes ?? []),
                    ])->save();

                    $profile = $employee->profile()->updateOrCreate([], $profileData);

                    if (! $pkwtError && $periods) {
                        $profile->contracts()->delete();
                        $profile->contracts()->createMany($periods);
                    }
                    if ($children) {
                        $profile->children()->delete();
                        $profile->children()->createMany($children);
                    }

                    return $employee;
                });

                $after = $this->snapshot($employee->id);
            } catch (Throwable $e) {
                report($e);
                $skip('Gagal menyimpan baris ini: '.$e->getMessage());

                continue;
            }

            if ($pkwtError) {
                $warnings[] = "{$pkwtError} Data PKWT baris ini tidak diubah.";
            }
            // Digits 4–5 should match the year of Tanggal Masuk (a warning, not a rejection).
            if ($isNew || isset($profileData['hire_date'])) {
                $hired = $employee->profile()->first()?->hire_date;
                if ($w = EmployeeNumber::yearWarning($employee_number, $hired)) {
                    $warnings[] = $w;
                }
            }

            // --- what actually changed --------------------------------------
            $changes = [];
            foreach ($trackedColumns as $col) {
                $b = $before['values'][$col['key']] ?? ($col['key'] === 'is_permanent' ? 'Tidak' : null);
                $a = $after['values'][$col['key']] ?? null;
                if ($b !== $a) {
                    $changes[] = $this->change($col['group_id'], $col['label'], $b, $a);
                }
            }
            foreach (['contract' => ['Kontrak PKWT', 'periode PKWT', 'periode'], 'children' => ['Data Anak', 'anak', 'anak']] as $block => [$label, $noun, $unit]) {
                if (($before[$block] ?? null) !== $after[$block]) {
                    $changes[] = $this->change($block, $label, $before[$block] ?? null, $after[$block]);
                    $nb = $before["{$block}_count"] ?? 0;
                    $na = $after["{$block}_count"];
                    if ($na < $nb) {
                        $warnings[] = "Jumlah {$noun} berkurang dari {$nb} menjadi {$na} — pastikan file memuat SEMUA {$unit}.";
                    }
                }
            }

            if ($w = ProfileRules::accountHolderWarning($after['holder'], $employee->name)) {
                $warnings[] = $w;
            }

            $action = $isNew ? 'new' : ($changes ? 'update' : 'unchanged');
            $counts[$action]++;
            $seenNrp[mb_strtolower($employee_number)] = true;

            $results[] = $this->result($rowNum, $employee_number, $employee->name, $action,
                changes: $changes,
                warnings: $warnings,
                before: $isNew ? null : Completeness::overallPercent($before['scores']),
                after: $after['scores'],
            );
        }

        return [
            'update_only' => $updateOnly,
            'total_rows' => count($rows),
            'created' => $counts['new'],
            'updated' => $counts['update'],
            'unchanged' => $counts['unchanged'],
            'rows' => $results,
            ...$this->inspectColumns($rows),
            'skipped' => array_values(array_map(
                fn (array $r) => ['row' => $r['row'], 'reason' => $r['reason']],
                array_filter($results, fn (array $r) => $r['action'] === 'skipped'),
            )),
        ];
    }

    /**
     * A note when a position normally held by one person (SubGroup layer 3 and
     * up) gets another active holder through the import.
     */
    private function sharedPositionNote(int $positionId, ?int $exceptKaryawanId): ?string
    {
        $position = Position::with('jobLevel')->find($positionId);
        if (! $position?->jobLevel?->isSingleHolder()) {
            return null;
        }

        $holders = Employee::active()->where('position_id', $positionId)
            ->when($exceptKaryawanId, fn ($q) => $q->where('id', '!=', $exceptKaryawanId))
            ->orderBy('name')->pluck('name');
        if ($holders->isEmpty()) {
            return null;
        }

        return "Jabatan \"{$position->name}\" sudah diisi {$holders->join(', ', ' dan ')} — karyawan ini tetap diimpor, jadi jabatan ini diisi ".($holders->count() + 1).' orang.';
    }

    /**
     * Groups 1 and 2 must be complete once this row is applied. A value can come
     * from the file or already be stored, so a partial-update file for an
     * existing employee does not need to repeat them.
     */
    private function missingRequiredError(?Employee $existing, string $nama, bool $placed, array $profileData): ?string
    {
        $stored = $existing?->profile;

        $missing = array_filter(ExcelSchema::REQUIRED_KEYS, fn (string $key) => match ($key) {
            'employee_number' => false, // checked first, a row cannot get here without it
            'name' => $nama === '' && ! $existing,
            'job_level', 'work_location_name' => ! $placed && ! $existing,
            default => ! array_key_exists($key, $profileData) && $this->isBlank($stored?->{$key}),
        });

        if (! $missing) {
            return null;
        }

        $labels = implode(', ', array_map(fn (string $key) => $this->labelOf[$key], $missing));

        return $existing
            ? "Kolom wajib belum terisi (di file maupun di data tersimpan): {$labels}."
            : "Kolom wajib untuk karyawan baru belum diisi: {$labels}.";
    }

    // --- reading cells ------------------------------------------------------

    /** @return array{0: mixed, 1: ?string} [value, error] */
    private function parse(array $col, mixed $raw): array
    {
        $label = $col['label'];
        $shown = is_scalar($raw) ? (string) $raw : '';

        return match ($col['type']) {
            'date' => ($d = $this->date($raw)) === 'invalid'
                ? [null, "Kolom \"{$label}\" bukan tanggal yang valid (\"{$shown}\")."]
                : [$d->toDateString(), null],
            'number' => ($n = $this->number($raw)) === null
                ? [null, "Kolom \"{$label}\" harus berupa angka (\"{$shown}\")."]
                : [$col['key'] === 'medical_benefit_limit' ? $n : (int) $n, null],
            'boolean' => [in_array(mb_strtolower(trim($shown)), ['ya', 'iya', 'yes', 'true', '1', 'y'], true), null],
            default => $this->choice($col, $this->text($raw)),
        };
    }

    /**
     * Columns backed by a choice list must hold one of its values; the match is
     * case-insensitive and stores the list's own spelling.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function choice(array $col, ?string $value): array
    {
        $key = $col['key'];
        $isListed = isset(ProfileSections::MASTER_LISTS[$key]) || in_array($key, ['gender', 'grade'], true);
        if ($value === null || ! $isListed) {
            return [$value, null];
        }

        $options = ProfileSections::optionsFor($key);
        foreach ($options as $option) {
            if (mb_strtolower($option) === mb_strtolower($value)) {
                return [$option, null];
            }
        }

        return [null, "Kolom \"{$col['label']}\" berisi \"{$value}\", tidak ada di daftar pilihan (".implode(', ', $options).'). Lihat sheet Referensi atau tambahkan di Pengaturan Core HR.'];
    }

    /** Excel serial number, DD.MM.YYYY, YYYY-MM-DD, DD/MM/YYYY or DD-MM-YYYY. */
    private function date(mixed $raw): CarbonImmutable|string
    {
        if (is_int($raw) || is_float($raw)) {
            // Excel counts days from 1899-12-30 (it treats 1900 as a leap year).
            return $raw >= 1 && $raw < 110000
                ? CarbonImmutable::create(1899, 12, 30)->addDays((int) floor($raw))
                : 'invalid';
        }

        $s = trim((string) $raw);
        foreach (['d.m.Y', 'j.n.Y', 'Y-m-d', 'd/m/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y', 'Y/m/d'] as $format) {
            // Carbon throws on a format mismatch; the round-trip check rejects overflow like 31/02.
            try {
                $d = CarbonImmutable::createFromFormat("!{$format}", $s);
            } catch (InvalidFormatException) {
                continue;
            }
            if ($d && $d->format($format) === $s && $d->year >= 1900 && $d->year <= 2200) {
                return $d;
            }
        }

        return 'invalid';
    }

    /** Accepts "20000000", "20.000.000", "Rp 20.000.000" and "20000000.5". */
    private function number(mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        $s = trim((string) $raw);
        if (is_numeric($s)) {
            return (float) $s;
        }
        $digits = preg_replace('/\D/', '', $s);

        return $digits === '' ? null : (float) $digits;
    }

    /** Trimmed text, or null when blank. Whole numbers typed without text format lose their ".0". */
    private function text(mixed $raw): ?string
    {
        if ($this->isBlank($raw)) {
            return null;
        }
        if (is_float($raw) && floor($raw) === $raw && abs($raw) < 1e15) {
            return (string) (int) $raw;
        }

        return trim((string) $raw);
    }

    private function isBlank(mixed $raw): bool
    {
        return $raw === null || (is_string($raw) && trim($raw) === '');
    }

    /** @return array{0: list<array>, 1: ?string} [periods, error] */
    private function readContract(array $row): array
    {
        $periods = [];
        foreach (range(1, EmployeeProfile::PKWT_MAX_PERIODS) as $i) {
            $name = EmploymentContract::label($i);
            $startRaw = $row[$this->labelOf["contract_{$i}_start_date"]] ?? null;
            $endRaw = $row[$this->labelOf["contract_{$i}_end_date"]] ?? null;
            if ($this->isBlank($startRaw) && $this->isBlank($endRaw)) {
                continue;
            }
            if ($this->isBlank($startRaw) || $this->isBlank($endRaw)) {
                return [[], "{$name} harus mengisi tanggal kontrak DAN tanggal berakhir."];
            }
            $start = $this->date($startRaw);
            $end = $this->date($endRaw);
            if ($start === 'invalid' || $end === 'invalid') {
                return [[], "Tanggal {$name} tidak valid."];
            }
            if ($end->lt($start)) {
                return [[], "{$name}: tanggal berakhir lebih awal dari tanggal kontrak."];
            }
            $periods[] = [
                'sequence_no' => count($periods) + 1,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ];
        }

        return [$periods, null];
    }

    /** @return list<array> */
    private function readChildren(array $row, int $anakInFile): array
    {
        $children = [];
        foreach (range(1, max($anakInFile, 1)) as $i) {
            $nama = $this->text($row[$this->labelOf["child_{$i}_name"] ?? ''] ?? null);
            if ($nama === null) {
                continue;
            }
            $born = $this->isBlank($birth = $row[$this->labelOf["child_{$i}_birth_date"]] ?? null) ? null : $this->date($birth);
            $children[] = [
                'sort_order' => count($children) + 1,
                'name' => $nama,
                'birth_date' => $born instanceof CarbonInterface ? $born->toDateString() : null,
                'education_level' => $this->childEducation($this->text($row[$this->labelOf["child_{$i}_education_level"]] ?? null)),
                'employment_status' => $this->text($row[$this->labelOf["child_{$i}_employment_status"]] ?? null),
                'marital_status' => $this->text($row[$this->labelOf["child_{$i}_marital_status"]] ?? null),
            ];
        }

        return $children;
    }

    /** Spelled as in the master list when it matches one; kept as typed otherwise. */
    private function childEducation(?string $value): ?string
    {
        return $value === null ? null : (LookupValues::canonical('child_education_level', $value) ?? $value);
    }

    /** Widest child block present in the file decides how many anak columns to read. */
    private function widestChildBlock(array $rows): int
    {
        $widest = 0;
        foreach ($rows as $row) {
            for ($i = ExcelSchema::MAX_ANAK_COLUMNS; $i > $widest; $i--) {
                if (! $this->isBlank($row[ExcelSchema::header("child_{$i}_name")] ?? null)) {
                    $widest = $i;
                    break;
                }
            }
        }

        return $widest;
    }

    /** Headers nobody recognizes (likely typos), and which groups the file carries. */
    private function inspectColumns(array $rows): array
    {
        $headers = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            foreach (array_keys($row) as $h) {
                $headers[$h] = true;
            }
        }
        unset($headers['__row']);

        $all = ExcelSchema::columns(ExcelSchema::MAX_ANAK_COLUMNS);
        $known = [ExcelSchema::KEY_LABEL => true];
        $perGroup = [];
        foreach ($all as $c) {
            $known[$c['label']] = true;
            if ($c['read_only']) {
                $known[$c['label'].ExcelSchema::AUTO_SUFFIX] = true;
            } elseif ($c['key'] !== 'employee_number' && isset($headers[$c['label']])) {
                $perGroup[$c['group_id']] = ($perGroup[$c['group_id']] ?? 0) + 1;
            }
        }

        $recognized = [];
        foreach ($this->groupTitle as $id => $title) {
            if (isset($perGroup[$id])) {
                $recognized[] = ['id' => $id, 'title' => $title, 'columns' => $perGroup[$id]];
            }
        }

        return [
            'recognized_groups' => $recognized,
            'unknown_columns' => array_values(array_filter(array_keys($headers), fn ($h) => ! isset($known[$h]))),
        ];
    }

    // --- before / after -------------------------------------------------------

    /** Displayable values of one employee, for the change list and completeness. */
    private function snapshot(int $employeeId): array
    {
        $k = Employee::with(EmployeeRow::RELATIONS)->findOrFail($employeeId);
        $p = $k->profile;

        $values = [
            ...array_intersect_key(EmployeeRow::flatten($k), array_flip(self::KARYAWAN_KEYS)),
            'position_name' => $k->position?->name,
            'is_permanent' => 'Tidak',
        ];
        foreach ($p?->attributesToArray() ?? [] as $key => $value) {
            $values[$key] = $value;
        }
        $values = array_map(fn ($v) => $this->display($v), $values);

        $periods = $p?->contracts ?? collect();
        $anak = $p?->children ?? collect();

        return [
            'values' => $values,
            'contract' => $periods->isEmpty() ? null : $periods
                ->map(fn (EmploymentContract $x) => str_replace('PKWT ', '', EmploymentContract::label($x->sequence_no))
                    .": {$x->start_date->format('d.m.Y')} s/d {$x->end_date->format('d.m.Y')}")
                ->join(' • '),
            'contract_count' => $periods->count(),
            'children' => $anak->isEmpty() ? null : $anak->count().' anak: '.$anak->pluck('name')->join(', '),
            'children_count' => $anak->count(),
            'holder' => $p?->bank_account_holder,
            'scores' => Completeness::score($p),
        ];
    }

    private function display(mixed $value): ?string
    {
        return match (true) {
            $value === null, $value === '' => null,
            is_bool($value) => $value ? 'Ya' : 'Tidak',
            is_float($value) && floor($value) === $value => (string) (int) $value,
            default => (string) $value,
        };
    }

    private function change(string $groupId, string $label, ?string $before, ?string $after): array
    {
        return [
            'group_id' => $groupId,
            'group_title' => $this->groupTitle[$groupId] ?? $groupId,
            'label' => $label,
            'before' => $before,
            'after' => $after,
        ];
    }

    private function result(
        int $row, string $employee_number, string $nama, string $action,
        ?string $reason = null, array $changes = [], array $warnings = [],
        ?int $before = null, ?array $after = null,
    ): array {
        return [
            'row' => $row,
            'employee_number' => $employee_number,
            'name' => $nama,
            'action' => $action,
            'reason' => $reason,
            'changes' => $changes,
            'groups' => array_values(array_unique(array_column($changes, 'group_title'))),
            'warnings' => $warnings,
            'completeness_before' => $before,
            'completeness_after' => $after ? Completeness::overallPercent($after) : null,
            'groups_after' => $after ? array_map(fn (string $id, string $title) => [
                'id' => $id,
                'title' => $title,
                // null when the group does not apply to this employee
                'percent' => $after[$id]['applicable'] && $after[$id]['total'] > 0
                    ? (int) round($after[$id]['filled'] / $after[$id]['total'] * 100)
                    : null,
            ], array_keys($this->groupTitle), $this->groupTitle) : [],
        ];
    }
}
