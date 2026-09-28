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

    /** Rows the target accepted for the table being copied (insertOrIgnore skips the others). */
    protected int $inserted = 0;

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
        $tables = $this->orderByForeignKeys($tables, $targetTables);
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
                    $this->inserted = 0;

                    try {
                        $report[$table] = $dryRun
                            ? ['rows' => $this->copyTable($table, $sample, $resume, true), 'status' => 'would copy']
                            : $this->copied($table, $this->copyTable($table, $sample, $resume, false), $sample === null);
                    } catch (Throwable $e) {
                        $report[$table] = ['rows' => $this->inserted, 'status' => sprintf(
                            'failed after %d row(s): %s',
                            $this->inserted,
                            substr((string) preg_replace('/\s+/', ' ', $e->getMessage()), 0, 200)
                        )];
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

    /**
     * Report a copied table: rows the target ignored (duplicates, values it rejects), and
     * "incomplete" when the target ends with fewer rows than the source.
     *
     * @return array{rows: int, status: string}
     */
    protected function copied(string $table, int $read, bool $complete): array
    {
        $this->resetSequences($table);

        $status = 'copied';
        $skipped = $read - $this->inserted;

        if ($skipped > 0) {
            $status .= ", {$skipped} skipped";
        }

        if ($complete) {
            $source = $this->source()->table($table)->count();
            $target = $this->target()->table($table)->count();

            if ($target < $source) {
                $status = "incomplete: the target has {$target} of {$source} row(s)".($skipped > 0 ? ", {$skipped} skipped" : '');
            }
        }

        return ['rows' => $this->inserted, 'status' => $status];
    }

    /**
     * @return int the rows read from the source (counted without copying on a dry run)
     */
    protected function copyTable(string $table, ?int $sample, bool $resume, bool $dryRun): int
    {
        $columns = array_values(array_intersect(
            Schema::connection($this->from)->getColumnListing($table),
            Schema::connection($this->to)->getColumnListing($table),
        ));

        $keys = $this->primaryKey($table);
        $key = count($keys) === 1 ? $keys[0] : null;
        $source = fn () => $this->source()->table($table)->select($columns);

        if ($dryRun) {
            return $sample !== null ? min($sample, $source()->count()) : $source()->count();
        }

        if ($sample !== null) {
            $rows = $source()->when($keys !== [], fn ($query) => $query->orderByDesc($keys[0]))->limit($sample)->get();

            return $this->insert($table, $rows->all());
        }

        if ($key === null) {
            // Composite or no primary key: pages ordered by the key columns, or by every column.
            $copied = 0;

            for ($page = 1; ; $page++) {
                $rows = $source();

                foreach ($keys ?: $columns as $column) {
                    $rows->orderBy($column);
                }

                $rows = $rows->forPage($page, $this->chunk)->get();
                $copied += $this->insert($table, $rows->all());

                if ($rows->count() < $this->chunk) {
                    return $copied;
                }
            }
        }

        // Keyset pagination on the primary key. --resume starts after the target's highest key
        // when the key is an integer; other keys may sort differently on the two databases, so
        // they are copied again and the rows already there are ignored.
        $last = $resume && $this->isIntegerColumn($table, $key) ? $this->target()->table($table)->max($key) : null;
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

        $this->inserted += $this->target()->table($table)->insertOrIgnore($values);

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

    /**
     * @return list<string> the primary key columns of the source table
     */
    protected function primaryKey(string $table): array
    {
        foreach (Schema::connection($this->from)->getIndexes($table) as $index) {
            if ($index['primary']) {
                return array_map('strval', $index['columns']);
            }
        }

        return [];
    }

    protected function isIntegerColumn(string $table, string $column): bool
    {
        foreach (Schema::connection($this->from)->getColumns($table) as $definition) {
            if ($definition['name'] === $column) {
                return str_contains(strtolower((string) $definition['type_name']), 'int');
            }
        }

        return false;
    }

    /**
     * Put parent tables before the tables whose foreign keys reference them, so a target that
     * keeps checking foreign keys (PostgreSQL, CockroachDB) accepts the rows. Tables in a
     * cycle keep their order.
     *
     * @param  list<string>  $tables
     * @param  list<string>  $targetTables
     * @return list<string>
     */
    protected function orderByForeignKeys(array $tables, array $targetTables): array
    {
        $parents = [];

        foreach ($tables as $table) {
            $parents[$table] = [];

            if (in_array($table, $targetTables, true)) {
                foreach (Schema::connection($this->to)->getForeignKeys($table) as $foreignKey) {
                    $parent = (string) $foreignKey['foreign_table'];

                    if ($parent !== $table && in_array($parent, $tables, true)) {
                        $parents[$table][] = $parent;
                    }
                }
            }
        }

        $ordered = [];

        while ($parents !== []) {
            $ready = array_keys(array_filter($parents, fn (array $pending) => array_diff($pending, $ordered) === []));
            $next = $ready === [] ? array_keys($parents) : $ready;   // a cycle: keep the given order

            foreach ($next as $table) {
                $ordered[] = (string) $table;
                unset($parents[$table]);
            }
        }

        return $ordered;
    }

    /**
     * Move the sequences behind serial and identity columns of a PostgreSQL-family target past
     * the copied keys, or the next insert collides with a copied row. MySQL-family and SQLite
     * auto-increment counters follow explicit keys by themselves.
     */
    protected function resetSequences(string $table): void
    {
        if (! $this->target()->getQueryGrammar() instanceof PostgresGrammar) {
            return;
        }

        foreach ($this->targetTypes($table) as $column => $type) {
            if (! str_contains($type, 'int')) {
                continue;
            }

            $sequence = $this->target()->scalar('select pg_get_serial_sequence(?, ?)', [$table, $column]);

            if (is_string($sequence) && $sequence !== '') {
                $wrapped = $this->target()->getQueryGrammar()->wrap($column);
                $this->target()->select(sprintf(
                    'select setval(?::regclass, max(%s)) from %s having max(%s) is not null',
                    $wrapped,
                    $this->target()->getQueryGrammar()->wrapTable($table),
                    $wrapped,
                ), [$sequence]);
            }
        }
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
