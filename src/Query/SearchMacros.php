<?php

namespace DbPortable\Query;

use DbPortable\Schema\Family;
use DbPortable\Schema\Unsupported;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Search box helpers named by intent. The drivers implement them where the
 * database can: CockroachDB (vuthaihoc/cockroachdb-laravel: trigrams,
 * ts_rank) and MatrixOne (vuthaihoc/laravel-matrixone: prefixes, MATCH
 * relevance). These macros only run when the builder has no method of that
 * name: PostgreSQL (pg_trgm, ts_rank), MySQL/MariaDB (LIKE, MATCH), SQLite,
 * and the similarity methods on MatrixOne.
 */
final class SearchMacros
{
    public static function register(): void
    {
        // Values starting with the search, ignoring case; % and _ are matched literally.
        Builder::macro('whereStartsWith', function (string $column, string $value, bool $unaccent = false, string $boolean = 'and') {
            /** @var Builder $this */
            return SearchMacros::whereLike($this, $column, SearchMacros::escapeLike($value).'%', $unaccent, $boolean);
        });

        // Values containing the search, ignoring case.
        Builder::macro('whereContains', function (string $column, string $value, bool $unaccent = false, string $boolean = 'and') {
            /** @var Builder $this */
            return SearchMacros::whereLike($this, $column, '%'.SearchMacros::escapeLike($value).'%', $unaccent, $boolean);
        });

        // Typo tolerant (trigram similarity) where the database has it, else whereContains().
        Builder::macro('whereSimilar', function (string $column, string $value, ?float $threshold = null, bool $unaccent = false, string $boolean = 'and') {
            /** @var Builder $this */
            if (! SearchMacros::hasTrigrams($this)) {
                SearchMacros::skip($this, 'whereSimilar()', 'no trigram similarity, whereContains() is used instead');

                return SearchMacros::whereLike($this, $column, '%'.SearchMacros::escapeLike($value).'%', false, $boolean);
            }

            [$expression, $placeholder] = SearchMacros::operands($this, $column, $unaccent);

            return $threshold === null
                ? $this->whereRaw("{$expression} % {$placeholder}", [$value], $boolean)
                : $this->whereRaw("({$expression} % {$placeholder} and similarity({$expression}, {$placeholder}) >= ?)", [$value, $value, $threshold], $boolean);
        });

        // similarity() as a column; elsewhere 1 (starts with), 0.5 (contains) or 0.
        Builder::macro('selectSimilarity', function (string $column, string $value, string $as = 'similarity', bool $unaccent = false) {
            /** @var Builder $this */
            [$sql, $bindings] = SearchMacros::similarity($this, $column, $value, $unaccent, 'selectSimilarity()');

            return $this->selectRaw("{$sql} as {$this->getGrammar()->wrap($as)}", $bindings);
        });

        Builder::macro('orderBySimilarity', function (string $column, string $value, string $direction = 'desc', bool $unaccent = false) {
            /** @var Builder $this */
            [$sql, $bindings] = SearchMacros::similarity($this, $column, $value, $unaccent, 'orderBySimilarity()');

            return $this->orderByRaw($sql.' '.SearchMacros::direction($direction), $bindings);
        });

        // A search box: values starting with the search; from 3 characters also
        // values containing it (or similar, with trigrams). Prefix matches first,
        // then the most similar, then the shortest.
        Builder::macro('suggest', function (string $column, string $value, bool $unaccent = false) {
            /** @var Builder $this */
            return SearchMacros::suggest($this, $column, $value, $unaccent);
        });

        Builder::macro('selectFullTextRelevance', function (string|array $columns, string $value, string $as = 'relevance', array $options = []) {
            /** @var Builder $this */
            /** @var array<string, mixed> $options */
            $sql = SearchMacros::relevance($this, array_values(array_filter((array) $columns, 'is_string')), $options);

            return $this->selectRaw("{$sql} as {$this->getGrammar()->wrap($as)}", $sql === '0' ? [] : [$value]);
        });

        Builder::macro('orderByFullTextRelevance', function (string|array $columns, string $value, array $options = [], string $direction = 'desc') {
            /** @var Builder $this */
            /** @var array<string, mixed> $options */
            $sql = SearchMacros::relevance($this, array_values(array_filter((array) $columns, 'is_string')), $options);

            return $sql === '0' ? $this : $this->orderByRaw($sql.' '.SearchMacros::direction($direction), [$value]);
        });

        // whereFullText() with the most relevant matches first.
        Builder::macro('searchFullText', function (string|array $columns, string $value, array $options = []) {
            /** @var Builder $this */
            /** @var array<string, mixed> $options */
            return $this->whereFullText($columns, $value, $options)->orderByFullTextRelevance($columns, $value, $options);
        });
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public static function whereLike(Builder $query, string $column, string $pattern, bool $unaccent, string $boolean): Builder
    {
        [$sql, $bindings] = self::like($query, $column, $pattern, $unaccent);

        return $query->whereRaw($sql, $bindings, $boolean);
    }

    /**
     * A case-insensitive LIKE: ilike on PostgreSQL and MatrixOne, the (_ci)
     * collation on MySQL, SQLite's ASCII case folding. `$unaccent` needs PostgreSQL's
     * unaccent extension; elsewhere the collation decides.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function like(Builder $query, string $column, string $pattern, bool $unaccent): array
    {
        $wrapped = $query->getGrammar()->wrap($column);

        return match (Family::of(self::connection($query))) {
            Family::POSTGRES => $unaccent
                ? ["unaccent(lower({$wrapped})) like unaccent(lower(?))", [$pattern]]
                : ["{$wrapped} ilike ?", [$pattern]],
            Family::SQLITE => ["{$wrapped} like ? escape '\\'", [$pattern]],
            // MatrixOne's LIKE ignores _ci collations.
            default => [$wrapped.(Family::isMatrixOne(self::connection($query)) ? ' ilike ?' : ' like ?'), [$pattern]],
        };
    }

    public static function hasTrigrams(Builder $query): bool
    {
        return Family::of(self::connection($query)) === Family::POSTGRES;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function operands(Builder $query, string $column, bool $unaccent): array
    {
        $wrapped = $query->getGrammar()->wrap($column);

        return $unaccent ? ["unaccent(lower({$wrapped}))", 'unaccent(lower(?))'] : [$wrapped, '?'];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    public static function similarity(Builder $query, string $column, string $value, bool $unaccent, string $method): array
    {
        if (self::hasTrigrams($query)) {
            [$expression, $placeholder] = self::operands($query, $column, $unaccent);

            return ["similarity({$expression}, {$placeholder})", [$value]];
        }

        self::skip($query, $method, 'no trigram similarity, the score is 1 for values starting with the search, 0.5 for values containing it, else 0');

        [$startsWith, $prefix] = self::like($query, $column, self::escapeLike($value).'%', false);
        [$contains, $infix] = self::like($query, $column, '%'.self::escapeLike($value).'%', false);

        return ["case when {$startsWith} then 1 when {$contains} then 0.5 else 0 end", [...$prefix, ...$infix]];
    }

    public static function suggest(Builder $query, string $column, string $value, bool $unaccent): Builder
    {
        $value = trim($value);

        if ($value === '') {
            return $query->whereRaw('1 = 0');
        }

        $connection = self::connection($query);
        $wrapped = $query->getGrammar()->wrap($column);
        [$startsWith, $prefix] = self::like($query, $column, self::escapeLike($value).'%', $unaccent);
        $length = Family::of($connection) === Family::MYSQL ? 'char_length' : 'length';

        if (mb_strlen($value) < 3) {
            $query->whereRaw($startsWith, $prefix);
        } else {
            $trigrams = self::hasTrigrams($query);

            $query->where(function (Builder $query) use ($column, $value, $unaccent, $trigrams) {
                self::whereLike($query, $column, '%'.self::escapeLike($value).'%', $unaccent, 'and');

                if ($trigrams) {
                    [$expression, $placeholder] = self::operands($query, $column, $unaccent);
                    $query->orWhereRaw("{$expression} % {$placeholder}", [$value]);
                }
            });

            // An integer key: MatrixOne ignores a DESC key after a boolean one.
            $query->orderByRaw("case when {$startsWith} then 0 else 1 end", $prefix);

            if ($trigrams) {
                [$expression, $placeholder] = self::operands($query, $column, $unaccent);
                $query->orderByRaw("similarity({$expression}, {$placeholder}) desc", [$value]);
            }
        }

        return $query->orderByRaw("{$length}({$wrapped})")->orderBy($column);
    }

    /**
     * Full-text relevance, the expression whereFullText() matches: ts_rank
     * on PostgreSQL, MATCH ... AGAINST on MySQL/MariaDB; '0' (no ordering)
     * on SQLite.
     *
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $options
     */
    public static function relevance(Builder $query, array $columns, array $options): string
    {
        $grammar = $query->getGrammar();
        $mode = $options['mode'] ?? null;

        switch (Family::of(self::connection($query))) {
            case Family::POSTGRES:
                $language = is_string($options['language'] ?? null) ? $options['language'] : 'english';
                $language = str_replace("'", '', $language);
                $document = implode(' || ', array_map(
                    fn (string $column) => ($options['vector'] ?? false) ? $grammar->wrap($column) : "to_tsvector('{$language}', {$grammar->wrap($column)})",
                    $columns,
                ));
                $function = match ($mode) {
                    'phrase' => 'phraseto_tsquery',
                    'websearch' => 'websearch_to_tsquery',
                    'raw' => 'to_tsquery',
                    default => 'plainto_tsquery',
                };

                return "ts_rank(({$document}), {$function}('{$language}', ?))";

            case Family::MYSQL:
                return sprintf('match (%s) against (? in %s mode)', $grammar->columnize($columns), $mode === 'boolean' ? 'boolean' : 'natural language');

            default:
                self::skip($query, 'Full-text relevance', 'no relevance score, 0 is used');

                return '0';
        }
    }

    public static function direction(string $direction): string
    {
        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Order direction must be "asc" or "desc".');
        }

        return $direction;
    }

    public static function skip(Builder $query, string $method, string $reason): void
    {
        Unsupported::skip("{$method} on ".self::connection($query)->getDriverName().": {$reason}.");
    }

    private static function connection(Builder $query): Connection
    {
        /** @var Connection $connection */
        $connection = $query->getConnection();

        return $connection;
    }
}
