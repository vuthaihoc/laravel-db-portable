<?php

namespace DbPortable\Copy;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Copies rows between two connections of possibly different database
 * families. The target schema must already exist (run the migrations).
 */
class Copier
{
    /** @var array<string, array<string, string>> target column type names by table */
    protected array $targetTypes = [];

    public function __construct(
        protected string $from,
        protected string $to,
        protected int $chunk = 500,
    ) {}

    /**
     * @param  list<string>  $tables  every table present on both sides when empty
     * @param  list<string>  $except
     * @param  (callable(string, int, string): void)|null  $progress  (table, rows, status)
     * @return array<string, array{rows: int, status: string}>
     */
    public function copy(array $tables = [], array $except = ['migrations'], ?int $sample = null, bool $resume = false, bool $dryRun = false, ?callable $progress = null): array
    {
        $sourceTables = $this->tables($this->from);
        $targetTables = $this->tables($this->to);
        $tables = $tables ?: array_values(array_intersect($sourceTables, $targetTables));
        $report = [];

        $this->toggleForeignKeys(false);

        try {
            foreach ($tables as $table) {
                if (in_array($table, $except, true)) {
                    continue;
                }

                if (! in_array($table, $sourceTables, true) || ! in_array($table, $targetTables, true)) {
                    $report[$table] = ['rows' => 0, 'status' => 'missing on one side'];
                } else {
                    try {
                        $report[$table] = ['rows' => $this->copyTable($table, $sample, $resume, $dryRun), 'status' => $dryRun ? 'would copy' : 'copied'];
                    } catch (Throwable $e) {
                        $report[$table] = ['rows' => 0, 'status' => 'failed: '.substr((string) preg_replace('/\s+/', ' ', $e->getMessage()), 0, 200)];
                    }
                }

                if ($progress) {
                    $progress($table, $report[$table]['rows'], $report[$table]['status']);
                }
            }
        } finally {
            $this->toggleForeignKeys(true);
        }

        return $report;
    }

    protected function copyTable(string $table, ?int $sample, bool $resume, bool $dryRun): int
    {
        $columns = array_values(array_intersect(
            Schema::connection($this->from)->getColumnListing($table),
            Schema::connection($this->to)->getColumnListing($table),
        ));

        $key = $this->primaryKey($table);
        $source = fn () => $this->source()->table($table)->select($columns);

        if ($dryRun) {
            return $sample !== null ? min($sample, $source()->count()) : $source()->count();
        }

        if ($sample !== null) {
            $rows = $source()->when($key !== null, fn ($query) => $query->orderByDesc((string) $key))->limit($sample)->get();

            return $this->insert($table, $rows->all());
        }

        if ($key === null) {
            $copied = 0;

            for ($page = 1; ; $page++) {
                $rows = $source()->orderBy($columns[0])->forPage($page, $this->chunk)->get();
                $copied += $this->insert($table, $rows->all());

                if ($rows->count() < $this->chunk) {
                    return $copied;
                }
            }
        }

        // Keyset pagination on the primary key; --resume starts after the target's highest key.
        $last = $resume ? $this->target()->table($table)->max($key) : null;
        $copied = 0;

        do {
            $rows = $source()
                ->when($last !== null, fn ($query) => $query->where($key, '>', $last))
                ->orderBy($key)
                ->limit($this->chunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $copied += $this->insert($table, $rows->all());
            $last = ((array) $rows->last())[$key];
        } while ($rows->count() === $this->chunk);

        return $copied;
    }

    /**
     * @param  array<int, object>  $rows
     */
    protected function insert(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $types = $this->targetTypes($table);
        $values = array_map(fn (object $row) => $this->normalize((array) $row, $types), $rows);

        $this->target()->table($table)->insertOrIgnore($values);

        return count($values);
    }

    /**
     * Convert a source row to values the target accepts.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $types
     * @return array<string, mixed>
     */
    protected function normalize(array $row, array $types): array
    {
        $grammar = $this->target()->getQueryGrammar();
        $mysqlLike = $grammar instanceof MySqlGrammar;

        foreach ($row as $column => $value) {
            $type = $types[$column] ?? '';

            if (is_array($value) || is_object($value) && ! $value instanceof DateTimeInterface) {
                $row[$column] = json_encode($value, JSON_UNESCAPED_UNICODE);
            } elseif (in_array($type, ['bool', 'boolean'], true) && $value !== null) {
                $row[$column] = $grammar instanceof PostgresGrammar ? (bool) $value : (int) (bool) $value;
            } elseif (is_bool($value)) {
                $row[$column] = $grammar instanceof PostgresGrammar ? $value : (int) $value;
            } elseif ($mysqlLike && is_string($value) && $this->hasTimeZoneOffset($value)) {
                // MySQL-family datetime columns take no offset: store the instant in UTC.
                $row[$column] = (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            }
        }

        return $row;
    }

    protected function hasTimeZoneOffset(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?([+-]\d{2}(:?\d{2})?|Z)$/', $value);
    }

    /**
     * @return array<string, string>
     */
    protected function targetTypes(string $table): array
    {
        return $this->targetTypes[$table] ??= collect(Schema::connection($this->to)->getColumns($table))
            ->mapWithKeys(fn (array $column) => [$column['name'] => strtolower((string) $column['type_name'])])
            ->all();
    }

    protected function primaryKey(string $table): ?string
    {
        foreach (Schema::connection($this->from)->getIndexes($table) as $index) {
            if ($index['primary'] && count($index['columns']) === 1) {
                return $index['columns'][0];
            }
        }

        return null;
    }

    protected function toggleForeignKeys(bool $enabled): void
    {
        $grammar = $this->target()->getQueryGrammar();

        if ($grammar instanceof MySqlGrammar) {
            $this->target()->statement('set foreign_key_checks = '.($enabled ? 1 : 0));
        } elseif ($grammar instanceof SQLiteGrammar) {
            $this->target()->statement('pragma foreign_keys = '.($enabled ? 'on' : 'off'));
        }
    }

    /**
     * @return list<string>
     */
    protected function tables(string $connection): array
    {
        return array_map('strval', Schema::connection($connection)->getTableListing(schemaQualified: false));
    }

    protected function source(): Connection
    {
        /** @var Connection */
        return DB::connection($this->from);
    }

    protected function target(): Connection
    {
        /** @var Connection */
        return DB::connection($this->to);
    }
}
