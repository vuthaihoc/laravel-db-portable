<?php

namespace DbPortable\Scan;

/**
 * A SQL construct that only some database families understand.
 */
final class Rule
{
    /**
     * @param  list<string>  $breaksOn  families rejecting it: mysql (MySQL, MariaDB), matrixone, pgsql (PostgreSQL, CockroachDB), sqlite
     * @param  bool  $sqlOnly  only match string literals that look like SQL (for patterns common in plain text)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $pattern,
        public readonly array $breaksOn,
        public readonly string $suggestion,
        public readonly bool $sqlOnly = false,
    ) {}

    /**
     * @return list<self>
     */
    public static function defaults(): array
    {
        return [
            // PostgreSQL / CockroachDB syntax.
            new self('pg-json-operator', "/->>?\\s*'(?!\\$)[^']+'/", ['mysql', 'matrixone', 'sqlite'],
                "where('col->key', ...), whereJsonNumber(), orderByJsonNumber(), Portable::jsonText()/jsonNumber()"),
            new self('pg-cast', '/::\s*(big)?int(eger|[248])?\b|::\s*(smallint|numeric|decimal|float[48]?|real|double precision|bool(ean)?|text|varchar|string|date|time(stamp(tz)?)?|interval|jsonb?|uuid)\b/i', ['mysql', 'matrixone', 'sqlite'],
                'cast(... as ...) or Portable::jsonNumber()/castText()'),
            new self('pg-jsonb-function', '/\bjsonb?_(set|insert|build_object|build_array|agg|object_agg|array_length|typeof|each(_text)?|object_keys|array_elements(_text)?|extract_path(_text)?)\s*\(/i', ['mysql', 'matrixone', 'sqlite'],
                'incrementJson(), whereJsonLength(), whereJsonContains() or update([\'col->key\' => ...])'),
            new self('pg-json-containment', '/@>|<@|\?\||\?&/', ['mysql', 'matrixone', 'sqlite'],
                'whereJsonContains() / whereJsonContainsKey()', true),
            new self('nulls-first-last', '/\bnulls\s+(first|last)\b/i', ['mysql', 'matrixone'],
                'orderByNullsLast() or orderByJsonNumber(..., nullsLast: true)'),
            new self('ilike', '/\bilike\b/i', ['mysql', 'sqlite'],
                'whereLike($column, $value, caseSensitive: false)'),
            new self('pg-date-function', '/\b(date_trunc|to_char|to_timestamp|age|timezone)\s*\(/i', ['mysql', 'matrixone', 'sqlite'],
                'Carbon in PHP, whereDate()/whereYear(), or group by per driver'),
            new self('pg-typed-literal', "/\\b(timestamptz|timestamp|date|interval)\\s+'[^']*'/i", ['mysql', 'matrixone', 'sqlite'],
                'bind a Carbon value instead of a typed literal', true),
            new self('pg-string-agg', '/\bstring_agg\s*\(/i', ['mysql', 'matrixone', 'sqlite'],
                'aggregate in PHP (pluck()->implode()) or per-driver SQL'),
            new self('distinct-on', '/\bdistinct\s+on\s*\(/i', ['mysql', 'matrixone', 'sqlite'],
                'a window function (row_number() over ...) or a grouped subquery'),
            new self('pg-trigram', '/\bsimilarity\s*\(|gin_trgm_ops|\bword_similarity\s*\(/i', ['mysql', 'matrixone', 'sqlite'],
                'full-text search (whereFullText) or LIKE'),
            new self('returning', '/\breturning\s+(\*|["`]?[a-z_][a-z0-9_]*["`]?\s*(,|;|$|\)))/im', ['mysql'],
                'insertGetId() / Eloquent create()', true),

            // MySQL / MariaDB / MatrixOne syntax.
            new self('mysql-backtick', '/`[A-Za-z_][A-Za-z0-9_]*`/', ['pgsql'],
                'unquoted identifiers or the query builder (it quotes per driver)', true),
            new self('mysql-json-function', '/\bjson_(unquote|contains|length|object|search|keys|merge(_patch|_preserve)?)\s*\(/i', ['pgsql', 'sqlite'],
                "where('col->key', ...), whereJsonContains(), whereJsonLength(), Portable::jsonText()"),
            new self('json-extract-set', '/\bjson_(extract|set|insert|replace|remove)\s*\(/i', ['pgsql'],
                "where('col->key', ...), update(['col->key' => ...]), incrementJson(), Portable::jsonText()"),
            new self('ifnull', '/\bifnull\s*\(/i', ['pgsql'],
                'coalesce()', true),
            new self('mysql-if', '/\bif\s*\(/i', ['pgsql', 'sqlite'],
                'case when ... then ... else ... end', true),
            new self('group-concat', '/\bgroup_concat\s*\(/i', ['pgsql'],
                'aggregate in PHP (pluck()->implode()) or per-driver SQL'),
            new self('on-duplicate-key', '/\bon\s+duplicate\s+key\s+update\b/i', ['pgsql', 'sqlite'],
                'upsert()'),
            new self('mysql-date-function', '/\b(date_format|unix_timestamp|from_unixtime|str_to_date|date_add|date_sub|timestampdiff|curdate)\s*\(/i', ['pgsql', 'sqlite'],
                'Carbon in PHP or whereDate()/whereYear()'),
            new self('mysql-limit-offset', '/\blimit\s+\d+\s*,\s*\d+/i', ['pgsql'],
                '->offset($n)->limit($m)', true),
            new self('mysql-insert-ignore', '/\binsert\s+ignore\b/i', ['pgsql', 'sqlite'],
                'insertOrIgnore()'),
        ];
    }
}
