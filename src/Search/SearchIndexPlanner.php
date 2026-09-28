<?php

namespace DbPortable\Search;

use DbPortable\Schema\Family;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use ReflectionAttribute;
use ReflectionMethod;
use Throwable;

/**
 * The indexes a Laravel Scout model needs for the database engines
 * (SCOUT_DRIVER=database, crdb or matrixone), read from the attributes of
 * its toSearchableArray():
 *
 * - #[SearchUsingFullText]: a FULLTEXT index (required on MatrixOne);
 * - #[SearchUsingFuzzy] (cockroachdb-laravel): a trigram index;
 * - #[SearchUsingPrefix]: a trigram index (PostgreSQL family) or an index;
 * - toSearchableEmbedding(): a vector index on the embedding column;
 * - with $like, the other columns (LIKE '%...%'): a trigram index.
 */
final class SearchIndexPlanner
{
    public const FULL_TEXT = 'Laravel\Scout\Attributes\SearchUsingFullText';

    public const PREFIX = 'Laravel\Scout\Attributes\SearchUsingPrefix';

    public const FUZZY = 'YlsIdeas\CockroachDb\Scout\SearchUsingFuzzy';

    private const TEXT_TYPES = ['char', 'varchar', 'character varying', 'character', 'text', 'string', 'citext', 'bpchar', 'tinytext', 'mediumtext', 'longtext'];

    /**
     * @return list<SearchIndex>
     */
    public function plan(Model $model, bool $likeColumns = false): array
    {
        /** @var Connection $connection */
        $connection = $model->getConnection();
        $table = $model->getTable();
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable($table)) {
            return [$this->index($model, 'table', [], SearchIndex::SKIPPED, "table {$table} does not exist on [{$connection->getName()}]")];
        }

        $existing = new ExistingIndexes($connection, $table);
        [$fullText, $fullTextOptions, $prefix, $fuzzy] = $this->attributes($model);
        $plans = [];

        if ($fullText !== []) {
            $plans[] = $this->fullText($model, $connection, $existing, $fullText, $fullTextOptions);
        }

        $trigrams = [];

        foreach ($fuzzy['columns'] as $column) {
            $trigrams[$column.($fuzzy['unaccent'] ? ':u' : '')] = [$column, $fuzzy['unaccent'], 'fuzzy'];
        }

        $likes = $likeColumns ? $this->likeColumns($model, [...$fullText, ...$prefix, ...$fuzzy['columns']]) : [];

        foreach ([...$prefix, ...$likes] as $column) {
            $trigrams[$column] ??= [$column, false, in_array($column, $prefix, true) ? 'prefix' : 'like'];
        }

        foreach ($trigrams as [$column, $unaccent, $reason]) {
            $plans[] = $this->textColumn($model, $connection, $existing, $column, $unaccent, $reason);
        }

        if (method_exists($model, 'toSearchableEmbedding')) {
            $plans[] = $this->vector($model, $connection, $existing);
        }

        return $plans;
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $options
     */
    private function fullText(Model $model, Connection $connection, ExistingIndexes $existing, array $columns, array $options): SearchIndex
    {
        $list = $this->export($columns);
        $name = $this->indexName($connection, $model->getTable(), 'fulltext', $columns);
        $down = "\$table->dropFullText({$list});";

        switch (Family::of($connection)) {
            case Family::POSTGRES:
                $crdb = $connection->getDriverName() === 'crdb';
                $language = $options['language'] ?? ($crdb ? $connection->getConfig('fulltext_language') : null) ?: 'english';
                $language = is_string($language) ? $language : 'english';
                $up = "\$table->fullText({$list})->language('{$language}');";

                $expected = $crdb
                    ? ["to_tsvector('{$language}',", ...array_map(fn ($column) => "coalesce({$column},'')", $columns)]
                    : array_map(fn ($column) => "to_tsvector('{$language}',{$column})", $columns);

                foreach ($existing->definitions() as $index => $definition) {
                    if (! str_contains($definition, 'to_tsvector(') || ! $this->mentionsAll($definition, $columns)) {
                        continue;
                    }

                    if ($this->containsAll($definition, $expected)) {
                        return $this->index($model, 'fulltext', $columns, SearchIndex::OK, $index);
                    }

                    return $this->index($model, 'fulltext', $columns, SearchIndex::OUTDATED,
                        "{$index} does not match whereFullText() (language {$language}".($crdb ? ', coalesce() since cockroachdb-laravel 2.3' : '').')',
                        $up, $down, $index);
                }

                return $this->index($model, 'fulltext', $columns, SearchIndex::MISSING, $name, $up, $down);

            case Family::MYSQL:
                if ($index = $existing->matching('fulltext', $columns)) {
                    return $this->index($model, 'fulltext', $columns, SearchIndex::OK, $index);
                }

                if (Family::isMatrixOne($connection)) {
                    if ($other = $existing->fullTextOn($columns)) {
                        return $this->index($model, 'fulltext', $columns, SearchIndex::SKIPPED,
                            "MatrixOne allows one FULLTEXT index per column and {$other} already covers some of them: drop it first");
                    }

                    if ($connection->getSchemaBuilder()->getForeignKeys($model->getTable()) !== []) {
                        return $this->index($model, 'fulltext', $columns, SearchIndex::SKIPPED,
                            'the table has foreign keys: inserts into a table with a FULLTEXT index and its own foreign key crash MatrixOne 4.2.4');
                    }
                }

                return $this->index($model, 'fulltext', $columns, SearchIndex::MISSING, $name, "\$table->fullText({$list});", $down);

            default:
                return $this->index($model, 'fulltext', $columns, SearchIndex::SKIPPED, "{$connection->getDriverName()} has no full-text indexes");
        }
    }

    private function textColumn(Model $model, Connection $connection, ExistingIndexes $existing, string $column, bool $unaccent, string $reason): SearchIndex
    {
        $family = Family::of($connection);

        if (! $this->isText($connection, $model->getTable(), $column)) {
            return $this->index($model, 'trigram', [$column], SearchIndex::SKIPPED, "{$column} is not a text column");
        }

        if ($family === Family::POSTGRES) {
            $name = $this->indexName($connection, $model->getTable(), 'index', [$column]).($unaccent ? '_trigram_unaccent' : '_trigram');
            $expression = $unaccent ? "(unaccent(lower({$column}))gin_trgm_ops)" : "({$column}gin_trgm_ops)";

            foreach ($existing->definitions() as $index => $definition) {
                if (str_contains($definition, $expression)) {
                    return $this->index($model, 'trigram', [$column], SearchIndex::OK, "{$index} ({$reason})");
                }
            }

            return $this->index($model, 'trigram', [$column], SearchIndex::MISSING, "{$name} ({$reason})",
                "\$table->trigramIndex('{$column}'".($unaccent ? ', unaccent: true' : '').');',
                "\$table->dropIndex('{$name}');");
        }

        if ($reason === 'prefix') {
            if ($index = $existing->startingWith($column)) {
                return $this->index($model, 'index', [$column], SearchIndex::OK, "{$index} (prefix)");
            }

            return $this->index($model, 'index', [$column], SearchIndex::MISSING, $this->indexName($connection, $model->getTable(), 'index', [$column]).' (prefix)',
                "\$table->index('{$column}');", "\$table->dropIndex(['{$column}']);");
        }

        return $this->index($model, 'trigram', [$column], SearchIndex::SKIPPED, $reason === 'fuzzy'
            ? "{$connection->getDriverName()} has no trigram similarity: fuzzy search falls back to LIKE '%...%'"
            : "LIKE '%...%' cannot use an index on {$connection->getDriverName()}");
    }

    private function vector(Model $model, Connection $connection, ExistingIndexes $existing): SearchIndex
    {
        $column = method_exists($model, 'searchableEmbeddingColumn') ? (string) $model->searchableEmbeddingColumn() : 'embedding';

        if (! $connection->getSchemaBuilder()->hasColumn($model->getTable(), $column)) {
            return $this->index($model, 'vector', [$column], SearchIndex::SKIPPED, "no {$column} column: add \$table->vector('{$column}', <dimensions>)");
        }

        $family = Family::of($connection);

        if ($family !== Family::POSTGRES && ! Family::isMatrixOne($connection)) {
            return $this->index($model, 'vector', [$column], SearchIndex::SKIPPED, "{$connection->getDriverName()} has no vector indexes");
        }

        $index = $family === Family::POSTGRES
            ? $existing->definingAny(["({$column}vector_"])
            : $existing->matching(null, [$column]);

        if ($index) {
            return $this->index($model, 'vector', [$column], SearchIndex::OK, $index);
        }

        $name = $this->indexName($connection, $model->getTable(), 'vectorindex', [$column]);

        return $this->index($model, 'vector', [$column], SearchIndex::MISSING, $name,
            "\$table->vectorIndex('{$column}');", "\$table->dropIndex('{$name}');");
    }

    /**
     * The Scout attributes of toSearchableArray().
     *
     * @return array{0: list<string>, 1: array<string, mixed>, 2: list<string>, 3: array{columns: list<string>, unaccent: bool}}
     */
    private function attributes(Model $model): array
    {
        $fullText = $prefix = $fuzzy = [];
        $options = [];
        $unaccent = false;

        if (! method_exists($model, 'toSearchableArray')) {
            return [[], [], [], ['columns' => [], 'unaccent' => false]];
        }

        /** @var ReflectionAttribute<object> $attribute */
        foreach ((new ReflectionMethod($model, 'toSearchableArray'))->getAttributes() as $attribute) {
            $arguments = $attribute->getArguments();
            $columns = array_values(array_map('strval', (array) ($arguments['columns'] ?? $arguments[0] ?? [])));

            switch ($attribute->getName()) {
                case self::FULL_TEXT:
                    $fullText = [...$fullText, ...$columns];
                    $options += (array) ($arguments['options'] ?? $arguments[1] ?? []);
                    break;
                case self::PREFIX:
                    $prefix = [...$prefix, ...$columns];
                    break;
                case self::FUZZY:
                    $fuzzy = [...$fuzzy, ...$columns];
                    $unaccent = $unaccent || (bool) ($arguments['unaccent'] ?? $arguments[2] ?? false);
                    break;
            }
        }

        /** @var array<string, mixed> $options */
        return [array_values(array_unique($fullText)), $options, array_values(array_unique($prefix)), ['columns' => array_values(array_unique($fuzzy)), 'unaccent' => $unaccent]];
    }

    /**
     * The LIKE columns Scout searches: the keys of toSearchableArray()
     * (called on an empty model, as Scout does), without the key and the
     * embedding.
     *
     * @param  list<string>  $except
     * @return list<string>
     */
    private function likeColumns(Model $model, array $except): array
    {
        try {
            $keys = array_keys((array) $model->toSearchableArray()); // @phpstan-ignore method.notFound
        } catch (Throwable) {
            return [];
        }

        $except[] = $model->getKeyName();
        $except[] = method_exists($model, 'searchableEmbeddingColumn') ? (string) $model->searchableEmbeddingColumn() : 'embedding';

        return array_values(array_diff(array_map('strval', $keys), $except));
    }

    private function isText(Connection $connection, string $table, string $column): bool
    {
        foreach ($connection->getSchemaBuilder()->getColumns($table) as $definition) {
            if ($definition['name'] === $column) {
                return in_array(strtolower((string) preg_replace('/\(.*$/', '', (string) $definition['type_name'])), self::TEXT_TYPES, true);
            }
        }

        return false;
    }

    /**
     * Laravel's default index name.
     *
     * @param  list<string>  $columns
     */
    private function indexName(Connection $connection, string $table, string $type, array $columns): string
    {
        return str_replace(['-', '.'], '_', strtolower($connection->getTablePrefix().$table.'_'.implode('_', $columns).'_'.$type));
    }

    /**
     * @param  list<string>  $columns
     */
    private function mentionsAll(string $definition, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! preg_match('/(^|[^a-z0-9_])'.preg_quote(strtolower($column), '/').'([^a-z0-9_]|$)/', $definition)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAll(string $definition, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (! str_contains($definition, strtolower($needle))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $columns
     */
    private function export(array $columns): string
    {
        return '['.implode(', ', array_map(fn ($column) => "'{$column}'", $columns)).']';
    }

    /**
     * @param  list<string>  $columns
     */
    private function index(Model $model, string $kind, array $columns, string $status, string $detail, string $up = '', string $down = '', ?string $replaces = null): SearchIndex
    {
        return new SearchIndex($model::class, $model->getConnectionName(), $model->getTable(), $kind, $columns, $status, $detail, $up, $down, $replaces);
    }
}
