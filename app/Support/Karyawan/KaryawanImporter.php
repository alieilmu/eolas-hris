<?php

namespace App\Support\Karyawan;

use App\Models\Jabatan;
use App\Models\Karyawan;
use App\Models\KaryawanProfile;
use App\Models\PkwtPeriod;
use App\Support\Access\AccessScope;
use App\Support\Org\JabatanComposer;
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
class KaryawanImporter
{
    /** Handled on the Karyawan record itself rather than the profile. */
    private const KARYAWAN_KEYS = ['nrp', 'name', 'email', ...self::PLACEMENT_KEYS];

    /** Column key => JabatanComposer::fromNames() key. */
    private const PLACEMENT = [
        'work_location_name' => 'work_location', 'sub_location_name' => 'sub_location', 'sub_group' => 'sub_group', 'bod_name' => 'bod', 'divisi_name' => 'divisi',
        'departemen_name' => 'departemen', 'section_name' => 'section', 'bidang' => 'bidang',
    ];

    private const PLACEMENT_KEYS = ['work_location_name', 'sub_location_name', 'sub_group', 'bod_name', 'divisi_name', 'departemen_name', 'section_name', 'bidang'];

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
        $isBlock = fn (string $key) => str_starts_with($key, 'pkwt_') || str_starts_with($key, 'anak_');
        $profileColumns = array_filter($writable, fn (array $c) => ! in_array($c['key'], self::KARYAWAN_KEYS, true) && ! $isBlock($c['key']));
        $trackedColumns = array_filter($writable, fn (array $c) => $c['key'] !== 'nrp' && ! $isBlock($c['key']));

        $results = [];
        $seenNrp = [];
        $counts = ['baru' => 0, 'perbarui' => 0, 'tanpa-perubahan' => 0];

        foreach ($rows as $idx => $row) {
            $rowNum = $row['__row'] ?? $idx + 2; // the header is row 1
            $nrp = $this->text($row[ExcelSchema::KEY_LABEL] ?? null) ?? '';
            $nama = $this->text($row['Nama'] ?? null) ?? '';
            $placementCells = [];
            foreach (self::PLACEMENT as $key => $name) {
                $placementCells[$name] = $this->text($row[$this->labelOf[$key]] ?? null);
            }
            $emailCell = $this->text($row[$this->labelOf['email']] ?? null);

            $skip = function (string $reason) use (&$results, $rowNum, $nrp, $nama): void {
                $results[] = $this->result($rowNum, $nrp, $nama, 'dilewati', reason: $reason);
            };

            if ($nrp === '') {
                $skip('Kolom "'.ExcelSchema::KEY_LABEL.'" wajib diisi.');

                continue;
            }
            if (isset($seenNrp[mb_strtolower($nrp)])) {
                $skip("NRP \"{$nrp}\" muncul lebih dari sekali di file ini.");

                continue;
            }

            $existing = Karyawan::where('nrp', $nrp)->first();
            // Outside the account's locations (Settings → Akun & Akses): neither updated nor created again.
            if (! $existing && Karyawan::withoutGlobalScope('akses')->where('nrp', $nrp)->exists()) {
                $skip("NRP \"{$nrp}\" milik karyawan di luar lokasi akun Anda.");

                continue;
            }
            $isNew = ! $existing;

            if ($isNew && $updateOnly) {
                $skip("NRP \"{$nrp}\" belum terdaftar (mode \"hanya perbarui\" aktif — karyawan baru tidak dibuat).");

                continue;
            }

            // --- Placement -----------------------------------------------------
            // Work Location + Sub Lokasi + SubGroup + unit / Nama Jabatan; cells left blank keep the
            // current placement. It fills the plan's matching position; without one the employee
            // waits to be placed in Struktur Organisasi.
            $placementAttributes = null;
            $placementNote = null;
            if (array_filter($placementCells, fn ($v) => $v !== null)) {
                [$placement, $placementError] = JabatanComposer::fromNames($placementCells, $existing?->placement() ?? []);
                // Only inside the account's locations (Settings → Akun & Akses).
                if ($placement && ! AccessScope::allowsWorkLocation((int) ($placement['work_location_id'] ?? 0))) {
                    $skip('Penempatan: lokasi kerja ini di luar akses akun Anda.');

                    continue;
                }
                [$jabatan, $placementError, $jabatanName, $created] = $placement ? JabatanComposer::findForEmployee($placement, $existing?->jabatan) : [null, $placementError, null, false];
                if ($placementError) {
                    $skip("Penempatan: {$placementError}");

                    continue;
                }
                $placementAttributes = [...JabatanComposer::employeePlacement($placement), 'jabatan_id' => $jabatan?->id];
                if (! $jabatan) {
                    $placementNote = "Jabatan {$jabatanName} belum ada di Plan Struktur Organisasi — karyawan menunggu ditempatkan di Struktur Organisasi.";
                } elseif ($created) {
                    $placementNote = "Jabatan {$jabatan->name} dibuat di Sub Lokasi {$jabatan->subLocation?->name}.";
                }
            } elseif ($isNew) {
                $skip("NRP \"{$nrp}\" belum terdaftar. Untuk menambah karyawan baru, isi Nama, Work Location dan SubGroup (plus kolom unit-nya)"
                    .'; untuk memperbarui karyawan yang ada, periksa kembali NRP-nya.');

                continue;
            }
            if ($isNew && $nama === '') {
                $skip("Karyawan baru (NRP \"{$nrp}\") wajib mengisi Nama.");

                continue;
            }

            // A new NRP must follow J LL YY NNN with registered codes; existing ones are left as they are.
            if ($isNew && ($nrpError = Nrp::error($nrp))) {
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

            [$periods, $pkwtError] = $this->readPkwt($row);
            $children = $this->readChildren($row, $anakInFile);

            // --- write -------------------------------------------------------
            // New employees start Aktif; an existing status (incl. Keluar) is never changed from a spreadsheet.
            $finalStatus = $existing->status ?? Karyawan::STATUS_AKTIF;
            $finalJabatan = $placementAttributes ? $placementAttributes['jabatan_id'] : $existing->jabatan_id;
            $warnings = $placementNote ? [$placementNote] : [];

            // An import may put several people on one position, even one normally held by a single
            // person (SubGroup layer 3 and up): the row goes through, with a note.
            $becomesHolder = $isNew || $finalJabatan !== $existing->jabatan_id;
            if ($finalJabatan && $finalStatus === Karyawan::STATUS_AKTIF && $becomesHolder && ($shared = $this->sharedPositionNote($finalJabatan, $existing?->id))) {
                $warnings[] = $shared;
            }
            if ($existing?->status === Karyawan::STATUS_KELUAR) {
                $warnings[] = "NRP \"{$nrp}\" berstatus Keluar ({$existing->alasan_keluar}). Datanya tetap diperbarui, tapi karyawan ini tidak diaktifkan kembali.";
            }

            try {
                $before = $existing ? $this->snapshot($existing->id) : null;

                // A savepoint per row: a failure undoes only this row.
                $karyawan = DB::transaction(function () use ($existing, $nrp, $nama, $emailCell, $finalStatus, $placementAttributes, $profileData, $periods, $pkwtError, $children) {
                    $karyawan = $existing ?? new Karyawan(['nrp' => $nrp]);
                    $karyawan->fill([
                        'name' => $nama !== '' ? $nama : $karyawan->name,
                        'email' => $emailCell ?? $karyawan->email,
                        'status' => $finalStatus,
                        ...($placementAttributes ?? []),
                    ])->save();

                    $profile = $karyawan->profile()->updateOrCreate([], $profileData);

                    if (! $pkwtError && $periods) {
                        $profile->pkwtPeriods()->delete();
                        $profile->pkwtPeriods()->createMany($periods);
                    }
                    if ($children) {
                        $profile->anak()->delete();
                        $profile->anak()->createMany($children);
                    }

                    return $karyawan;
                });

                $after = $this->snapshot($karyawan->id);
            } catch (Throwable $e) {
                report($e);
                $skip('Gagal menyimpan baris ini: '.$e->getMessage());

                continue;
            }

            if ($pkwtError) {
                $warnings[] = "{$pkwtError} Data PKWT baris ini tidak diubah.";
            }
            // Digits 4–5 should match the year of Tanggal Masuk (a warning, not a rejection).
            if ($isNew || isset($profileData['tanggal_masuk'])) {
                $masuk = $karyawan->profile()->first()?->tanggal_masuk;
                if ($w = Nrp::yearWarning($nrp, $masuk)) {
                    $warnings[] = $w;
                }
            }

            // --- what actually changed --------------------------------------
            $changes = [];
            foreach ($trackedColumns as $col) {
                $b = $before['values'][$col['key']] ?? ($col['key'] === 'status_pegawai_tetap' ? 'Tidak' : null);
                $a = $after['values'][$col['key']] ?? null;
                if ($b !== $a) {
                    $changes[] = $this->change($col['group_id'], $col['label'], $b, $a);
                }
            }
            foreach (['pkwt' => ['Kontrak PKWT', 'periode PKWT', 'periode'], 'anak' => ['Data Anak', 'anak', 'anak']] as $block => [$label, $noun, $unit]) {
                if (($before[$block] ?? null) !== $after[$block]) {
                    $changes[] = $this->change($block, $label, $before[$block] ?? null, $after[$block]);
                    $nb = $before["{$block}_count"] ?? 0;
                    $na = $after["{$block}_count"];
                    if ($na < $nb) {
                        $warnings[] = "Jumlah {$noun} berkurang dari {$nb} menjadi {$na} — pastikan file memuat SEMUA {$unit}.";
                    }
                }
            }

            if ($w = ProfileRules::accountHolderWarning($after['holder'], $karyawan->name)) {
                $warnings[] = $w;
            }

            $action = $isNew ? 'baru' : ($changes ? 'perbarui' : 'tanpa-perubahan');
            $counts[$action]++;
            $seenNrp[mb_strtolower($nrp)] = true;

            $results[] = $this->result($rowNum, $nrp, $karyawan->name, $action,
                changes: $changes,
                warnings: $warnings,
                before: $isNew ? null : Completeness::overallPercent($before['scores']),
                after: $after['scores'],
            );
        }

        return [
            'update_only' => $updateOnly,
            'total_rows' => count($rows),
            'created' => $counts['baru'],
            'updated' => $counts['perbarui'],
            'unchanged' => $counts['tanpa-perubahan'],
            'rows' => $results,
            ...$this->inspectColumns($rows),
            'skipped' => array_values(array_map(
                fn (array $r) => ['row' => $r['row'], 'reason' => $r['reason']],
                array_filter($results, fn (array $r) => $r['action'] === 'dilewati'),
            )),
        ];
    }

    /**
     * A note when a position normally held by one person (SubGroup layer 3 and
     * up) gets another active holder through the import.
     */
    private function sharedPositionNote(int $jabatanId, ?int $exceptKaryawanId): ?string
    {
        $jabatan = Jabatan::with('subGroup')->find($jabatanId);
        if (! $jabatan?->subGroup?->isSingleHolder()) {
            return null;
        }

        $holders = Karyawan::aktif()->where('jabatan_id', $jabatanId)
            ->when($exceptKaryawanId, fn ($q) => $q->where('id', '!=', $exceptKaryawanId))
            ->orderBy('name')->pluck('name');
        if ($holders->isEmpty()) {
            return null;
        }

        return "Jabatan \"{$jabatan->name}\" sudah diisi {$holders->join(', ', ' dan ')} — karyawan ini tetap diimpor, jadi jabatan ini diisi ".($holders->count() + 1).' orang.';
    }

    /**
     * Groups 1 and 2 must be complete once this row is applied. A value can come
     * from the file or already be stored, so a partial-update file for an
     * existing employee does not need to repeat them.
     */
    private function missingRequiredError(?Karyawan $existing, string $nama, bool $placed, array $profileData): ?string
    {
        $stored = $existing?->profile;

        $missing = array_filter(ExcelSchema::REQUIRED_KEYS, fn (string $key) => match ($key) {
            'nrp' => false, // checked first, a row cannot get here without it
            'name' => $nama === '' && ! $existing,
            'sub_group', 'work_location_name' => ! $placed && ! $existing,
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
                : [$col['key'] === 'plafon_pengobatan' ? $n : (int) $n, null],
            'boolean' => [in_array(mb_strtolower(trim($shown)), ['ya', 'yes', 'true', '1', 'y'], true), null],
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
        $isListed = isset(ProfileSections::MASTER_LISTS[$key]) || in_array($key, ['jenis_kelamin', 'golongan'], true);
        if ($value === null || ! $isListed) {
            return [$value, null];
        }

        $options = ProfileSections::optionsFor($key);
        foreach ($options as $option) {
            if (mb_strtolower($option) === mb_strtolower($value)) {
                return [$option, null];
            }
        }

        return [null, "Kolom \"{$col['label']}\" berisi \"{$value}\", tidak ada di daftar pilihan (".implode(', ', $options).'). Lihat sheet Referensi atau tambahkan di Master Data Personalia.'];
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
    private function readPkwt(array $row): array
    {
        $periods = [];
        foreach (range(1, KaryawanProfile::PKWT_MAX_PERIODS) as $i) {
            $name = PkwtPeriod::label($i);
            $startRaw = $row[$this->labelOf["pkwt_{$i}_kontrak"]] ?? null;
            $endRaw = $row[$this->labelOf["pkwt_{$i}_berakhir"]] ?? null;
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
                'periode_ke' => count($periods) + 1,
                'tanggal_kontrak' => $start->toDateString(),
                'tanggal_berakhir' => $end->toDateString(),
            ];
        }

        return [$periods, null];
    }

    /** @return list<array> */
    private function readChildren(array $row, int $anakInFile): array
    {
        $children = [];
        foreach (range(1, max($anakInFile, 1)) as $i) {
            $nama = $this->text($row[$this->labelOf["anak_{$i}_nama"] ?? ''] ?? null);
            if ($nama === null) {
                continue;
            }
            $born = $this->isBlank($lahir = $row[$this->labelOf["anak_{$i}_tanggal_lahir"]] ?? null) ? null : $this->date($lahir);
            $children[] = [
                'urutan' => count($children) + 1,
                'nama' => $nama,
                'tanggal_lahir' => $born instanceof CarbonInterface ? $born->toDateString() : null,
                'pendidikan' => $this->childEducation($this->text($row[$this->labelOf["anak_{$i}_pendidikan"]] ?? null)),
                'status_bekerja' => $this->text($row[$this->labelOf["anak_{$i}_status_bekerja"]] ?? null),
                'status_menikah' => $this->text($row[$this->labelOf["anak_{$i}_status_menikah"]] ?? null),
            ];
        }

        return $children;
    }

    /** Spelled as in the master list when it matches one; kept as typed otherwise. */
    private function childEducation(?string $value): ?string
    {
        return $value === null ? null : (MasterOptions::canonical('pendidikan_anak', $value) ?? $value);
    }

    /** Widest child block present in the file decides how many anak columns to read. */
    private function widestChildBlock(array $rows): int
    {
        $widest = 0;
        foreach ($rows as $row) {
            for ($i = ExcelSchema::MAX_ANAK_COLUMNS; $i > $widest; $i--) {
                if (! $this->isBlank($row["Anak {$i} — Nama"] ?? null)) {
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
            } elseif ($c['key'] !== 'nrp' && isset($headers[$c['label']])) {
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
    private function snapshot(int $karyawanId): array
    {
        $k = Karyawan::with(KaryawanRow::RELATIONS)->findOrFail($karyawanId);
        $p = $k->profile;

        $values = [
            ...array_intersect_key(KaryawanRow::flatten($k), array_flip(self::KARYAWAN_KEYS)),
            'jabatan_name' => $k->jabatan?->name,
            'status_pegawai_tetap' => 'Tidak',
        ];
        foreach ($p?->attributesToArray() ?? [] as $key => $value) {
            $values[$key] = $value;
        }
        $values = array_map(fn ($v) => $this->display($v), $values);

        $periods = $p?->pkwtPeriods ?? collect();
        $anak = $p?->anak ?? collect();

        return [
            'values' => $values,
            'pkwt' => $periods->isEmpty() ? null : $periods
                ->map(fn (PkwtPeriod $x) => str_replace('PKWT ', '', PkwtPeriod::label($x->periode_ke))
                    .": {$x->tanggal_kontrak->format('d.m.Y')} s/d {$x->tanggal_berakhir->format('d.m.Y')}")
                ->join(' • '),
            'pkwt_count' => $periods->count(),
            'anak' => $anak->isEmpty() ? null : $anak->count().' anak: '.$anak->pluck('nama')->join(', '),
            'anak_count' => $anak->count(),
            'holder' => $p?->nama_pemilik_rekening,
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
        int $row, string $nrp, string $nama, string $action,
        ?string $reason = null, array $changes = [], array $warnings = [],
        ?int $before = null, ?array $after = null,
    ): array {
        return [
            'row' => $row,
            'nrp' => $nrp,
            'nama' => $nama,
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
