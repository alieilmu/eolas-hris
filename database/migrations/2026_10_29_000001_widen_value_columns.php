<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The status / type columns hold English codes since the terminology refactor (business_trip,
 * long_service, mining_roster …). The refactor widened them only where rows already held a
 * value, so a database built from scratch — a new demo workspace — kept e.g. type varchar(10)
 * and could not store "business_trip". Every such column is at least 30 characters now (MySQL;
 * SQLite does not enforce lengths). Defaults, nullability and charset stay as they are.
 */
return new class extends Migration
{
    private const MIN = 30;

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        $dict = json_decode(file_get_contents(base_path('database/refactor/terminology.json')), true);
        $columns = array_merge(
            array_map(fn (array $c) => [$c[0], $c[1]], $dict['enum_columns']),
            array_map(fn (array $c) => [$c[0], $c[1]], $dict['special_values']),
        );
        foreach (array_unique($columns, SORT_REGULAR) as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            $col = DB::selectOne(
                'select data_type as data_type, character_maximum_length as len, is_nullable as is_nullable, column_default as column_default,
                        character_set_name as charset, collation_name as collation
                 from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
                [$table, $column],
            );
            if (! $col || ! in_array($col->data_type, ['varchar', 'char'], true) || (int) $col->len >= self::MIN) {
                continue;
            }
            DB::statement(sprintf(
                'alter table `%s` modify `%s` varchar(%d)%s %s%s',
                $table, $column, self::MIN,
                $col->charset ? " character set {$col->charset} collate {$col->collation}" : '',
                $col->is_nullable === 'YES' ? 'null' : 'not null',
                $col->column_default === null ? '' : ' default '.DB::getPdo()->quote($col->column_default),
            ));
        }
    }

    public function down(): void
    {
        // Narrowing again could cut stored codes; the wider columns are kept.
    }
};
