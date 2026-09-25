<?php

namespace DbPortable\Dialects;

/**
 * MySQL, MariaDB and MatrixOne.
 */
class MySqlDialect extends Dialect
{
    public function jsonNumber(string $path): string
    {
        return 'cast('.$this->jsonText($path).' as double)';
    }

    public function jsonBool(string $path): string
    {
        return '('.$this->jsonText($path)." = 'true')";
    }

    /**
     * MySQL has no NULLS LAST, but already sorts NULLs last in descending
     * order; ascending order sorts on "is null" first. (MatrixOne ignores a
     * DESC key that follows a boolean key, so "is null, x desc" is avoided.)
     */
    public function orderNullsLast(string $expression, string $direction): string
    {
        return $this->direction($direction) === 'desc'
            ? "{$expression} desc"
            : "({$expression}) is null, {$expression} asc";
    }

    /**
     * MatrixOne truncates `cast(... as char)` at 65535 bytes; MySQL has no `as text`.
     */
    public function castText(string $column): string
    {
        return 'cast('.$this->grammar->wrap($column).' as '.($this->isMatrixOne() ? 'text' : 'char').')';
    }

    public function jsonIncrement(string $path, int|float $amount): array
    {
        [$column, $keys] = $this->splitJsonPath($path);

        $jsonPath = "'$".implode('', array_map(fn ($key) => '."'.str_replace(['\\', '"', "'"], ['\\\\', '\\"', "''"], $key).'"', $keys))."'";
        $wrapped = $this->grammar->wrap($column);
        $current = 'cast('.$this->jsonText($path).' as '.(is_int($amount) ? 'signed' : 'double').')';

        return [$column, sprintf(
            'json_set(coalesce(%s, json_object()), %s, coalesce(%s, 0) + %s)',
            $wrapped, $jsonPath, $current, $this->number($amount)
        )];
    }

    protected function isMatrixOne(): bool
    {
        return str_starts_with($this->grammar::class, 'MatrixOne\\');
    }
}
