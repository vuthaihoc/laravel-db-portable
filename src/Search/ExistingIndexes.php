<?php

namespace DbPortable\Search;

use DbPortable\Schema\Family;
use Illuminate\Database\Connection;

/**
 * The indexes of a table: Schema::getIndexes(), and on the PostgreSQL
 * family the index definitions, since expression indexes (full-text,
 * unaccent trigrams) list no columns there.
 */
final class ExistingIndexes
{
    /** @var list<array{name: string, columns: list<string>, type: string|null, primary: bool}>|null */
    private ?array $indexes = null;

    /** @var array<string, string>|null */
    private ?array $definitions = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    /**
     * Index definitions by name, lower-cased, without spaces, quotes or casts.
     *
     * @return array<string, string>
     */
    public function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $this->definitions = [];

        if (Family::of($this->connection) !== Family::POSTGRES) {
            return $this->definitions;
        }

        [$schema, $table] = $this->connection->getSchemaBuilder()->parseSchemaAndTable($this->connection->getTablePrefix().$this->table, true);

        foreach ($this->connection->select('select indexname, indexdef from pg_indexes where schemaname = ? and tablename = ?', [$schema, $table]) as $row) {
            /** @var object{indexname: string, indexdef: string} $row */
            $definition = strtolower($row->indexdef);
            $definition = (string) preg_replace('/:{2,3}[a-z_ ]+(\([^)]*\))?/', '', $definition);
            // PostgreSQL writes "(title)" for a column argument.
            $definition = (string) preg_replace('/([(,]\s*)\(([a-z0-9_]+)\)/', '$1$2', $definition);
            $this->definitions[$row->indexname] = str_replace([' ', '"'], '', $definition);
        }

        return $this->definitions;
    }

    /**
     * The first index whose definition contains one of the needles.
     *
     * @param  list<string>  $needles
     */
    public function definingAny(array $needles): ?string
    {
        foreach ($this->definitions() as $name => $definition) {
            foreach ($needles as $needle) {
                if (str_contains($definition, strtolower($needle))) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * An index of that type (any type when null) on exactly these columns.
     *
     * @param  list<string>  $columns
     */
    public function matching(?string $type, array $columns): ?string
    {
        $wanted = $this->sorted($columns);

        foreach ($this->indexes() as $index) {
            if (! $index['primary'] && $this->sorted($index['columns']) === $wanted
                && ($type === null || strtolower((string) $index['type']) === $type)) {
                return $index['name'];
            }
        }

        return null;
    }

    /**
     * A FULLTEXT index on any of these columns.
     *
     * @param  list<string>  $columns
     */
    public function fullTextOn(array $columns): ?string
    {
        foreach ($this->indexes() as $index) {
            if (strtolower((string) $index['type']) === 'fulltext' && array_intersect($this->sorted($index['columns']), $this->sorted($columns)) !== []) {
                return $index['name'];
            }
        }

        return null;
    }

    /**
     * An index whose first column is this one.
     */
    public function startingWith(string $column): ?string
    {
        foreach ($this->indexes() as $index) {
            if (strtolower($index['columns'][0] ?? '') === strtolower($column) && strtolower((string) $index['type']) !== 'fulltext') {
                return $index['name'];
            }
        }

        return null;
    }

    /**
     * @return list<array{name: string, columns: list<string>, type: string|null, primary: bool}>
     */
    private function indexes(): array
    {
        return $this->indexes ??= $this->connection->getSchemaBuilder()->getIndexes($this->table);
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function sorted(array $columns): array
    {
        $columns = array_map('strtolower', $columns);
        sort($columns);

        return $columns;
    }
}
