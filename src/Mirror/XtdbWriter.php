<?php

namespace DbPortable\Mirror;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DbPortable\Support\ValueMapper;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use stdClass;
use Throwable;

/**
 * Writes XTDB mirror tables (experimental, XTDB 2.2 through laravel-xtdb2), in XTDB SQL:
 *
 * - the rows' current state (versions "latest"): an INSERT replaces a row from now on,
 *   unless the mirror holds a newer version;
 * - every version (versions "all"): each row is valid from its version time until the
 *   next change already in the history, so versions applied out of order leave the
 *   history right; a delete ends the row's validity, an erase removes its history.
 *
 * @phpstan-type Version array{key: int|string, row: array<string, mixed>|null, at: string, erase?: bool}
 */
class XtdbWriter
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return int the rows written
     */
    public function upsert(MirrorTable $table, array $rows): int
    {
        $mapper = new ValueMapper($table->connection);
        $rows = array_map(fn (array $row) => $mapper->row($row), $rows);

        if ($table->version !== null) {
            $current = [];
            $grammar = $table->connection->getQueryGrammar();

            foreach (array_chunk(array_column($rows, $table->key), 500) as $keys) {
                $sql = "select {$grammar->wrap($table->key)} as k, {$grammar->wrap($table->version)} as v from {$grammar->wrapTable($table->table)}"
                    ." where {$grammar->wrap($table->key)} in (".$grammar->parameterize($keys).')';

                foreach ($this->select($table, $sql, $keys) as $row) {
                    $current[(string) $row->k] = $row->v;
                }
            }

            // The version guard: a row never replaces a newer one.
            $rows = array_values(array_filter($rows, fn (array $row) => ! $this->newer($current[(string) $row[$table->key]] ?? null, $row[$table->version] ?? null)));
        }

        $this->insert($table, $rows);

        return count($rows);
    }

    /**
     * Apply versions of rows: each row valid from "at", a null row ending the validity at
     * "at", "erase" removing the row's whole history.
     *
     * @param  list<Version>  $versions
     */
    public function versions(MirrorTable $table, array $versions): void
    {
        $grammar = $table->connection->getQueryGrammar();
        $name = $grammar->wrapTable($table->table);
        $erased = array_values(array_unique(array_map(fn (array $version) => $version['key'], array_filter($versions, fn (array $version) => $version['erase'] ?? false))));

        foreach (array_chunk($erased, 500) as $keys) {
            $table->connection->delete("erase from {$name} where {$grammar->wrap($table->key)} in (".$grammar->parameterize($keys).')', $keys);
        }

        $versions = array_values(array_filter($versions, fn (array $version) => ! in_array($version['key'], $erased, true)));
        usort($versions, fn (array $a, array $b) => $this->instant($a['at']) <=> $this->instant($b['at']));

        $bounds = $this->bounds($table, array_values(array_unique(array_map(fn (array $version) => $version['key'], $versions))));
        $counts = array_count_values(array_map(fn (array $version) => (string) $version['key'], $versions));
        $mapper = new ValueMapper($table->connection);
        $batch = [];

        foreach ($versions as $version) {
            $at = $this->instant($version['at']);
            $key = (string) $version['key'];
            // The next change of the row already in the history ends this version.
            $later = array_filter($bounds[$key] ?? [], fn (DateTimeImmutable $bound) => $bound > $at);
            $to = $later === [] ? null : min($later);

            if ($version['row'] === null) {
                $table->connection->delete(
                    "delete from {$name} for portion of valid_time from {$this->timestamp($at)} to ".($to === null ? 'NULL' : $this->timestamp($to))
                    ." where {$grammar->wrap($table->key)} = ?",
                    [$version['key']],
                );

                continue;
            }

            $row = $mapper->row($version['row']) + ['_valid_from' => new Expression($this->timestamp($at))];

            if ($to !== null) {
                $row['_valid_to'] = new Expression($this->timestamp($to));
            }

            // Rows of keys with one version and no later change go in one insert; the others
            // one by one, in order.
            if ($to === null && $counts[$key] === 1) {
                $batch[] = $row;
            } else {
                $this->insert($table, [$row]);
            }
        }

        $this->insert($table, $batch);
    }

    /**
     * Remove every row and its history.
     */
    public function flush(MirrorTable $table): void
    {
        try {
            $table->connection->statement('erase from '.$table->connection->getQueryGrammar()->wrapTable($table->table).' where true');
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'Table not found')) {
                throw $e;
            }
        }
    }

    /**
     * The valid time boundaries of the rows' history.
     *
     * @param  list<int|string>  $keys
     * @return array<string, list<DateTimeImmutable>>
     */
    protected function bounds(MirrorTable $table, array $keys): array
    {
        $grammar = $table->connection->getQueryGrammar();
        $bounds = [];

        foreach (array_chunk($keys, 500) as $chunk) {
            $rows = $this->select(
                $table,
                "select {$grammar->wrap($table->key)} as k, _valid_from, _valid_to from {$grammar->wrapTable($table->table)} for all valid_time"
                ." where {$grammar->wrap($table->key)} in (".$grammar->parameterize($chunk).')',
                $chunk,
            );

            foreach ($rows as $row) {
                $bounds[(string) $row->k][] = $this->instant((string) $row->_valid_from);

                if ($row->_valid_to !== null) {
                    $bounds[(string) $row->k][] = $this->instant((string) $row->_valid_to);
                }
            }
        }

        return $bounds;
    }

    /**
     * XTDB creates a table on its first insert, and fails reading one it has not seen yet:
     * no rows then.
     *
     * @param  list<mixed>  $bindings
     * @return array<int, stdClass>
     */
    protected function select(MirrorTable $table, string $sql, array $bindings): array
    {
        try {
            /** @var array<int, stdClass> */
            return $table->connection->select($sql, $bindings);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Table not found')) {
                return [];
            }

            throw $e;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function insert(MirrorTable $table, array $rows): void
    {
        $groups = [];

        foreach ($rows as $row) {
            ksort($row);
            $groups[implode(',', array_keys($row))][] = $row;
        }

        foreach ($groups as $group) {
            foreach (array_chunk($group, 500) as $chunk) {
                $table->connection->table($table->table)->insert($chunk);
            }
        }
    }

    protected function newer(mixed $current, mixed $incoming): bool
    {
        if ($current === null || $incoming === null) {
            return false;
        }

        try {
            return $this->instant((string) $current) > $this->instant((string) $incoming);
        } catch (Throwable) {
            return (string) $current > (string) $incoming;
        }
    }

    /**
     * A time of the owner (in the application's time zone when it has no offset) as a UTC instant.
     */
    protected function instant(DateTimeInterface|string $time): DateTimeImmutable
    {
        $time = $time instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($time)
            : new DateTimeImmutable($time, new DateTimeZone((string) config('app.timezone', 'UTC')));

        return $time->setTimezone(new DateTimeZone('UTC'));
    }

    protected function timestamp(DateTimeImmutable $time): string
    {
        return "TIMESTAMP '".$time->format('Y-m-d\TH:i:s.uP')."'";
    }
}
