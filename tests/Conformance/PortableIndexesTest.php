<?php

namespace DbPortable\Tests\Conformance;

use DbPortable\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * jsonKeyIndex, coveringIndex, trigramIndex and partialIndex as real migrations.
 */
class PortableIndexesTest extends TestCase
{
    private string $connection;

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            Schema::connection($this->connection)->dropIfExists('portable_indexes');
        }

        parent::tearDown();
    }

    #[DataProvider('connections')]
    public function test_portable_indexes(string $connection): void
    {
        $this->requireConnection($connection);
        $this->connection = $connection;
        Log::spy();

        $schema = Schema::connection($connection);
        $schema->dropIfExists('portable_indexes');
        $schema->create('portable_indexes', function (Blueprint $table) {
            $table->id();
            $table->integer('video_id');
            $table->string('title');
            $table->string('word', 100);
            $table->json('meta')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->jsonKeyIndex('meta->source');
            $table->coveringIndex('video_id', ['title']);
            $table->trigramIndex('word');
            $table->partialIndex('title', 'deleted_at is null');
        });

        $indexes = collect($schema->getIndexes('portable_indexes'))->pluck('columns', 'name');

        $expected = [
            // name => created on crdb, matrixone, sqlite
            'portable_indexes_meta_source_index' => ['crdb' => true, 'matrixone' => false, 'sqlite' => true],
            'portable_indexes_video_id_index' => ['crdb' => true, 'matrixone' => true, 'sqlite' => true],
            'portable_indexes_word_index_trigram' => ['crdb' => true, 'matrixone' => true, 'sqlite' => false],
            'portable_indexes_title_index_partial' => ['crdb' => true, 'matrixone' => true, 'sqlite' => true],
        ];

        foreach ($expected as $name => $created) {
            $this->assertSame($created[$connection], $indexes->has($name), "{$name} on {$connection}");
        }

        if ($connection !== 'crdb') {
            Log::shouldHaveReceived('warning')->atLeast()->once();
        }

        // The indexes do not get in the way of writes and reads.
        DB::connection($connection)->table('portable_indexes')->insert([
            ['video_id' => 1, 'title' => 'Hello', 'word' => 'hello world', 'meta' => json_encode(['source' => 'yt']), 'deleted_at' => null],
            ['video_id' => 1, 'title' => 'Gone', 'word' => 'goodbye', 'meta' => null, 'deleted_at' => now()],
        ]);

        $this->assertSame(1, DB::connection($connection)->table('portable_indexes')->where('meta->source', 'yt')->count());
        $this->assertSame(['Hello'], DB::connection($connection)->table('portable_indexes')->whereNull('deleted_at')->pluck('title')->all());
    }
}
