<?php

namespace DbPortable\Schema;

use DbPortable\Dialects\Dialect;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Database\Schema\Grammars\MariaDbGrammar;
use Illuminate\Support\Fluent;

/**
 * SQL of the portable index commands (jsonKeyIndex, coveringIndex,
 * trigramIndex, partialIndex) per database family.
 */
final class IndexCompiler
{
    public function __construct(
        private readonly Grammar $grammar,
        private readonly Connection $connection,
    ) {}

    /**
     * @param  Fluent<string, mixed>  $command
     * @return string|list<string>|null
     */
    public function compile(Blueprint $blueprint, Fluent $command): string|array|null
    {
        return match ($command->get('kind')) {
            'jsonKey' => $this->jsonKey($blueprint, $command),
            'covering' => $this->covering($blueprint, $command),
            'trigram' => $this->trigram($blueprint, $command),
            'partial' => $this->partial($blueprint, $command),
            default => null,
        };
    }

    /**
     * An index on the text value of a JSON key.
     *
     * @param  Fluent<string, mixed>  $command
     */
    private function jsonKey(Blueprint $blueprint, Fluent $command): ?string
    {
        $path = (string) $command->get('path');
        $family = Family::of($this->connection);

        if ($family === Family::MYSQL && ($this->grammar instanceof MariaDbGrammar || Family::isMatrixOne($this->connection))) {
            Unsupported::skip("jsonKeyIndex('{$path}') on {$this->connection->getDriverName()}: there are no expression indexes; store the key in its own column and index it.");

            return null;
        }

        $text = Dialect::for($this->connection->getQueryGrammar())->jsonText($path);

        if ($family === Family::MYSQL) {
            // MySQL 8.0.13+ functional key part: JSON values must be cast to be indexed.
            return sprintf('alter table %s add index %s ((cast(%s as char(255)) collate utf8mb4_bin))',
                $this->grammar->wrapTable($blueprint), $this->grammar->wrap($this->name($command)), $text);
        }

        return sprintf('create index %s on %s ((%s))',
            $this->grammar->wrap($this->name($command)), $this->grammar->wrapTable($blueprint), $text);
    }

    /**
     * An index carrying extra columns (INCLUDE on PostgreSQL, also accepted by
     * CockroachDB as STORING); a plain index elsewhere.
     *
     * @param  Fluent<string, mixed>  $command
     * @return string|list<string>|null
     */
    private function covering(Blueprint $blueprint, Fluent $command): string|array|null
    {
        if (Family::of($this->connection) !== Family::POSTGRES) {
            return $this->plainIndex($blueprint, $command);
        }

        return sprintf('create index %s on %s (%s) include (%s)',
            $this->grammar->wrap($this->name($command)),
            $this->grammar->wrapTable($blueprint),
            $this->grammar->columnize($this->columns($command)),
            $this->grammar->columnize((array) $command->get('include')),
        );
    }

    /**
     * Fuzzy text search: a trigram GIN index on PostgreSQL / CockroachDB, an
     * ngram full-text index on MySQL and MatrixOne.
     *
     * @param  Fluent<string, mixed>  $command
     */
    private function trigram(Blueprint $blueprint, Fluent $command): ?string
    {
        $column = $this->columns($command)[0];
        $name = $this->grammar->wrap($this->name($command));

        return match (Family::of($this->connection)) {
            Family::POSTGRES => sprintf('create index %s on %s using gin (%s gin_trgm_ops)', $name, $this->grammar->wrapTable($blueprint), $this->grammar->wrap($column)),
            Family::MYSQL => $this->grammar instanceof MariaDbGrammar
                ? sprintf('alter table %s add fulltext %s (%s)', $this->grammar->wrapTable($blueprint), $name, $this->grammar->wrap($column))
                : (Family::isMatrixOne($this->connection)
                    ? sprintf('create fulltext index %s on %s (%s) with parser ngram', $name, $this->grammar->wrapTable($blueprint), $this->grammar->wrap($column))
                    : sprintf('alter table %s add fulltext %s (%s) with parser ngram', $this->grammar->wrapTable($blueprint), $name, $this->grammar->wrap($column))),
            default => $this->skipTrigram($column),
        };
    }

    /**
     * An index on the rows matching a condition; a plain index where partial
     * indexes do not exist (MySQL, MariaDB, MatrixOne).
     *
     * @param  Fluent<string, mixed>  $command
     * @return string|list<string>|null
     */
    private function partial(Blueprint $blueprint, Fluent $command): string|array|null
    {
        if (Family::of($this->connection) === Family::MYSQL) {
            Unsupported::skip("partialIndex('{$this->name($command)}') on {$this->connection->getDriverName()}: partial indexes do not exist, the index covers every row.");

            return $this->plainIndex($blueprint, $command);
        }

        return sprintf('create index %s on %s (%s) where %s',
            $this->grammar->wrap($this->name($command)),
            $this->grammar->wrapTable($blueprint),
            $this->grammar->columnize($this->columns($command)),
            (string) $command->get('where'),
        );
    }

    private function skipTrigram(string $column): null
    {
        Unsupported::skip("trigramIndex('{$column}') on {$this->connection->getDriverName()}: there is no trigram or ngram index.");

        return null;
    }

    /**
     * @param  Fluent<string, mixed>  $command
     * @return string|list<string>|null
     */
    private function plainIndex(Blueprint $blueprint, Fluent $command): string|array|null
    {
        if (! method_exists($this->grammar, 'compileIndex')) {
            return null;
        }

        $sql = $this->grammar->compileIndex($blueprint, new Fluent([
            'name' => 'index',
            'index' => $this->name($command),
            'columns' => $this->columns($command),
            'algorithm' => null,
        ]));

        return is_string($sql) ? $sql : (is_array($sql) ? array_values(array_map('strval', $sql)) : null);
    }

    /**
     * @param  Fluent<string, mixed>  $command
     * @return list<string>
     */
    private function columns(Fluent $command): array
    {
        return array_values(array_map('strval', (array) $command->get('columns')));
    }

    /**
     * @param  Fluent<string, mixed>  $command
     */
    private function name(Fluent $command): string
    {
        return (string) $command->get('index');
    }
}
