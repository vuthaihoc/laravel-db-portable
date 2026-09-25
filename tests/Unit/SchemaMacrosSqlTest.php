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

    public function test_portable_indexes_on_postgres(): void
    {
        $sql = $this->sql(PostgresConnection::class, function (Blueprint $table) {
            $table->jsonKeyIndex('meta->source');
            $table->coveringIndex('video_id', ['title', 'slug']);
            $table->trigramIndex('word');
            $table->partialIndex(['email'], 'deleted_at is null');
        });

        $this->assertContains('create index "items_meta_source_index" on "items" (("meta"->>\'source\'))', $sql);
        $this->assertContains('create index "items_video_id_index" on "items" ("video_id") include ("title", "slug")', $sql);
        $this->assertContains('create index "items_word_index_trigram" on "items" using gin ("word" gin_trgm_ops)', $sql);
        $this->assertContains('create index "items_email_index_partial" on "items" ("email") where deleted_at is null', $sql);
    }

    public function test_portable_indexes_on_mysql(): void
    {
        $sql = implode(';', $this->sql(MySqlConnection::class, function (Blueprint $table) {
            $table->jsonKeyIndex('meta->source');
            $table->coveringIndex('video_id', ['title']);
            $table->trigramIndex('word');
            $table->partialIndex('email', 'deleted_at is null');
        }));

        $this->assertStringContainsString("alter table `items` add index `items_meta_source_index` ((cast(json_unquote(json_extract(`meta`, '$.\"source\"')) as char(255)) collate utf8mb4_bin))", $sql);
        $this->assertStringContainsString('alter table `items` add index `items_video_id_index`(`video_id`)', $sql);
        $this->assertStringContainsString('alter table `items` add fulltext `items_word_index_trigram` (`word`) with parser ngram', $sql);
        $this->assertStringContainsString('alter table `items` add index `items_email_index_partial`(`email`)', $sql);
        $this->assertStringNotContainsString('where', $sql);
    }

    public function test_mariadb_skips_json_key_indexes(): void
    {
        $sql = implode(';', $this->sql(MariaDbConnection::class, function (Blueprint $table) {
            $table->jsonKeyIndex('meta->source');
            $table->trigramIndex('word');
        }));

        $this->assertStringNotContainsString('meta', $sql);
        $this->assertStringContainsString('add fulltext `items_word_index_trigram` (`word`)', $sql);
        $this->assertStringNotContainsString('ngram', $sql);
    }
}
