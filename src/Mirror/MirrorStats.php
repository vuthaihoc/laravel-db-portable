<?php

namespace DbPortable\Mirror;

use DateTimeImmutable;
use DateTimeZone;
use DbPortable\Dialects\Dialect;
use DbPortable\Schema\Family;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * mirror:stats: what each server knows about the owner table and the mirror
 * table (no scan), and, on request, exact aggregates of what both share and
 * the keys that differ.
 *
 * @phpstan-type Row array{0: string, 1: string, 2: string, 3: string}
 * @phpstan-type Report array{mirror: string, owner: string, state: string, owner_connection: string, mirror_connection: string, rows: list<Row>, notes: list<string>, differs: bool}
 */
class MirrorStats
{
    /** Key ranges compared by --keys. */
    protected const BUCKETS = 20;

    /** Keys listed per side. */
    protected const LISTED = 10;

    /** Rows whose keys are compared one by one when the key is not an integer. */
    protected const MAX_KEYS = 100000;

    public function __construct(
        protected MirrorRegistry $registry,
        protected MirrorSync $sync,
    ) {}

    /**
     * @param  class-string<Model>  $owner
     * @return Report
     */
    public function report(string $mirror, string $owner, bool $compare = false, bool $keys = false): array
    {
        $declaration = $this->registry->mirrorsOf($owner)[$mirror];
        $config = $this->registry->config($mirror);
        $table = MirrorTable::of($declaration->model);
        /** @var Model $ownerModel */
        $ownerModel = new $owner;
        /** @var Connection $ownerConnection */
        $ownerConnection = $ownerModel->getConnection();
        $ownerTable = $ownerModel->getTable();
        $rows = [['table', $ownerTable, $table->table, '']];
        $notes = [];
        $differs = false;

        $ownerServer = $this->server($ownerConnection, $ownerTable);
        $mirrorServer = $this->server($table->connection, $table->table);

        foreach (array_keys($ownerServer + $mirrorServer) as $label) {
            $rows[] = ['server: '.$label, $ownerServer[$label] ?? '', $mirrorServer[$label] ?? '', ''];
        }

        $queue = $this->queue($mirror, $owner, $config);
        $rows[] = ['queue', '', $queue, ''];

        $mirrorSchema = $table->connection->getSchemaBuilder();

        if (! $mirrorSchema->hasTable($table->table)) {
            $notes[] = "The mirror table {$table->table} does not exist: db-portable:mirror:schema creates it.";

            return $this->result($mirror, $owner, $this->registry->state($mirror, $owner), $ownerConnection, $table, $rows, $notes, $compare || $keys);
        }

        $ownerColumns = $this->columns($ownerConnection, $ownerTable);
        $mirrorColumns = $this->columns($table->connection, $table->table);
        $ownerNames = array_map(fn (string $column) => $column === $table->ownerKey ? $table->key : $column, array_keys($ownerColumns));
        // fromOwner() chooses the mirrored columns: the others are left out on purpose.
        $reshaped = method_exists($declaration->model, 'fromOwner');
        $missing = $reshaped ? [] : array_diff($ownerNames, array_keys($mirrorColumns));
        $extra = $reshaped ? [] : array_diff(array_keys($mirrorColumns), $ownerNames);

        if ($missing !== []) {
            $notes[] = 'Owner columns not on the mirror (not mirrored, or db-portable:mirror:schema adds them): '.implode(', ', $missing).'.';
        }

        if ($extra !== []) {
            $notes[] = 'Mirror columns not on the owner (computed, or dropped on the owner): '.implode(', ', $extra).'.';
        }

        array_push($notes, ...$this->relations($declaration->model, $mirror));

        $shared = [];

        foreach ($ownerColumns as $name => $column) {
            $mirrorName = $name === $table->ownerKey ? $table->key : $name;

            if (isset($mirrorColumns[$mirrorName])) {
                $shared[$name] = [$mirrorName, $column, $mirrorColumns[$mirrorName]];
            }
        }

        // The owner rows the mirror holds: those of the mirror model's ownerQuery().
        $ownerRows = $this->sync->ownerQuery($declaration->model, $owner)->toBase()
            ->cloneWithout(['columns', 'orders', 'limit', 'offset'])
            ->cloneWithoutBindings(['select', 'order']);

        if ($compare) {
            $differs = $this->compare($ownerRows, $ownerTable, $table, $shared, $ownerModel, $rows);
        }

        if ($keys) {
            $differs = $this->keys($ownerRows, $ownerTable, $table, $ownerColumns[$table->ownerKey] ?? null, $rows) || $differs;
        }

        return $this->result($mirror, $owner, $this->registry->state($mirror, $owner), $ownerConnection, $table, $rows, $notes, $differs);
    }

    /**
     * What a server keeps about a table, without scanning it.
     *
     * @return array<string, string>
     */
    public function server(Connection $connection, string $table): array
    {
        $name = $connection->getTablePrefix().$table;

        try {
            return match (true) {
                Family::isXtdb($connection) => ['rows' => 'none kept (XTDB)'],
                Family::isMatrixOne($connection) => $this->matrixOne($connection, $name),
                Family::isCockroachDb($connection) => $this->cockroachDb($connection, $table),
                Family::of($connection) === Family::POSTGRES => $this->postgres($connection, $name),
                Family::of($connection) === Family::MYSQL => $this->mysql($connection, $name),
                Family::of($connection) === Family::SQLITE => $this->sqlite($connection, $name),
                default => [],
            };
        } catch (Throwable $e) {
            return ['statistics' => 'unavailable: '.$this->short($e)];
        }
    }

    /**
     * @return array<string, string>
     */
    protected function postgres(Connection $connection, string $table): array
    {
        $row = $connection->selectOne(
            'select c.reltuples::bigint as estimate, pg_total_relation_size(c.oid) as size, s.n_live_tup as live, s.n_dead_tup as dead, '
            .'coalesce(s.last_analyze, s.last_autoanalyze) as analyzed from pg_class c join pg_namespace n on n.oid = c.relnamespace '
            .'left join pg_stat_user_tables s on s.relid = c.oid where c.relname = ? and n.nspname = current_schema()',
            [$table],
        );

        if ($row === null) {
            return ['rows' => 'no table'];
        }

        $stats = [
            'rows' => $row->estimate < 0 ? 'not analyzed' : '~'.number_format((int) $row->estimate),
            'size' => $this->bytes((int) $row->size),
            'live / dead rows' => number_format((int) $row->live).' / '.number_format((int) $row->dead),
            'analyzed' => (string) ($row->analyzed ?? 'never'),
        ];

        $columns = $connection->select('select attname, null_frac, n_distinct from pg_stats where schemaname = current_schema() and tablename = ? order by attname', [$table]);

        foreach ($columns as $column) {
            $distinct = (float) $column->n_distinct;
            $stats["{$column->attname}: nulls, distinct"] = round((float) $column->null_frac * 100, 1).'% null, '
                .($distinct < 0 ? round(-$distinct * 100).'% distinct' : number_format($distinct).' distinct');
        }

        return $stats;
    }

    /**
     * @return array<string, string>
     */
    protected function cockroachDb(Connection $connection, string $table): array
    {
        $rows = $connection->select('select column_names, row_count, distinct_count, null_count, created from [show statistics for table '.$connection->getQueryGrammar()->wrapTable($table).'] order by created desc');

        if ($rows === []) {
            return ['rows' => 'no statistics yet'];
        }

        $stats = ['rows' => '~'.number_format((int) $rows[0]->row_count), 'statistics' => (string) $rows[0]->created];

        foreach ($rows as $row) {
            $columns = trim((string) $row->column_names, '{}');
            $label = "{$columns}: nulls, distinct";

            if (! str_contains($columns, ',') && ! isset($stats[$label])) {
                $stats[$label] = number_format((int) $row->null_count).' null, '.number_format((int) $row->distinct_count).' distinct';
            }
        }

        return $stats;
    }

    /**
     * @return array<string, string>
     */
    protected function mysql(Connection $connection, string $table): array
    {
        $row = $connection->selectOne(
            'select table_rows as estimate, data_length as data, index_length as idx, update_time as updated from information_schema.tables where table_schema = database() and table_name = ?',
            [$table],
        );

        if ($row === null) {
            return ['rows' => 'no table'];
        }

        $stats = [
            'rows' => '~'.number_format((int) $row->estimate),
            'size' => $this->bytes((int) $row->data + (int) $row->idx),
            'updated' => (string) ($row->updated ?? 'unknown'),
        ];

        $indexes = $connection->select(
            'select index_name as name, max(cardinality) as cardinality from information_schema.statistics where table_schema = database() and table_name = ? group by index_name order by index_name',
            [$table],
        );

        foreach ($indexes as $index) {
            $stats["index {$index->name}: cardinality"] = number_format((int) $index->cardinality);
        }

        return $stats;
    }

    /**
     * @return array<string, string>
     */
    protected function matrixOne(Connection $connection, string $table): array
    {
        $database = $connection->getDatabaseName();
        $row = $connection->selectOne('select mo_table_rows(?, ?) as estimate, mo_table_size(?, ?) as size', [$database, $table, $database, $table]);
        $object = str_replace("'", "''", $database.'.'.$table);
        $flushed = $connection->selectOne("select max(rows_cnt) as n from (select col_name, sum(rows_cnt) as rows_cnt from metadata_scan('{$object}', '*') g group by col_name) s");

        return [
            'rows' => number_format((int) ($row->estimate ?? 0)).' (refreshed asynchronously)',
            'size' => $this->bytes((int) ($row->size ?? 0)).' (refreshed asynchronously)',
            'rows of flushed data' => number_format((int) ($flushed->n ?? 0)),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function sqlite(Connection $connection, string $table): array
    {
        $analyzed = $connection->selectOne("select 1 as present from sqlite_master where type = 'table' and name = 'sqlite_stat1'");
        $row = $analyzed === null ? null : $connection->selectOne('select stat from sqlite_stat1 where tbl = ? limit 1', [$table]);

        return ['rows' => $row === null ? 'not analyzed (ANALYZE)' : '~'.number_format((int) strtok((string) $row->stat, ' '))];
    }

    /**
     * The mirror's queue: jobs waiting, and failed jobs of this owner model and mirror.
     *
     * @param  array{queue_connection: string|null, queue: string|null}  $config
     */
    protected function queue(string $mirror, string $owner, array $config): string
    {
        try {
            $pending = number_format(app('queue')->connection($config['queue_connection'])->size($config['queue']))
                .' pending on '.($config['queue'] === null ? 'the default queue (every job)' : "[{$config['queue']}]");
        } catch (Throwable) {
            $pending = 'pending jobs unknown';
        }

        try {
            $needle = "({$owner} → {$mirror})";
            $failed = count(array_filter(
                (array) app('queue.failer')->all(),
                fn ($job) => str_contains((string) (json_decode((string) ($job->payload ?? ''), true)['displayName'] ?? ''), $needle),
            ));
            $failed = number_format($failed).' failed';
        } catch (Throwable) {
            $failed = 'failed jobs unknown';
        }

        return "{$pending}, {$failed}";
    }

    /**
     * Relations of the mirror model to mirror models whose table is missing or empty.
     *
     * @param  class-string<MirrorModel>  $mirrorModel
     * @return list<string>
     */
    protected function relations(string $mirrorModel, string $mirror): array
    {
        $notes = [];
        $model = new $mirrorModel;

        foreach ((new ReflectionClass($mirrorModel))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();

            if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0 || $method->getDeclaringClass()->getName() === MirrorModel::class
                || ! $type instanceof ReflectionNamedType || ! is_a($type->getName(), Relation::class, true)) {
                continue;
            }

            $name = $method->getName();

            try {
                $related = $model->{$name}()->getRelated();

                if (! $related instanceof MirrorModel) {
                    continue;   // an owner model, read in the owner database
                }

                if ($related::mirrorName() !== $mirror) {
                    $notes[] = "Relation {$name}: ".$related::class." reads the [{$related::mirrorName()}] mirror.";
                } elseif (! $related->getConnection()->getSchemaBuilder()->hasTable($related->getTable())) {
                    $notes[] = "Relation {$name}: the table of ".$related::class.' is missing.';
                } elseif (! $related->newQuery()->exists()) {
                    $notes[] = "Relation {$name}: the table of ".$related::class.' is empty.';
                }
            } catch (Throwable $e) {
                $notes[] = "Relation {$name}: ".$this->short($e);
            }
        }

        return $notes;
    }

    /**
     * One aggregate query per side over the columns both tables have.
     *
     * @param  array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>  $shared  owner column => [mirror column, owner definition, mirror definition]
     * @param  list<Row>  $rows
     * @return bool whether the sides differ
     */
    protected function compare(QueryBuilder $ownerRows, string $ownerTable, MirrorTable $table, array $shared, Model $owner, array &$rows): bool
    {
        /** @var Connection $ownerConnection */
        $ownerConnection = $ownerRows->getConnection();
        $casts = $owner->getCasts();
        $version = $owner->usesTimestamps() ? $owner->getUpdatedAtColumn() : null;
        $ownerSelects = $mirrorSelects = ['count(*)'];
        $labels = ['count(*)'];
        $kinds = ['count'];

        foreach ($shared as $name => [$mirrorName, $ownerColumn, $mirrorColumn]) {
            $cast = $casts[$name] ?? null;
            $kind = $this->kind($ownerColumn, is_string($cast) ? $cast : null);
            $ownerWrapped = $ownerConnection->getQueryGrammar()->wrap("{$ownerTable}.{$name}");
            $mirrorWrapped = $table->connection->getQueryGrammar()->wrap($mirrorName);
            $templates = ['nulls' => 'sum(case when %s is null then 1 else 0 end)'];

            if ($kind === 'number') {
                $templates += ['min' => 'min(%s)', 'max' => 'max(%s)', 'sum' => 'sum(%s)'];
            } elseif ($kind === 'date') {
                $templates += ['min' => 'min(%s)', 'max' => 'max(%s)'];
            }

            $aggregates = [];

            foreach ($templates as $aggregate => $template) {
                $aggregates[$aggregate] = [sprintf($template, $ownerWrapped), sprintf($template, $mirrorWrapped)];
            }

            if ($kind === 'bool') {
                $aggregates['true'] = [$this->trueCount($ownerConnection, "{$ownerTable}.{$name}", $ownerColumn), $this->trueCount($table->connection, $mirrorName, $mirrorColumn)];
            } elseif ($kind === 'string') {
                $aggregates['length sum'] = [
                    'sum('.Dialect::for($ownerConnection->getQueryGrammar())->charLength("{$ownerTable}.{$name}").')',
                    'sum('.Dialect::for($table->connection->getQueryGrammar())->charLength($mirrorName).')',
                ];
            }

            foreach ($aggregates as $aggregate => [$ownerSql, $mirrorSql]) {
                $ownerSelects[] = $ownerSql;
                $mirrorSelects[] = $mirrorSql;
                $labels[] = "{$name} {$aggregate}";
                $kinds[] = in_array($aggregate, ['min', 'max'], true) ? $kind : 'number';
            }
        }

        $ownerValues = $this->aggregate(clone $ownerRows, $ownerSelects);
        $mirrorValues = $this->aggregate($table->connection->table($table->table), $mirrorSelects);
        $differs = false;

        foreach ($labels as $index => $label) {
            [$ownerValue, $mirrorValue] = [$this->normalize($ownerValues[$index], $kinds[$index]), $this->normalize($mirrorValues[$index], $kinds[$index])];
            $note = '';

            if ($ownerValue !== $mirrorValue) {
                $differs = true;
                $note = $kinds[$index] === 'count' && is_numeric($ownerValue) && is_numeric($mirrorValue)
                    ? '≠ '.number_format(abs((int) $ownerValue - (int) $mirrorValue))
                    : '≠';
            }

            if ($version !== null && $label === "{$version} max" && $ownerValue !== '' && $mirrorValue !== '') {
                $lag = $this->seconds($ownerValue) - $this->seconds($mirrorValue);
                $note = $lag > 0 ? "≠ lag {$lag} s" : $note;
            }

            $rows[] = [$label, $ownerValue, $mirrorValue, $note];
        }

        return $differs;
    }

    /**
     * Rows per key range on both sides, then the keys of the ranges that differ.
     *
     * @param  array<string, mixed>|null  $keyColumn  the owner key column
     * @param  list<Row>  $rows
     * @return bool whether the keys differ
     */
    protected function keys(QueryBuilder $ownerRows, string $ownerTable, MirrorTable $table, ?array $keyColumn, array &$rows): bool
    {
        $qualified = "{$ownerTable}.{$table->ownerKey}";
        $ownerKey = $ownerRows->getGrammar()->wrap($qualified);
        $mirrorKey = $table->connection->getQueryGrammar()->wrap($table->key);
        $mirrorRows = fn () => $table->connection->table($table->table);

        if ($keyColumn !== null && $this->kind($keyColumn, null) === 'number') {
            $ownerRange = (array) (clone $ownerRows)->selectRaw("min({$ownerKey}) as low, max({$ownerKey}) as high")->first();
            $mirrorRange = (array) $mirrorRows()->selectRaw("min({$mirrorKey}) as low, max({$mirrorKey}) as high")->first();
            $bounds = array_filter([$ownerRange['low'], $ownerRange['high'], $mirrorRange['low'], $mirrorRange['high']], fn ($value) => $value !== null);

            if ($bounds === []) {
                $rows[] = ['keys', 'none', 'none', ''];

                return false;
            }

            $low = (int) min($bounds);
            $width = max(1, intdiv((int) max($bounds) - $low, self::BUCKETS) + 1);
            $ownerBuckets = $this->buckets(clone $ownerRows, $ownerKey, $low, $width);
            $mirrorBuckets = $this->buckets($mirrorRows(), $mirrorKey, $low, $width);
            [$missing, $extra, $ranges] = [[], [], 0];

            foreach (array_unique([...array_keys($ownerBuckets), ...array_keys($mirrorBuckets)]) as $bucket) {
                if (($ownerBuckets[$bucket] ?? 0) === ($mirrorBuckets[$bucket] ?? 0)) {
                    continue;
                }

                $ranges++;
                [$from, $to] = [$low + $bucket * $width, $low + ($bucket + 1) * $width - 1];
                $ownerKeys = (clone $ownerRows)->whereBetween($qualified, [$from, $to])->limit(self::MAX_KEYS)->pluck($qualified)->all();
                $mirrorKeys = $mirrorRows()->whereBetween($table->key, [$from, $to])->limit(self::MAX_KEYS)->pluck($table->key)->all();
                array_push($missing, ...$this->minus($ownerKeys, $mirrorKeys));
                array_push($extra, ...$this->minus($mirrorKeys, $ownerKeys));
            }

            $rows[] = ['key ranges that differ', '', number_format($ranges).' of '.number_format(count(array_unique([...array_keys($ownerBuckets), ...array_keys($mirrorBuckets)]))), $ranges > 0 ? '≠' : ''];
        } else {
            $count = max((clone $ownerRows)->count(), $mirrorRows()->count());

            if ($count > self::MAX_KEYS) {
                $rows[] = ['keys', '', "not compared: more than {$this->format(self::MAX_KEYS)} rows with a key that is not an integer", ''];

                return false;
            }

            $ownerKeys = (clone $ownerRows)->pluck($qualified)->all();
            $mirrorKeys = $mirrorRows()->pluck($table->key)->all();
            [$missing, $extra] = [$this->minus($ownerKeys, $mirrorKeys), $this->minus($mirrorKeys, $ownerKeys)];
        }

        $rows[] = ['keys missing on the mirror', '', $this->listed($missing), $missing === [] ? '' : '≠'];
        $rows[] = ['keys only on the mirror', '', $this->listed($extra), $extra === [] ? '' : '≠'];

        return $missing !== [] || $extra !== [];
    }

    /**
     * @return array<int, int> rows by bucket
     */
    protected function buckets(QueryBuilder $rows, string $key, int $low, int $width): array
    {
        // The bounds are inlined integers: bound parameters make CockroachDB return no bucket.
        $bucket = "floor(({$key} - {$low}) / {$width})";
        $buckets = [];

        foreach ($rows->selectRaw("{$bucket} as bucket, count(*) as n")->groupByRaw($bucket)->get() as $row) {
            $buckets[(int) $row->bucket] = (int) $row->n;
        }

        return $buckets;
    }

    /**
     * @param  list<string>  $selects
     * @return list<mixed>
     */
    protected function aggregate(QueryBuilder $rows, array $selects): array
    {
        $aliases = array_map(fn (string $select, int $index) => "{$select} as a{$index}", $selects, array_keys($selects));
        $row = (array) $rows->selectRaw(implode(', ', $aliases))->first();

        return array_map(fn (int $index) => $row["a{$index}"] ?? null, array_keys($selects));
    }

    /**
     * Rows where a boolean column is true.
     *
     * @param  array<string, mixed>  $column
     */
    protected function trueCount(Connection $connection, string $name, array $column): string
    {
        $wrapped = $connection->getQueryGrammar()->wrap($name);
        $boolean = in_array(strtolower((string) $column['type_name']), ['bool', 'boolean'], true);

        return 'sum(case when '.($boolean || Family::of($connection) !== Family::POSTGRES ? $wrapped : "{$wrapped} <> 0").' then 1 else 0 end)';
    }

    /**
     * @param  array<string, mixed>  $column
     * @return 'number'|'bool'|'date'|'string'|'other'
     */
    protected function kind(array $column, ?string $cast): string
    {
        $typeName = strtolower((string) $column['type_name']);
        $type = strtolower((string) $column['type']);
        $cast = strtolower(explode(':', (string) $cast)[0]);

        return match (true) {
            in_array($typeName, ['bool', 'boolean'], true) || $type === 'tinyint(1)' || in_array($cast, ['bool', 'boolean'], true) => 'bool',
            in_array($cast, ['array', 'json', 'object', 'collection'], true) || str_starts_with($cast, 'encrypted') => 'other',
            (bool) preg_match('/int|decimal|numeric|number|float|double|real|year/', $typeName) => 'number',
            (bool) preg_match('/date|time/', $typeName) => 'date',
            (bool) preg_match('/char|text|uuid|enum|string/', $typeName) => 'string',
            default => 'other',
        };
    }

    /**
     * A value as both sides can be compared: numbers without insignificant zeros, dates in UTC.
     */
    protected function normalize(mixed $value, string $kind): string
    {
        if ($value === null) {
            return '';
        }

        if ($kind === 'date') {
            try {
                $date = (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone('UTC'));

                return $date->format($date->format('u') === '000000' ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u');
            } catch (Throwable) {
                return (string) $value;
            }
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;   // exact: keys beyond 2^53 do not survive a float
        }

        if (is_numeric($value)) {
            $number = is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) ? $value : sprintf('%.10F', (float) $value);

            return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }

    /**
     * @param  array<mixed>  $keys
     * @param  array<mixed>  $others
     * @return list<string>
     */
    protected function minus(array $keys, array $others): array
    {
        $others = array_flip(array_map(fn ($key) => (string) $key, $others));

        return array_values(array_filter(array_map(fn ($key) => (string) $key, $keys), fn (string $key) => ! isset($others[$key])));
    }

    /**
     * @param  list<string>  $keys
     */
    protected function listed(array $keys): string
    {
        if ($keys === []) {
            return 'none';
        }

        sort($keys);

        return $this->format(count($keys)).': '.implode(', ', array_slice($keys, 0, self::LISTED)).(count($keys) > self::LISTED ? ', ...' : '');
    }

    /**
     * @param  list<Row>  $rows
     * @param  list<string>  $notes
     * @return Report
     */
    protected function result(string $mirror, string $owner, string $state, Connection $ownerConnection, MirrorTable $table, array $rows, array $notes, bool $differs): array
    {
        return [
            'mirror' => $mirror,
            'owner' => $owner,
            'state' => $state,
            'owner_connection' => (string) $ownerConnection->getName(),
            'mirror_connection' => (string) $table->connection->getName(),
            'rows' => $rows,
            'notes' => $notes,
            'differs' => $differs,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function columns(Connection $connection, string $table): array
    {
        $columns = [];

        foreach ($connection->getSchemaBuilder()->getColumns($table) as $column) {
            $columns[(string) $column['name']] = $column;
        }

        return $columns;
    }

    protected function bytes(int $bytes): string
    {
        $size = (float) $bytes;

        foreach (['B', 'KB', 'MB'] as $unit) {
            if ($size < 1024) {
                return ($unit === 'B' ? $bytes : round($size, 1)).' '.$unit;
            }

            $size /= 1024;
        }

        return round($size, 1).' GB';
    }

    protected function seconds(string $date): int
    {
        try {
            return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable) {
            return 0;
        }
    }

    protected function format(int $number): string
    {
        return number_format($number);
    }

    protected function short(Throwable $e): string
    {
        return substr((string) preg_replace('/\s+/', ' ', $e->getMessage()), 0, 160);
    }
}
