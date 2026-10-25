<?php

use App\Support\LegacyUrls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once base_path('database/refactor/Mapper.php');

/**
 * Standard HRIS terminology (docs/refactor/terminology-mapping.md): tables, columns and the
 * system values in them move to the English names of database/refactor/terminology.json.
 *
 * Nothing is dropped: on MySQL every foreign key is noted and dropped first, the tables and
 * columns are renamed (Schema::rename / renameColumn, keeping their data), the enum values,
 * roles, permissions, morph types and JSON keys are rewritten, the indexes get names that
 * match their new table, and the foreign keys are added back under the new names. SQLite
 * (the tests) carries its foreign keys along with the renames. What was done is kept in
 * schema_rename_log so down() can undo it exactly.
 */
return new class extends Migration
{
    /** Laravel's own tables: never renamed. */
    private const FRAMEWORK = ['cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations', 'password_reset_tokens', 'sessions', 'notifications', 'schema_rename_log'];

    /** users.permissions: old menu key => new one (App\Support\Access\Menu). */
    private const MENU_KEYS = [
        'recruitment' => 'recruitment', 'org-structure' => 'org-chart', 'mpp' => 'workforce-planning', 'mpp-approve' => 'workforce-planning-approval',
        'karyawan' => 'employees', 'update-data' => 'data-change-requests', 'master-personalia' => 'core-hr-settings',
        'roster' => 'shift-schedule', 'absensi' => 'attendance-records', 'trip-leave' => 'leave-travel', 'pengajuan-dinas' => 'travel-requests',
        'kehadiran' => 'time-clock', 'lembur-approve' => 'overtime-approval', 'master-attendance' => 'time-settings',
        'deklarasi' => 'benefit-claims', 'monitoring-plafon' => 'benefit-balances', 'cuti' => 'leave-balances', 'data-gaji' => 'employee-compensation',
        'master-comben' => 'compensation-settings',
    ];

    private TerminologyMapper $map;

    private array $dict;

    public function __construct()
    {
        $file = base_path('database/refactor/terminology.json');
        $this->map = new TerminologyMapper($file);
        $this->dict = json_decode(file_get_contents($file), true);
    }

    public function up(): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        $log = ['tables' => [], 'columns' => [], 'column_types' => [], 'values' => [], 'json' => [], 'indexes' => [], 'foreign_keys' => []];

        // 1. Foreign keys: noted and dropped (MySQL).
        $fks = $mysql ? $this->foreignKeys() : [];
        foreach ($fks as $fk) {
            Schema::table($fk['table'], fn (Blueprint $t) => $t->dropForeign($fk['name']));
        }
        $log['foreign_keys'] = $fks;

        // 2. Tables.
        foreach ($this->dict['tables'] as $old => $new) {
            if (Schema::hasTable($old) && ! Schema::hasTable($new)) {
                Schema::rename($old, $new);
                $log['tables'][$old] = $new;
            }
        }

        // 3. Columns (every non-framework table), one word -> one word.
        foreach ($this->tables() as $table) {
            $columns = Schema::getColumnListing($table);
            $renames = [];
            foreach ($columns as $col) {
                $new = $this->map->tokens[$col] ?? $col;
                if ($new !== $col) {
                    if (in_array($new, $columns, true) || in_array($new, $renames, true)) {
                        throw new RuntimeException("Kolom {$table}.{$col} -> {$new} bertabrakan.");
                    }
                    $renames[$col] = $new;
                }
            }
            if ($renames) {
                Schema::table($table, function (Blueprint $t) use ($renames) {
                    foreach ($renames as $from => $to) {
                        $t->renameColumn($from, $to);
                    }
                });
                $log['columns'][$table] = $renames;
            }
        }

        // 4. Values.
        $this->values($log);
        DB::table('cache')->delete(); // cached lookups were built with the old names

        // 5. Index names follow their table (MySQL), then the foreign keys come back.
        if ($mysql) {
            $this->renameIndexes($log);
            foreach ($fks as $fk) {
                $table = $this->newTable($fk['table']);
                $column = $log['columns'][$table][$fk['column']] ?? $fk['column'];
                $refTable = $this->newTable($fk['ref_table']);
                $refColumn = $log['columns'][$refTable][$fk['ref_column']] ?? $fk['ref_column'];
                Schema::table($table, fn (Blueprint $t) => $t->foreign($column)->references($refColumn)->on($refTable)
                    ->onUpdate(strtolower($fk['update_rule']))->onDelete(strtolower($fk['delete_rule'])));
            }
        }

        Schema::create('schema_rename_log', function (Blueprint $t) {
            $t->id();
            $t->longText('plan');
            $t->timestamp('created_at')->nullable();
        });
        DB::table('schema_rename_log')->insert(['plan' => json_encode($log), 'created_at' => now()]);
    }

    public function down(): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        $log = json_decode(DB::table('schema_rename_log')->value('plan'), true);
        Schema::drop('schema_rename_log');

        $fks = $mysql ? $this->foreignKeys() : [];
        foreach ($fks as $fk) {
            Schema::table($fk['table'], fn (Blueprint $t) => $t->dropForeign($fk['name']));
        }
        // Index names back, while the tables still have their new names.
        foreach (array_reverse($log['indexes'] ?? []) as [$table, $from, $to]) {
            DB::statement("alter table `{$table}` rename index `{$to}` to `{$from}`");
        }
        // Values back (through a placeholder, as on the way up: MySQL compares "admin" and "Admin"
        // alike), then the column types and defaults they had.
        foreach ($log['values'] as [$table, $column, $pairs]) {
            foreach ($pairs as $i => [, $new]) {
                DB::table($table)->where($column, $new)->update([$column => "\u{1}{$i}"]);
            }
            foreach ($pairs as $i => [$old]) {
                DB::table($table)->where($column, "\u{1}{$i}")->update([$column => $old]);
            }
        }
        foreach (array_reverse($log['column_types'] ?? []) as [$table, $column, $type, $nullable, $default, $charset]) {
            DB::statement(sprintf('alter table `%s` modify `%s` %s%s %s', $table, $column, $type, $charset, $this->nullAndDefault($nullable, $default)));
        }
        foreach ($log['json'] as [$table, $column, $rows]) {
            foreach ($rows as $id => $old) {
                DB::table($table)->where('id', $id)->update([$column => $old]);
            }
        }
        // Columns and tables back.
        foreach ($log['columns'] as $table => $renames) {
            Schema::table($table, function (Blueprint $t) use ($renames) {
                foreach ($renames as $from => $to) {
                    $t->renameColumn($to, $from);
                }
            });
        }
        foreach (array_reverse($log['tables'], true) as $old => $new) {
            Schema::rename($new, $old);
        }
        DB::table('cache')->delete();
        if ($mysql) {
            foreach ($log['foreign_keys'] as $fk) {
                Schema::table($fk['table'], fn (Blueprint $t) => $t->foreign($fk['column'], $fk['name'])->references($fk['ref_column'])->on($fk['ref_table'])
                    ->onUpdate(strtolower($fk['update_rule']))->onDelete(strtolower($fk['delete_rule'])));
            }
        }
    }

    // --- values ----------------------------------------------------------------------------------

    private function values(array &$log): void
    {
        $change = function (string $table, string $column, callable $mapValue) use (&$log) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                return;
            }
            $pairs = [];
            foreach (DB::table($table)->whereNotNull($column)->distinct()->pluck($column) as $old) {
                $new = $mapValue((string) $old);
                if ($new !== (string) $old) {
                    $pairs[] = [$old, $new];
                }
            }
            if (DB::getDriverName() === 'mysql') {
                $this->prepareColumn($table, $column, $mapValue, $pairs, $log);
            }
            // Through a placeholder first, so a value renamed onto another one's old name is not hit twice.
            foreach ($pairs as $i => [$old]) {
                DB::table($table)->where($column, $old)->update([$column => "\u{1}{$i}"]);
            }
            foreach ($pairs as $i => [, $new]) {
                DB::table($table)->where($column, "\u{1}{$i}")->update([$column => $new]);
            }
            if ($pairs) {
                $log['values'][] = [$table, $column, $pairs];
            }
        };

        foreach ($this->dict['special_values'] as [$table, $column, $values]) {
            $change($table, $column, fn ($v) => $values[$v] ?? $v);
        }
        foreach ($this->dict['enum_columns'] as [$table, $column]) {
            $change($table, $column, fn ($v) => $this->map->string($v));
        }
        $change('attachments', 'attachable_type', fn ($v) => $this->map->qualified($v));
        $change('notifications', 'type', fn ($v) => $this->map->qualified($v));
        $change('notifications', 'ref', fn ($v) => $this->map->string($v));

        foreach ($this->dict['json_columns'] as [$table, $column, $mode]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            $before = [];
            foreach (DB::table($table)->whereNotNull($column)->get(['id', $column]) as $row) {
                $data = json_decode($row->{$column}, true);
                if (! is_array($data)) {
                    continue;
                }
                $new = match (true) {
                    $table === 'users' && $column === 'permissions' => $this->permissions($data),
                    $mode === 'field_key' => array_map(fn ($a) => is_array($a) ? $this->answer($a) : $a, $data),
                    default => $this->json($data, $mode === 'notification' || $table === 'workforce_plan_revisions'),
                };
                $encoded = json_encode($new, JSON_UNESCAPED_UNICODE);
                if ($encoded !== json_encode($data, JSON_UNESCAPED_UNICODE)) {
                    $before[$row->id] = $row->{$column};
                    DB::table($table)->where('id', $row->id)->update([$column => $encoded]);
                }
            }
            if ($before) {
                $log['json'][] = [$table, $column, $before];
            }
        }
    }

    /**
     * A value column (MySQL) made ready for its English codes: an ENUM becomes a VARCHAR, a
     * VARCHAR too short for the longest new value is widened, and a default that is an old
     * code ("disetujui", "5_hari") gets the new one. The definition before is logged for down().
     */
    private function prepareColumn(string $table, string $column, callable $mapValue, array $pairs, array &$log): void
    {
        $col = DB::selectOne(
            'select data_type as data_type, column_type as column_type, character_maximum_length as len, is_nullable as is_nullable, column_default as column_default,
                    character_set_name as charset, collation_name as collation
             from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
            [$table, $column],
        );
        if (! $col || ! in_array($col->data_type, ['varchar', 'char', 'enum'], true)) {
            return;
        }
        $default = $col->column_default;
        $newDefault = $default === null ? null : $mapValue((string) $default);
        $need = max((int) $col->len, $newDefault === null ? 0 : mb_strlen($newDefault), ...array_map(fn (array $p) => mb_strlen((string) $p[1]), $pairs ?: [[null, '']]));
        if ($col->data_type !== 'enum' && $need <= (int) $col->len && $newDefault === $default) {
            return;
        }
        $nullable = $col->is_nullable === 'YES';
        $charset = $col->charset ? " character set {$col->charset} collate {$col->collation}" : '';
        $log['column_types'][] = [$table, $column, $col->column_type, $nullable, $default, $charset];
        $type = $col->data_type === 'char' && $need <= (int) $col->len ? $col->column_type : 'varchar('.max($need, (int) $col->len).')';
        DB::statement(sprintf('alter table `%s` modify `%s` %s%s %s', $table, $column, $type, $charset, $this->nullAndDefault($nullable, $newDefault)));
    }

    private function nullAndDefault(bool $nullable, ?string $default): string
    {
        return ($nullable ? 'null' : 'not null').($default === null ? '' : ' default '.DB::getPdo()->quote($default));
    }

    /** Keys renamed; string values too where they are system words (notification data, plan changes). */
    private function json(array $data, bool $values): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = is_string($k) ? $this->map->string($k) : $k;
            $out[$key] = match (true) {
                is_array($v) => $this->json($v, $values),
                $values && $key === 'url' && is_string($v) => $this->url($v),
                $values && is_string($v) => $this->map->string($v),
                default => $v,
            };
        }

        return $out;
    }

    /** One application answer: keys renamed ("kunci" → "field_key"), and the field_key's value; the answer itself is kept. */
    private function answer(array $answer): array
    {
        $out = [];
        foreach ($answer as $k => $v) {
            $key = $this->map->string((string) $k);
            $out[$key] = $key === 'field_key' && is_string($v) ? $this->map->string($v) : $v;
        }

        return $out;
    }

    /** A menu key => level map; keys this migration does not know are kept. */
    private function permissions(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[self::MENU_KEYS[$k] ?? $k] = $v;
        }

        return $out;
    }

    /** An old page link ("/comben/deklarasi?status=diajukan") as the current one ("/compensation/claims?status=pending"). */
    private function url(string $url): string
    {
        [$path, $query] = array_pad(explode('?', $url, 2), 2, null);
        $path = trim($path, '/');
        foreach (array_keys(LegacyUrls::MAP) as $old) {
            if ($path === $old || str_starts_with($path, $old.'/')) {
                $new = LegacyUrls::to($old, ltrim(substr($path, strlen($old)), '/'));

                return $query === null ? $new : $new.'?'.$this->map->string($query);
            }
        }

        return $this->map->string($url);
    }

    // --- helpers ---------------------------------------------------------------------------------

    /** @return list<string> */
    private function tables(): array
    {
        // Schema::getTables() lists every schema on the server (the other workspaces too): only this one.
        $schema = DB::getDriverName() === 'mysql' ? DB::getDatabaseName() : null;
        $tables = array_filter(Schema::getTables(), fn (array $t) => $schema === null || ($t['schema'] ?? $schema) === $schema);

        return array_values(array_filter(array_map(fn (array $t) => $t['name'], $tables), fn (string $t) => ! in_array($t, self::FRAMEWORK, true)));
    }

    private function newTable(string $table): string
    {
        return $this->dict['tables'][$table] ?? $table;
    }

    private function foreignKeys(): array
    {
        return array_map(fn ($r) => (array) $r, DB::select(
            'select k.table_name as `table`, k.column_name as `column`, k.referenced_table_name as ref_table, k.referenced_column_name as ref_column,
                    k.constraint_name as name, r.update_rule as update_rule, r.delete_rule as delete_rule
             from information_schema.key_column_usage k
             join information_schema.referential_constraints r on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name
             where k.table_schema = database() and k.referenced_table_name is not null
             order by k.table_name, k.column_name'
        ));
    }

    /** Indexes named after the old table / columns get Laravel's name for the new ones. */
    private function renameIndexes(array &$log): void
    {
        foreach ($this->tables() as $table) {
            $indexes = collect(DB::select("show index from `{$table}`"))->groupBy('Key_name');
            foreach ($indexes as $name => $cols) {
                if ($name === 'PRIMARY') {
                    continue;
                }
                $columns = $cols->sortBy('Seq_in_index')->pluck('Column_name')->all();
                $kind = $cols->first()->Non_unique ? 'index' : 'unique';
                $wanted = strtolower(str_replace(['-', '.'], '_', $table.'_'.implode('_', $columns).'_'.$kind));
                if (str_ends_with($name, '_foreign')) {
                    $wanted = strtolower($table.'_'.implode('_', $columns).'_foreign');
                }
                if ($name !== $wanted && strlen($wanted) <= 64 && ! $indexes->has($wanted)) {
                    DB::statement("alter table `{$table}` rename index `{$name}` to `{$wanted}`");
                    $log['indexes'][] = [$table, $name, $wanted];
                }
            }
        }
    }
};
