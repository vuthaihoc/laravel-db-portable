<?php

namespace DbPortable\Audit;

use DbPortable\Dialects\Dialect;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compares the schema of a target database with the data of a source
 * database: what would be lost or rejected when copying.
 */
class Auditor
{
    /** Integer ranges by type name: [signed min, signed max, unsigned max]. */
    protected const INTEGER_RANGES = [
        'tinyint' => [-128, 127, 255],
        'smallint' => [-32768, 32767, 65535],
        'int2' => [-32768, 32767, 65535],
        'mediumint' => [-8388608, 8388607, 16777215],
        'int' => [-2147483648, 2147483647, 4294967295],
        'integer' => [-2147483648, 2147483647, 4294967295],
        'int4' => [-2147483648, 2147483647, 4294967295],
    ];

    public function __construct(protected string $from, protected string $to) {}

    /**
     * @param  list<string>  $tables  all tables of the source when empty
     * @return list<array{table: string, column: string|null, problem: string, detail: string}>
     */
    public function audit(array $tables = [], ?callable $progress = null): array
    {
        $sourceTables = $this->tables($this->from);
        $targetTables = $this->tables($this->to);
        $tables = $tables ?: $sourceTables;
        $problems = [];

        foreach ($tables as $table) {
            if (! in_array($table, $sourceTables, true)) {
                $problems[] = $this->problem($table, null, 'missing in source', "[{$this->from}] has no table {$table}");

                continue;
            }

            if (! in_array($table, $targetTables, true)) {
                $problems[] = $this->problem($table, null, 'missing in target', "[{$this->to}] has no table {$table}");

                continue;
            }

            if ($progress) {
                $progress($table);
            }

            array_push($problems, ...$this->auditTable($table));
        }

        return $problems;
    }

    /**
     * @return list<array{table: string, column: string|null, problem: string, detail: string}>
     */
    protected function auditTable(string $table): array
    {
        $problems = [];
        $sourceColumns = collect(Schema::connection($this->from)->getColumns($table))->keyBy('name');
        $targetColumns = collect(Schema::connection($this->to)->getColumns($table))->keyBy('name');

        foreach ($sourceColumns->keys()->diff($targetColumns->keys()) as $column) {
            $problems[] = $this->problem($table, (string) $column, 'missing column', "[{$this->to}].{$table} has no column {$column}; its data would be dropped");
        }

        $source = DB::connection($this->from);
        $dialect = Dialect::for($source->query()->getGrammar());
        $selects = [];
        $checks = [];

        foreach ($targetColumns as $name => $column) {
            if (! $sourceColumns->has($name)) {
                continue;
            }

            $typeName = strtolower((string) $column['type_name']);
            $type = strtolower((string) $column['type']);
            $wrapped = $source->getQueryGrammar()->wrap($name);

            if (isset(self::INTEGER_RANGES[$typeName])) {
                [$min, $max, $unsignedMax] = self::INTEGER_RANGES[$typeName];
                $unsigned = str_contains($type, 'unsigned');
                $checks[] = ['integer', $name, $unsigned ? 0 : $min, $unsigned ? $unsignedMax : $max, $type];
                $selects[] = "min({$wrapped}) as ".$this->alias('min', $name);
                $selects[] = "max({$wrapped}) as ".$this->alias('max', $name);
            } elseif (str_contains($type, 'unsigned')) {
                $checks[] = ['integer', $name, 0, null, $type];
                $selects[] = "min({$wrapped}) as ".$this->alias('min', $name);
            } elseif (preg_match('/^(var)?char(acter)?( varying)?\((\d+)\)/', $type, $matches)) {
                $checks[] = ['string', $name, null, (int) $matches[4], $type];
                $selects[] = 'max('.$dialect->charLength($name).') as '.$this->alias('len', $name);
            }
        }

        if ($selects === []) {
            return $problems;
        }

        $row = (array) $source->selectOne('select '.implode(', ', $selects).' from '.$source->getQueryGrammar()->wrapTable($table));

        foreach ($checks as [$kind, $name, $min, $max, $type]) {
            if ($kind === 'string') {
                $length = $row[$this->alias('len', $name)] ?? null;

                if ($length !== null && (int) $length > $max) {
                    $problems[] = $this->problem($table, $name, 'string too long', "{$type} but the longest value has {$length} characters");
                }

                continue;
            }

            $lowest = $row[$this->alias('min', $name)] ?? null;
            $highest = $row[$this->alias('max', $name)] ?? null;

            if ($lowest !== null && is_numeric($lowest) && (float) $lowest < $min) {
                $problems[] = $this->problem($table, $name, 'integer out of range', "{$type} (min {$min}) but the source has {$lowest}");
            }

            if ($max !== null && $highest !== null && is_numeric($highest) && (float) $highest > $max) {
                $problems[] = $this->problem($table, $name, 'integer out of range', "{$type} (max {$max}) but the source has {$highest}");
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    protected function tables(string $connection): array
    {
        return array_map('strval', Schema::connection($connection)->getTableListing(schemaQualified: false));
    }

    protected function alias(string $kind, string $column): string
    {
        return $kind.'_'.substr(md5($column), 0, 12);
    }

    /**
     * @return array{table: string, column: string|null, problem: string, detail: string}
     */
    protected function problem(string $table, ?string $column, string $problem, string $detail): array
    {
        return compact('table', 'column', 'problem', 'detail');
    }
}
