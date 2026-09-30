<?php

namespace DbPortable\Tests\Unit;

use DbPortable\Schema\Family;
use DbPortable\Schema\Unsupported;
use DbPortable\Tests\TestCase;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\Log;
use LaravelXtdb\XtdbConnection;
use MatrixOne\MatrixOneConnection;
use YlsIdeas\CockroachDb\CockroachDbConnection;

class FamilyAndUnsupportedTest extends TestCase
{
    public function test_family_keeps_the_schema_grammar_of_the_connection(): void
    {
        $connection = new PostgresConnection(fn () => null, 'app', '', ['driver' => 'pgsql']);
        $grammar = new class($connection) extends PostgresGrammar {};
        $connection->setSchemaGrammar($grammar);

        $this->assertSame(Family::POSTGRES, Family::of($connection));
        $this->assertSame($grammar, $connection->getSchemaGrammar());
    }

    public function test_family_fills_in_a_missing_schema_grammar(): void
    {
        $connection = new PostgresConnection(fn () => null, 'app', '', ['driver' => 'pgsql']);

        $this->assertSame(Family::POSTGRES, Family::of($connection));
        $this->assertInstanceOf(PostgresGrammar::class, $connection->getSchemaGrammar());
    }

    public function test_the_drivers_are_recognized_by_their_connection_class(): void
    {
        // Registered under other names than "crdb" and "matrixone".
        $crdb = new CockroachDbConnection(fn () => null, 'app', '', ['driver' => 'cockroach']);
        $matrixOne = new MatrixOneConnection(fn () => null, 'app', '', ['driver' => 'mo']);
        $postgres = new PostgresConnection(fn () => null, 'app', '', ['driver' => 'pgsql']);
        $mysql = new MySqlConnection(fn () => null, 'app', '', ['driver' => 'mysql']);

        $this->assertSame([Family::CRDB, Family::MATRIXONE, 'pgsql', 'mysql'], array_map(Family::driver(...), [$crdb, $matrixOne, $postgres, $mysql]));
        $this->assertTrue(Family::isCockroachDb($crdb));
        $this->assertFalse(Family::isCockroachDb($postgres));
        $this->assertTrue(Family::isMatrixOne($matrixOne));
        $this->assertFalse(Family::isMatrixOne($mysql));
        $this->assertTrue(Family::isMatrixOneGrammar($matrixOne->getQueryGrammar()));
        $this->assertFalse(Family::isMatrixOneGrammar($mysql->getQueryGrammar()));

        $callbacks = ['crdb' => 'driver', 'matrixone' => 'matrixone', 'pgsql' => 'family', 'default' => 'default'];
        $this->assertSame(['driver', 'matrixone', 'family', 'default'], array_map(fn ($connection) => Family::pick($connection, $callbacks), [$crdb, $matrixOne, $postgres, $mysql]));

        // CockroachDB has no ROLLUP: the union of grouping levels, as for "crdb".
        $this->assertStringNotContainsString('rollup', $crdb->table('orders')->select('region')->selectRaw('count(*) as n')->groupBy('region')->rollup()->toSql());
        $this->assertStringContainsString('rollup ("region")', $postgres->table('orders')->select('region')->selectRaw('count(*) as n')->groupBy('region')->rollup()->toSql());
    }

    public function test_xtdb_is_recognized_by_its_connection_class(): void
    {
        $xtdb = new XtdbConnection(fn () => null, 'xtdb', '', ['driver' => 'xtdb']);

        $this->assertSame(Family::XTDB, Family::driver($xtdb));
        $this->assertTrue(Family::isXtdb($xtdb));
        $this->assertSame(Family::POSTGRES, Family::of($xtdb));
        $this->assertFalse(Family::isXtdb(new PostgresConnection(fn () => null, 'app', '', ['driver' => 'pgsql'])));
        $this->assertSame('xtdb', Family::pick($xtdb, ['xtdb' => 'xtdb', 'pgsql' => 'pgsql']));
    }

    public function test_a_warning_is_logged_once_per_message(): void
    {
        Log::spy();

        Unsupported::skip('first fallback.');
        Unsupported::skip('first fallback.');
        Unsupported::skip('second fallback.');

        Log::shouldHaveReceived('warning')->with('[db-portable] first fallback. Skipped.')->once();
        Log::shouldHaveReceived('warning')->with('[db-portable] second fallback. Skipped.')->once();
    }

    public function test_strict_mode_throws_every_time(): void
    {
        config()->set('db-portable.strict', true);

        foreach ([1, 2] as $call) {
            try {
                Unsupported::skip('strict fallback.');
                $this->fail("Call {$call} did not throw.");
            } catch (\RuntimeException $e) {
                $this->assertSame('[db-portable] strict fallback.', $e->getMessage());
            }
        }
    }
}
