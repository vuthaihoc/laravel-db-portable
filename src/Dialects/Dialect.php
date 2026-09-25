<?php

namespace DbPortable\Dialects;

use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use InvalidArgumentException;
use RuntimeException;

/**
 * SQL fragments whose syntax differs between database families. JSON paths
 * use Laravel's arrow syntax ("column->key->nested"); the text extraction
 * comes from the connection's own grammar.
 */
abstract class Dialect
{
    public function __construct(protected Grammar $grammar) {}

    public static function for(Grammar $grammar): self
    {
        return match (true) {
            $grammar instanceof PostgresGrammar => new PostgresDialect($grammar),
            $grammar instanceof MySqlGrammar => new MySqlDialect($grammar),
            $grammar instanceof SQLiteGrammar => new SQLiteDialect($grammar),
            default => throw new RuntimeException('laravel-db-portable does not support the '.$grammar::class.' grammar.'),
        };
    }

    /**
     * The text value of a JSON path (NULL when the key is missing).
     */
    public function jsonText(string $path): string
    {
        $this->assertJsonPath($path);

        return $this->grammar->wrap($path);
    }

    /**
     * The numeric value of a JSON path, for comparisons, ordering and aggregates.
     */
    abstract public function jsonNumber(string $path): string;

    /**
     * true / false / NULL for a JSON boolean.
     */
    abstract public function jsonBool(string $path): string;

    /**
     * An ORDER BY fragment sorting NULLs after every value.
     */
    abstract public function orderNullsLast(string $expression, string $direction): string;

    /**
     * A column cast to text (JSON, numbers, dates...).
     */
    abstract public function castText(string $column): string;

    /**
     * The length in characters of a string column.
     */
    public function charLength(string $column): string
    {
        return 'char_length('.$this->grammar->wrap($column).')';
    }

    /**
     * [column, expression] setting a JSON path to its numeric value plus $amount
     * (a missing key or NULL column counts as 0).
     *
     * @return array{string, string}
     */
    abstract public function jsonIncrement(string $path, int|float $amount): array;

    /**
     * Split "column->a->b" into the column and its keys.
     *
     * @return array{string, list<string>}
     */
    protected function splitJsonPath(string $path): array
    {
        $this->assertJsonPath($path);

        $segments = array_map('trim', explode('->', $path));
        $column = array_shift($segments);

        return [$column, $segments];
    }

    protected function assertJsonPath(string $path): void
    {
        if (! str_contains($path, '->')) {
            throw new InvalidArgumentException("[{$path}] is not a JSON path: use \"column->key\".");
        }
    }

    protected function direction(string $direction): string
    {
        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Order direction must be "asc" or "desc".');
        }

        return $direction;
    }

    protected function number(int|float $amount): string
    {
        return is_int($amount) ? (string) $amount : rtrim(rtrim(sprintf('%.10F', $amount), '0'), '.');
    }
}
