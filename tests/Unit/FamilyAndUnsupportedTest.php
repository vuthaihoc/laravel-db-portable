<?php

namespace DbPortable\Tests\Unit;

use DbPortable\Schema\Family;
use DbPortable\Schema\Unsupported;
use DbPortable\Tests\TestCase;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\Log;

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
