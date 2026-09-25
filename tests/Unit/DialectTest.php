<?php

namespace DbPortable\Tests\Unit;

use DbPortable\Dialects\Dialect;
use DbPortable\Dialects\MySqlDialect;
use DbPortable\Dialects\PostgresDialect;
use DbPortable\Dialects\SQLiteDialect;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\MariaDbGrammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Query\Grammars\SqlServerGrammar;
use InvalidArgumentException;
use MatrixOne\Query\Grammar as MatrixOneGrammar;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use YlsIdeas\CockroachDb\Query\CockroachGrammar;

class DialectTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    /**
     * @param  class-string<Grammar>  $grammar
     */
    private function dialect(string $grammar): Dialect
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getTablePrefix')->andReturn('');

        return Dialect::for(new $grammar($connection));
    }

    public function test_resolution(): void
    {
        $this->assertInstanceOf(PostgresDialect::class, $this->dialect(PostgresGrammar::class));
        $this->assertInstanceOf(PostgresDialect::class, $this->dialect(CockroachGrammar::class));
        $this->assertInstanceOf(MySqlDialect::class, $this->dialect(MySqlGrammar::class));
        $this->assertInstanceOf(MySqlDialect::class, $this->dialect(MariaDbGrammar::class));
        $this->assertInstanceOf(MySqlDialect::class, $this->dialect(MatrixOneGrammar::class));
        $this->assertInstanceOf(SQLiteDialect::class, $this->dialect(SQLiteGrammar::class));

        $this->expectException(RuntimeException::class);
        $this->dialect(SqlServerGrammar::class);
    }

    public function test_postgres_and_cockroachdb(): void
    {
        $dialect = $this->dialect(CockroachGrammar::class);

        $this->assertSame('("plan_data"->>\'amount\')::numeric', $dialect->jsonNumber('plan_data->amount'));
        $this->assertSame('("flags"->>\'sync\')::boolean', $dialect->jsonBool('flags->sync'));
        $this->assertSame('x desc nulls last', $dialect->orderNullsLast('x', 'desc'));
        $this->assertSame('"tags"::text', $dialect->castText('tags'));
        $this->assertSame(
            ['meta', 'jsonb_set(coalesce("meta"::jsonb, \'{}\'::jsonb), \'{"a","b"}\', to_jsonb(coalesce(("meta"->\'a\'->>\'b\')::numeric, 0) + 2), true)'],
            $dialect->jsonIncrement('meta->a->b', 2)
        );
    }

    public function test_mysql_mariadb_and_matrixone(): void
    {
        $mysql = $this->dialect(MySqlGrammar::class);

        $this->assertSame('cast(json_unquote(json_extract(`plan_data`, \'$."amount"\')) as double)', $mysql->jsonNumber('plan_data->amount'));
        $this->assertSame('(json_unquote(json_extract(`flags`, \'$."sync"\')) = \'true\')', $mysql->jsonBool('flags->sync'));
        $this->assertSame('x desc', $mysql->orderNullsLast('x', 'desc'));
        $this->assertSame('(x) is null, x asc', $mysql->orderNullsLast('x', 'asc'));
        $this->assertSame('cast(`tags` as char)', $mysql->castText('tags'));
        $this->assertSame('cast(`tags` as text)', $this->dialect(MatrixOneGrammar::class)->castText('tags'));
        $this->assertSame(
            ['meta', 'json_set(coalesce(`meta`, json_object()), \'$."views"\', coalesce(cast(json_unquote(json_extract(`meta`, \'$."views"\')) as signed), 0) + 1)'],
            $mysql->jsonIncrement('meta->views', 1)
        );
        $this->assertStringContainsString('as double)', $mysql->jsonIncrement('meta->ratio', 0.5)[1]);
        $this->assertStringEndsWith('+ 0.5)', $mysql->jsonIncrement('meta->ratio', 0.5)[1]);
    }

    public function test_sqlite(): void
    {
        $sqlite = $this->dialect(SQLiteGrammar::class);

        $this->assertSame('cast(json_extract("meta", \'$."amount"\') as real)', $sqlite->jsonNumber('meta->amount'));
        $this->assertSame('(json_extract("meta", \'$."sync"\') = 1)', $sqlite->jsonBool('meta->sync'));
        $this->assertSame('length("name")', $sqlite->charLength('name'));
    }

    public function test_paths_and_directions_are_validated(): void
    {
        $dialect = $this->dialect(MySqlGrammar::class);

        try {
            $dialect->jsonNumber('amount');
            $this->fail('A column without a JSON path must be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        $dialect->orderNullsLast('x', 'sideways');
    }

    public function test_keys_are_escaped_in_json_paths(): void
    {
        [, $sql] = $this->dialect(MySqlGrammar::class)->jsonIncrement("meta->it's", 1);

        $this->assertStringContainsString('\'$."it\'\'s"\'', $sql);
    }
}
