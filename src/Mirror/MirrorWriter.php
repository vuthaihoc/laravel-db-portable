<?php

namespace DbPortable\Mirror;

use DbPortable\Schema\Family;
use DbPortable\Schema\Unsupported;
use DbPortable\Support\ValueMapper;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use LogicException;

/**
 * Writes mirror tables: upserts keyed by the table's key and guarded by the
 * version column (a row never replaces a newer version, so replayed or
 * reordered jobs leave the mirror right), and deletes.
 */
class MirrorWriter
{
    /** Bound parameters per statement, under SQLite's limit of 32766. */
    protected const PARAMETERS = 30000;

    /** Seconds before an unknown column makes the writer read the table's columns again. */
    protected const COLUMNS_TTL = 60;

    /** @var array<string, array{types: array<string, string>, at: int}> */
    protected array $columns = [];

    /**
     * @param  list<array<string, mixed>>  $rows  rows with the mirror table's column names
     * @return int the rows written or left alone because the mirror has a newer version
     */
    public function upsert(MirrorTable $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $this->ensureSupported($table);

        $types = $this->types($table, $rows);
        $mapper = new ValueMapper($table->connection);
        $groups = [];

        foreach ($rows as $row) {
            if (($row[$table->key] ?? null) === null) {
                throw new InvalidArgumentException("A row for the mirror table {$table->table} has no {$table->key}.");
            }

            $row = array_intersect_key($row, $types);
            ksort($row);
            $groups[implode(',', array_keys($row))][] = $mapper->row($row, $types);
        }

        foreach ($groups as $group) {
            foreach (array_chunk($group, max(1, intdiv(self::PARAMETERS, count($group[0])))) as $batch) {
                $this->upsertBatch($table, $batch);
            }
        }

        return count($rows);
    }

    /**
     * @param  list<int|string>  $keys
     * @return int the rows deleted
     */
    public function delete(MirrorTable $table, array $keys): int
    {
        $this->ensureSupported($table);

        $deleted = 0;

        foreach (array_chunk($keys, 1000) as $chunk) {
            $deleted += $table->connection->table($table->table)->whereIn($table->key, $chunk)->delete();
        }

        return $deleted;
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $rows  rows with the same columns, sorted by name
     */
    protected function upsertBatch(MirrorTable $table, array $rows): void
    {
        $connection = $table->connection;
        $query = $connection->table($table->table);
        $grammar = $query->getGrammar();
        $bindings = $query->cleanBindings(Arr::flatten($rows, 1));
        $update = array_values(array_diff(array_keys($rows[0]), [$table->key]));

        if ($update === []) {
            $connection->affectingStatement($grammar->compileInsertOrIgnore($query, $rows), $bindings);

            return;
        }

        $version = $table->version !== null && in_array($table->version, $update, true) ? $table->version : null;

        if ($version !== null) {
            // Assigned last: MySQL assigns in order, so the other columns still compare the old version.
            $update = [...array_diff($update, [$version]), $version];
        }

        $sql = $grammar->compileInsert($query, $rows);

        if (Family::of($connection) === Family::MYSQL) {
            $guard = $version === null ? null : sprintf('(values(%1$s) is null or %1$s is null or %1$s <= values(%1$s))', $grammar->wrap($version));

            $sql .= ' on duplicate key update '.implode(', ', array_map(function (string $column) use ($grammar, $guard) {
                $wrapped = $grammar->wrap($column);

                return $guard === null ? "{$wrapped} = values({$wrapped})" : "{$wrapped} = if({$guard}, values({$wrapped}), {$wrapped})";
            }, $update));
        } else {
            $excluded = fn (string $column) => $grammar->wrap('excluded').'.'.$grammar->wrap($column);

            $sql .= ' on conflict ('.$grammar->wrap($table->key).') do update set '.implode(', ', array_map(
                fn (string $column) => $grammar->wrap($column).' = '.$excluded($column),
                $update,
            ));

            if ($version !== null) {
                $current = $grammar->wrapTable($table->table).'.'.$grammar->wrap($version);
                $sql .= " where {$excluded($version)} is null or {$current} is null or {$current} <= {$excluded($version)}";
            }
        }

        $connection->affectingStatement($sql, $bindings);
    }

    /**
     * The mirror table's column types. Columns of the rows that the table lacks are left out,
     * with a warning (an exception in strict mode): the owner has columns mirror:schema did not add.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    protected function types(MirrorTable $table, array $rows): array
    {
        $cacheKey = $table->connection->getName().'|'.$table->table;
        $cached = $this->columns[$cacheKey] ?? null;
        $wanted = array_keys(array_merge(...$rows));

        if ($cached === null || (array_diff($wanted, array_keys($cached['types'])) !== [] && $cached['at'] < time() - self::COLUMNS_TTL)) {
            $types = [];

            foreach ($table->connection->getSchemaBuilder()->getColumns($table->table) as $column) {
                $types[(string) $column['name']] = strtolower((string) $column['type_name']);
            }

            if ($types === []) {
                throw new LogicException("The mirror table {$table->table} does not exist on [{$table->connection->getName()}]: create it (db-portable:mirror:schema).");
            }

            $cached = $this->columns[$cacheKey] = ['types' => $types, 'at' => time()];
        }

        $missing = array_diff($wanted, array_keys($cached['types']));

        if ($missing !== []) {
            Unsupported::skip(sprintf(
                'The mirror table %s.%s has no column %s: the column is not mirrored (db-portable:mirror:schema adds it).',
                $table->connection->getName(),
                $table->table,
                implode(', ', $missing),
            ));
        }

        return $cached['types'];
    }

    protected function ensureSupported(MirrorTable $table): void
    {
        if (Family::isXtdb($table->connection)) {
            throw new LogicException('XTDB mirrors are not supported yet.');
        }
    }
}
