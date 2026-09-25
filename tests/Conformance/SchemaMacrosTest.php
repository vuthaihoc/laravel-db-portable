<?php

namespace DbPortable\Tests\Conformance;

use DbPortable\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Blueprint and schema builder macros, run as real migrations on every database.
 */
class SchemaMacrosTest extends TestCase
{
    private string $connection;

    private function useConnection(string $connection): void
    {
        $this->requireConnection($connection);
        $this->connection = $connection;
        Schema::connection($connection)->dropIfExists('portable_schema');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            Schema::connection($this->connection)->dropIfExists('portable_schema');
        }

        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function indexNames(): array
    {
        return array_map(fn ($index) => $index['name'], Schema::connection($this->connection)->getIndexes('portable_schema'));
    }

    #[DataProvider('connections')]
    public function test_json_index_and_default(string $connection): void
    {
        $this->useConnection($connection);
        $postgres = $connection === 'crdb';

        if (! $postgres) {
            Log::shouldReceive('warning')->atLeast()->once();
        }

        Schema::connection($connection)->create('portable_schema', function (Blueprint $table) {
            $table->id();
            $table->jsonWithDefault('tags', []);
            $table->jsonWithDefault('settings', ['theme' => 'dark'], binary: true);
            $table->json('meta')->nullable();
            $table->jsonIndex('meta');
        });

        $this->assertSame($postgres, in_array('portable_schema_meta_index', $this->indexNames(), true));

        DB::connection($connection)->table('portable_schema')->insert(['meta' => null]);
        $row = DB::connection($connection)->table('portable_schema')->first();

        if ($connection === 'matrixone') {
            // JSON columns take no default on MatrixOne: set it in the model.
            $this->assertNull($row?->tags);
        } else {
            $this->assertSame([], json_decode((string) $row?->tags, true));
            $this->assertSame(['theme' => 'dark'], json_decode((string) $row?->settings, true));
        }
    }

    #[DataProvider('connections')]
    public function test_strict_mode_throws_instead_of_skipping(string $connection): void
    {
        $this->useConnection($connection);
        config(['db-portable.strict' => true]);

        if ($connection !== 'crdb') {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('jsonIndex');
        }

        Schema::connection($connection)->create('portable_schema', function (Blueprint $table) {
            $table->id();
            $table->json('meta')->nullable();
            $table->jsonIndex('meta');
        });

        $this->assertContains('portable_schema_meta_index', $this->indexNames());
    }

    #[DataProvider('connections')]
    public function test_desc_index(string $connection): void
    {
        $this->useConnection($connection);

        Schema::connection($connection)->create('portable_schema', function (Blueprint $table) {
            $table->id();
            $table->integer('score')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->descIndex('updated_at');
            $table->descIndex(['score' => 'desc', 'id' => 'asc'], 'portable_schema_ranking');
        });

        $this->assertContains('portable_schema_updated_at_index_desc', $this->indexNames());
        $this->assertContains('portable_schema_ranking', $this->indexNames());

        DB::connection($connection)->table('portable_schema')->insert([['score' => 1], ['score' => 3], ['score' => 2]]);
        $this->assertSame([3, 2, 1], DB::connection($connection)->table('portable_schema')->orderByDesc('score')->pluck('score')->map(fn ($s) => (int) $s)->all());
    }

    #[DataProvider('connections')]
    public function test_for_driver(string $connection): void
    {
        $this->useConnection($connection);

        Schema::connection($connection)->create('portable_schema', function (Blueprint $table) {
            $table->id();
            $table->forDriver([
                'crdb' => fn (Blueprint $table) => $table->string('from_driver')->nullable(),
                'pgsql' => fn (Blueprint $table) => $table->string('from_family')->nullable(),
                'mysql,sqlite' => fn (Blueprint $table) => $table->string('from_list')->nullable(),
                'default' => fn (Blueprint $table) => $table->string('from_default')->nullable(),
            ]);
        });

        $expected = ['crdb' => 'from_driver', 'matrixone' => 'from_list', 'sqlite' => 'from_list'][$connection];
        $this->assertSame(['id', $expected], Schema::connection($connection)->getColumnListing('portable_schema'));

        $result = Schema::connection($connection)->forDriver([
            'matrixone' => fn () => 'matrixone',
            'default' => fn () => 'other',
        ]);
        $this->assertSame($connection === 'matrixone' ? 'matrixone' : 'other', $result);
        $this->assertNull(Schema::connection($connection)->forDriver(['oracle' => fn () => 'never']));
    }
}
