<?php

namespace DbPortable\Tests\Unit;

use DbPortable\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;

/**
 * The SQL of the search macros on databases without a driver implementation.
 */
class SearchMacrosSqlTest extends TestCase
{
    /**
     * @param  class-string<Connection>  $class
     */
    private function query(string $class, string $driver): Builder
    {
        $connection = new $class(fn () => null, 'app', '', ['driver' => $driver]);

        return $connection->query()->from('words');
    }

    public function test_postgres_uses_trigrams_and_ts_rank(): void
    {
        $query = $this->query(PostgresConnection::class, 'pgsql')->suggest('word', 'a_pl', unaccent: true);

        $this->assertSame(
            'select * from "words" where (unaccent(lower("word")) like unaccent(lower(?)) or unaccent(lower("word")) % unaccent(lower(?))) order by case when unaccent(lower("word")) like unaccent(lower(?)) then 0 else 1 end, similarity(unaccent(lower("word")), unaccent(lower(?))) desc, length("word"), "word" asc',
            $query->toSql()
        );
        $this->assertSame(['%a\_pl%', 'a_pl', 'a\_pl%', 'a_pl'], $query->getBindings());

        $this->assertSame(
            'select * from "words" where ("title") @@ websearch_to_tsquery(\'simple\', ?) order by ts_rank(("title"), websearch_to_tsquery(\'simple\', ?)) desc',
            $this->query(PostgresConnection::class, 'pgsql')->searchFullText('title', 'x', ['mode' => 'websearch', 'language' => 'simple', 'vector' => true])->toSql()
        );
    }

    public function test_mysql_falls_back_to_like_and_match(): void
    {
        $query = $this->query(MySqlConnection::class, 'mysql')
            ->whereSimilar('word', 'aple')
            ->orderByFullTextRelevance(['title', 'body'], 'x', ['mode' => 'boolean']);

        $this->assertSame(
            'select * from `words` where `word` like ? order by match (`title`, `body`) against (? in boolean mode) desc',
            $query->toSql()
        );
        $this->assertSame(['%aple%', 'x'], $query->getBindings());
    }
}
