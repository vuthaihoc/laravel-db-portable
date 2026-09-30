<?php

namespace DbPortable\Sqlite;

use DbPortable\Query\SearchMacros;
use DbPortable\Schema\Unsupported;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;

/**
 * Laravel's SQLite query grammar, with whereFullText() on the FTS5 tables of
 * $table->fullText() (see FullText).
 */
class QueryGrammar extends SQLiteGrammar
{
    /**
     * The rows whose FTS5 table matches the text. The FTS5 query is written into the SQL:
     * the value's binding is only used by "? is not null".
     *
     * @param  array<string, mixed>  $where
     */
    public function whereFullText(Builder $query, $where)
    {
        [$table, $reference] = FullText::source($query);
        $columns = [];

        foreach ((array) $where['columns'] as $column) {
            $segments = explode('.', (string) $column);
            $columns[] = (string) array_pop($segments);

            // A column of a joined table searches that table.
            if ($segments !== [] && ! in_array(end($segments), [$table, $reference], true)) {
                $table = $reference = (string) end($segments);
            }
        }

        /** @var array<string, mixed> $options */
        $options = is_array($where['options'] ?? null) ? $where['options'] : [];
        $mode = is_string($options['mode'] ?? null) ? $options['mode'] : null;
        $text = FullText::query((string) $where['value'], $mode);

        if ($text === null) {
            return '(0 = 1 and ? is not null)';
        }

        $fts = FullText::tableFor($this->connection, $table, $columns);

        if ($fts === null) {
            Unsupported::skip("whereFullText() on sqlite: no FTS5 index on {$table} (".implode(', ', $columns).'): add $table->fullText() to a migration; a LIKE per word is used instead.');

            return $this->likeEveryWord($reference, $columns, (string) $where['value']);
        }

        [$name, $indexed] = $fts;
        $wrapped = $this->wrapValue($name);

        return $this->wrap("{$reference}.rowid")." in (select rowid from {$wrapped} where {$wrapped} match "
            .$this->literal(FullText::match($text, $columns, $indexed)).' and ? is not null)';
    }

    /**
     * Every word in one of the columns, ignoring ASCII case.
     *
     * @param  list<string>  $columns
     */
    protected function likeEveryWord(string $reference, array $columns, string $value): string
    {
        $words = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $clauses = array_map(function (string $word) use ($reference, $columns) {
            $pattern = $this->literal('%'.SearchMacros::escapeLike($word).'%');

            return '('.implode(' or ', array_map(fn (string $column) => $this->wrap("{$reference}.{$column}")." like {$pattern} escape '\\'", $columns)).')';
        }, $words);

        return '('.($clauses === [] ? '0 = 1' : implode(' and ', $clauses)).' and ? is not null)';
    }

    protected function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
