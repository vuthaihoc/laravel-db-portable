<?php

namespace DbPortable\Tests\Unit;

use DbPortable\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;

/**
 * The SQL of the schema macros on grammars without a test server.
 */
class SchemaMacrosSqlTest extends TestCase
{
    /**
     * @param  class-string<Connection>  $class
     * @return list<string>
     */
    private function sql(string $class, callable $callback): array
    {
        $connection = new $class(fn () => null, 'app', '', ['driver' => 'x']);
        $connection->useDefaultSchemaGrammar();

        $blueprint = new Blueprint($connection, 'items');
        $blueprint->create();
        $callback($blueprint);

        return $blueprint->toSql();
    }

    public function test_mysql_and_mariadb_json_defaults_are_expressions(): void
    {
        foreach ([MySqlConnection::class, MariaDbConnection::class] as $class) {
            $sql = $this->sql($class, fn (Blueprint $table) => $table->jsonWithDefault('tags', ["it's"]));

            $this->assertStringContainsString("`tags` json not null default ('[\"it''s\"]')", $sql[0], $class);
        }
    }

    public function test_postgres_json_default_and_gin_index(): void
    {
        $sql = $this->sql(PostgresConnection::class, function (Blueprint $table) {
            $table->jsonWithDefault('tags', [], binary: true);
            $table->jsonIndex('tags');
        });

        $this->assertStringContainsString('"tags" jsonb not null default \'[]\'', $sql[0]);
        $this->assertSame('create index "items_tags_index" on "items" using gin ("tags")', $sql[1]);
    }

    public function test_desc_index_sql(): void
    {
        $sql = $this->sql(MySqlConnection::class, fn (Blueprint $table) => $table->descIndex(['score', 'id' => 'asc']));

        $this->assertStringContainsString('`items_score_id_index_desc`', implode(';', $sql));
        $this->assertStringContainsString('(`score` desc, `id` asc)', implode(';', $sql));
    }

    public function test_desc_index_rejects_invalid_directions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->sql(MySqlConnection::class, fn (Blueprint $table) => $table->descIndex(['score' => 'sideways']));
    }
}
