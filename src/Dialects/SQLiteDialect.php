<?php

namespace DbPortable\Dialects;

class SQLiteDialect extends Dialect
{
    public function jsonNumber(string $path): string
    {
        return 'cast('.$this->jsonText($path).' as real)';
    }

    /**
     * json_extract() returns 1 / 0 for JSON booleans.
     */
    public function jsonBool(string $path): string
    {
        return '('.$this->jsonText($path).' = 1)';
    }

    public function orderNullsLast(string $expression, string $direction): string
    {
        return $expression.' '.$this->direction($direction).' nulls last';
    }

    public function charLength(string $column): string
    {
        return 'length('.$this->grammar->wrap($column).')';
    }

    public function castText(string $column): string
    {
        return 'cast('.$this->grammar->wrap($column).' as text)';
    }

    public function jsonIncrement(string $path, int|float $amount): array
    {
        [$column, $keys] = $this->splitJsonPath($path);

        $jsonPath = "'$".implode('', array_map(fn ($key) => '."'.str_replace(['\\', '"', "'"], ['\\\\', '\\"', "''"], $key).'"', $keys))."'";
        $wrapped = $this->grammar->wrap($column);

        return [$column, sprintf(
            "json_set(coalesce(%s, '{}'), %s, coalesce(%s, 0) + %s)",
            $wrapped, $jsonPath, $this->jsonText($path), $this->number($amount)
        )];
    }
}
