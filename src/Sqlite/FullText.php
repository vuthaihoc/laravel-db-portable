<?php

namespace DbPortable\Sqlite;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar as LaravelQueryGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar as LaravelSchemaGrammar;
use Illuminate\Database\SQLiteConnection;
use InvalidArgumentException;

/**
 * Full-text search on SQLite with FTS5, behind Laravel's own API: $table->fullText()
 * creates an FTS5 table that triggers keep up to date, and whereFullText() matches
 * it. Installed on each SQLite connection by replacing Laravel's SQLite grammars
 * with subclasses: the driver and the connection stay Laravel's.
 */
final class FullText
{
    /** Words, case and accents ignored ("pho co" finds "Phố cổ"). */
    public const TOKENIZER = 'unicode61 remove_diacritics 2';

    /**
     * Letters with a stroke, which unicode61 keeps as they are: indexed and searched as
     * their base letter ("da nang" finds "Đà Nẵng").
     */
    public const FOLD = ['đ' => 'd', 'Đ' => 'D', 'ł' => 'l', 'Ł' => 'L', 'ø' => 'o', 'Ø' => 'O'];

    public static function install(Connection $connection): void
    {
        if (! $connection instanceof SQLiteConnection) {
            return;
        }

        // Only Laravel's own grammars: an application's custom grammar is left alone.
        if ($connection->getQueryGrammar()::class === LaravelQueryGrammar::class) {
            $connection->setQueryGrammar(new QueryGrammar($connection));
        }

        /** @var LaravelSchemaGrammar|null $schema null until the schema builder is first used */
        $schema = $connection->getSchemaGrammar();

        if ($schema === null || $schema::class === LaravelSchemaGrammar::class) {
            $connection->setSchemaGrammar(new SchemaGrammar($connection));
        }
    }

    public static function installed(Connection $connection): bool
    {
        return $connection->getQueryGrammar() instanceof QueryGrammar;
    }

    /**
     * An SQL expression folding the letters of FOLD, for the values written to an FTS5 table.
     */
    public static function foldSql(string $expression): string
    {
        foreach (self::FOLD as $letter => $base) {
            $expression = "replace({$expression}, '{$letter}', '{$base}')";
        }

        return $expression;
    }

    /**
     * Index a table's rows again, in each of its FTS5 tables: after a VACUUM of a table
     * without an integer primary key (its rowids may change). FTS5's own 'rebuild' would
     * index the letters of FOLD unfolded.
     */
    public static function rebuild(Connection $connection, string $table): void
    {
        $grammar = $connection->getQueryGrammar();

        foreach (self::tables($connection, $table) as $name => $columns) {
            $fts = $grammar->wrapTable($name, '');
            $list = implode(', ', array_map(fn (string $column) => $grammar->wrap($column), $columns));
            $values = implode(', ', array_map(fn (string $column) => self::foldSql($grammar->wrap($column)), $columns));

            $connection->statement("insert into {$fts}({$fts}) values ('delete-all')");
            $connection->statement("insert into {$fts}(rowid, {$list}) select rowid, {$values} from {$grammar->wrapTable($table)}");
        }
    }

    /**
     * The FTS5 tokenizer of an index: $table->fullText()->language('english') stems English
     * words, 'trigram' matches any part of a word, another value is an FTS5 tokenizer.
     */
    public static function tokenizer(?string $language): string
    {
        return match ($language === null ? null : strtolower($language)) {
            null, '', 'simple' => self::TOKENIZER,
            'english', 'porter' => 'porter '.self::TOKENIZER,
            'trigram' => 'trigram',
            default => (string) $language,
        };
    }

    /**
     * The table a query reads, and the name its columns are qualified with.
     *
     * @return array{0: string, 1: string}
     */
    public static function source(Builder $query): array
    {
        if (! is_string($query->from)) {
            throw new InvalidArgumentException('Full-text search on SQLite needs a table in from(), not a subquery.');
        }

        $parts = preg_split('/\s+as\s+/i', trim($query->from)) ?: [];

        return [$parts[0], $parts[1] ?? $parts[0]];
    }

    /**
     * The FTS5 tables indexing a table: their columns, by name.
     *
     * @return array<string, list<string>>
     */
    public static function tables(Connection $connection, string $table): array
    {
        $content = $connection->getTablePrefix().$table;
        $tables = [];

        foreach ($connection->select("select name, sql from sqlite_master where type = 'table' and sql like 'create virtual table%'") as $row) {
            $definition = self::definition((string) $row->sql);

            if ($definition !== null && strcasecmp((string) $definition['content'], $content) === 0) {
                $tables[(string) $row->name] = $definition['columns'];
            }
        }

        return $tables;
    }

    /**
     * The FTS5 table indexing these columns of a table (the one indexing the fewest others),
     * and its columns.
     *
     * @param  list<string>  $columns
     * @return array{0: string, 1: list<string>}|null
     */
    public static function tableFor(Connection $connection, string $table, array $columns): ?array
    {
        $wanted = array_map('strtolower', $columns);
        $best = null;

        foreach (self::tables($connection, $table) as $name => $indexed) {
            if (array_diff($wanted, array_map('strtolower', $indexed)) === [] && ($best === null || count($indexed) < count($best[1]))) {
                $best = [$name, $indexed];
            }
        }

        return $best;
    }

    /**
     * The FTS5 match of a whereFullText(): the query, restricted to the searched columns
     * when the FTS5 table indexes others too.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $indexed
     */
    public static function match(string $query, array $columns, array $indexed): string
    {
        if (array_diff(array_map('strtolower', $indexed), array_map('strtolower', $columns)) === []) {
            return $query;
        }

        $filter = implode(' ', array_map(fn (string $column) => '"'.str_replace('"', '""', $column).'"', $columns));

        return "{{$filter}} : ({$query})";
    }

    /**
     * An FTS5 query for the text of whereFullText(), or null when the text has nothing
     * to match:
     *
     * - by default, every word (as PostgreSQL's whereFullText());
     * - mode "phrase": the text as one phrase;
     * - mode "websearch": every word or "quoted phrase", "a OR b", -excluded, prefix*;
     * - mode "boolean" (MySQL's): +required, -excluded, the other words when nothing is
     *   required, "quoted phrases", prefix*;
     * - mode "raw": FTS5 query syntax as it is.
     */
    public static function query(string $text, ?string $mode = null): ?string
    {
        $text = strtr(trim($text), self::FOLD);

        if ($mode === 'raw') {
            return $text === '' ? null : $text;
        }

        if ($mode === 'phrase') {
            return self::searchable($text) ? self::phrase($text) : null;
        }

        if ($mode === 'websearch' || $mode === 'boolean') {
            return self::operators($text, $mode === 'boolean');
        }

        $words = array_filter(preg_split('/\s+/u', $text) ?: [], fn (string $word) => self::searchable($word));

        return $words === [] ? null : implode(' ', array_map(fn (string $word) => self::phrase($word), $words));
    }

    /**
     * The columns and content table of an FTS5 table's "create virtual table" statement.
     *
     * @return array{columns: list<string>, content: string|null}|null
     */
    public static function definition(string $sql): ?array
    {
        if (! preg_match('/using\s+fts5\s*\((.*)\)\s*$/is', $sql, $matches)) {
            return null;
        }

        $columns = [];
        $content = null;

        foreach (self::arguments($matches[1]) as $argument) {
            if (preg_match('/^([a-z_]+)\s*=\s*(.*)$/is', $argument, $option)) {
                $content = strtolower($option[1]) === 'content' ? self::unquote(trim($option[2])) : $content;

                continue;
            }

            $columns[] = self::unquote((preg_split('/\s+/', $argument) ?: [''])[0]);   // "title" UNINDEXED
        }

        return ['columns' => $columns, 'content' => $content];
    }

    private static function operators(string $text, bool $boolean): ?string
    {
        preg_match_all('/([+-]?)"([^"]*)"\*?|(\S+)/u', $text, $tokens, PREG_SET_ORDER);
        [$required, $optional, $excluded, $groups, $or] = [[], [], [], [], false];

        foreach ($tokens as $token) {
            if (($token[3] ?? '') !== '' && strtoupper($token[3]) === 'OR') {
                $or = true;

                continue;
            }

            [$sign, $term] = ($token[3] ?? '') !== ''
                ? [in_array($token[3][0], ['+', '-'], true) ? $token[3][0] : '', ltrim($token[3], '+-')]
                : [$token[1] ?? '', $token[2] ?? ''];
            $prefix = str_ends_with($term, '*') || str_ends_with($token[0], '*');
            $term = rtrim($term, '*');

            if (! self::searchable($term)) {
                continue;
            }

            $phrase = self::phrase($term).($prefix ? '*' : '');

            if ($sign === '-') {
                $excluded[] = $phrase;
            } elseif ($boolean && $sign === '+') {
                $required[] = $phrase;
            } elseif ($boolean) {
                $optional[] = $phrase;
            } elseif ($or && $groups !== []) {
                $groups[count($groups) - 1][] = $phrase;   // "a OR b" binds its neighbours, as in PostgreSQL
            } else {
                $groups[] = [$phrase];
            }

            $or = false;
        }

        $terms = $boolean
            ? ($required !== [] ? $required : ($optional === [] ? [] : ['('.implode(' OR ', $optional).')']))
            : array_map(fn (array $group) => count($group) > 1 ? '('.implode(' OR ', $group).')' : $group[0], $groups);

        if ($terms === []) {
            return null;   // FTS5 has no query without a positive term
        }

        return implode(' AND ', $terms).implode('', array_map(fn (string $phrase) => " NOT {$phrase}", $excluded));
    }

    private static function searchable(string $text): bool
    {
        return (bool) preg_match('/[\p{L}\p{N}]/u', $text);
    }

    private static function phrase(string $text): string
    {
        return '"'.str_replace('"', '""', $text).'"';
    }

    /**
     * The arguments of fts5(...), split on the commas outside quotes.
     *
     * @return list<string>
     */
    private static function arguments(string $list): array
    {
        $arguments = [];
        $current = '';
        $quote = null;

        foreach (mb_str_split($list) as $char) {
            if ($quote !== null) {
                $current .= $char;
                $quote = $char === $quote ? null : $quote;   // a doubled quote closes and reopens
            } elseif (in_array($char, ['"', "'", '`', '['], true)) {
                $current .= $char;
                $quote = $char === '[' ? ']' : $char;
            } elseif ($char === ',') {
                $arguments[] = trim($current);
                $current = '';
            } else {
                $current .= $char;
            }
        }

        return trim($current) === '' ? $arguments : [...$arguments, trim($current)];
    }

    private static function unquote(string $value): string
    {
        $first = $value[0] ?? '';

        if (in_array($first, ['"', "'", '`'], true) && strlen($value) > 1 && str_ends_with($value, $first)) {
            return str_replace($first.$first, $first, substr($value, 1, -1));
        }

        return $first === '[' && str_ends_with($value, ']') ? substr($value, 1, -1) : $value;
    }
}
