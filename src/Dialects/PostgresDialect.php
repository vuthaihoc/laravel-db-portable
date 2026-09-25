<?php

namespace DbPortable\Dialects;

/**
 * PostgreSQL and CockroachDB.
 */
class PostgresDialect extends Dialect
{
    public function jsonNumber(string $path): string
    {
        return '('.$this->jsonText($path).')::numeric';
    }

    public function jsonBool(string $path): string
    {
        return '('.$this->jsonText($path).')::boolean';
    }

    public function orderNullsLast(string $expression, string $direction): string
    {
        return $expression.' '.$this->direction($direction).' nulls last';
    }

    public function castText(string $column): string
    {
        return $this->grammar->wrap($column).'::text';
    }

    public function jsonIncrement(string $path, int|float $amount): array
    {
        [$column, $keys] = $this->splitJsonPath($path);

        $pointer = "'{".implode(',', array_map(fn ($key) => '"'.str_replace(['\\', '"', "'"], ['\\\\', '\\"', "''"], $key).'"', $keys))."}'";
        $wrapped = $this->grammar->wrap($column);

        return [$column, sprintf(
            "jsonb_set(case when jsonb_typeof(%s::jsonb) = 'object' then %s::jsonb else '{}'::jsonb end, %s, to_jsonb(coalesce(%s, 0) + %s), true)",
            $wrapped, $wrapped, $pointer, $this->jsonNumber($path), $this->number($amount)
        )];
    }
}
