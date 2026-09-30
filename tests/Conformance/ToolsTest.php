<?php

namespace DbPortable\Tests\Conformance;

use DbPortable\Audit\Auditor;
use DbPortable\Copy\Copier;
use DbPortable\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * db-portable:audit and db-portable:copy between CockroachDB and MatrixOne.
 */
class ToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->requireConnection('crdb');
        $this->requireConnection('matrixone');

        $this->dropToolTables();

        // Source: CockroachDB, where integer() is INT8.
        Schema::connection('crdb')->create('tool_videos', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->string('title', 500);
            $table->integer('view_count');
            $table->unsignedTinyInteger('score')->nullable();
            $table->boolean('is_public')->default(true);
            $table->json('meta')->nullable();
            $table->string('legacy')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->uuid('ref')->nullable();
        });
        Schema::connection('crdb')->create('tool_only_source', fn (Blueprint $table) => $table->id());

        // Target: MatrixOne, migrated with MySQL-sized columns.
        Schema::connection('matrixone')->create('tool_videos', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->string('title', 20);
            $table->integer('view_count');
            $table->unsignedTinyInteger('score')->nullable();
            $table->boolean('is_public')->default(true);
            $table->json('meta')->nullable();
            $table->dateTime('published_at', 6)->nullable();
            $table->char('ref', 36)->nullable();
        });

        DB::connection('crdb')->table('tool_videos')->insert([
            ['id' => 1, 'title' => 'Short', 'view_count' => 10, 'score' => 90, 'is_public' => true, 'meta' => '{"a": 1}', 'legacy' => 'x', 'published_at' => '2026-01-01 07:00:00+07'],
            ['id' => 2, 'title' => 'A title longer than twenty characters', 'view_count' => 15_950_438_052, 'score' => 500, 'is_public' => false, 'meta' => null, 'legacy' => null, 'published_at' => null],
            ['id' => 3, 'title' => 'Third', 'view_count' => 3, 'score' => null, 'is_public' => true, 'meta' => '[1, 2]', 'legacy' => null, 'published_at' => '2026-06-01 00:00:00+00'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropToolTables();

        parent::tearDown();
    }

    public function test_audit_finds_what_does_not_fit(): void
    {
        $problems = collect((new Auditor('crdb', 'matrixone'))->audit(['tool_videos', 'tool_only_source']))
            ->map(fn ($problem) => $problem['table'].'.'.($problem['column'] ?? '*').': '.$problem['problem'])
            ->sort()->values()->all();

        $this->assertSame([
            'tool_only_source.*: missing in target',
            'tool_videos.legacy: missing column',
            'tool_videos.score: integer out of range',
            'tool_videos.title: string too long',
            'tool_videos.view_count: integer out of range',
        ], $problems);

        $this->artisan('db-portable:audit', ['--from' => 'crdb', '--to' => 'matrixone', '--table' => ['tool_videos']])
            ->expectsOutputToContain('view_count')
            ->assertFailed();
    }

    public function test_copy_converts_values_for_the_target(): void
    {
        // Widen the target so every row fits (what the audit asks for).
        Schema::connection('matrixone')->table('tool_videos', function (Blueprint $table) {
            $table->string('title', 100)->change();
            $table->bigInteger('view_count')->change();
            $table->unsignedSmallInteger('score')->nullable()->change();
        });

        $this->artisan('db-portable:copy', ['--from' => 'crdb', '--to' => 'matrixone', '--table' => ['tool_videos'], '--force' => true])
            ->assertSuccessful();

        $rows = DB::connection('matrixone')->table('tool_videos')->orderBy('id')->get();

        $this->assertCount(3, $rows);
        $this->assertEquals(15_950_438_052, $rows[1]->view_count);
        $this->assertEquals(500, $rows[1]->score);
        $this->assertEquals(0, $rows[1]->is_public);
        $this->assertStringStartsWith('2026-01-01 00:00:00', (string) $rows[0]->published_at);
        $this->assertEquals(['a' => 1], json_decode((string) $rows[0]->meta, true));
        $this->assertEquals([1, 2], json_decode((string) $rows[2]->meta, true));
    }

    public function test_copy_resume_sample_and_dry_run(): void
    {
        Schema::connection('matrixone')->table('tool_videos', function (Blueprint $table) {
            $table->string('title', 100)->change();
            $table->bigInteger('view_count')->change();
            $table->unsignedSmallInteger('score')->nullable()->change();
        });

        $this->artisan('db-portable:copy', ['--from' => 'crdb', '--to' => 'matrixone', '--table' => ['tool_videos'], '--dry-run' => true])
            ->expectsOutputToContain('to copy')
            ->assertSuccessful();
        $this->assertSame(0, DB::connection('matrixone')->table('tool_videos')->count());

        $this->artisan('db-portable:copy', ['--from' => 'crdb', '--to' => 'matrixone', '--table' => ['tool_videos'], '--sample' => 1, '--force' => true])
            ->assertSuccessful();
        $this->assertSame([3], DB::connection('matrixone')->table('tool_videos')->pluck('id')->map(fn ($id) => (int) $id)->all());

        DB::connection('matrixone')->table('tool_videos')->delete();
        DB::connection('matrixone')->table('tool_videos')->insert(['id' => 1, 'title' => 'Short', 'view_count' => 10, 'is_public' => 1]);

        $this->artisan('db-portable:copy', ['--from' => 'crdb', '--to' => 'matrixone', '--table' => ['tool_videos'], '--resume' => true, '--chunk' => 1, '--force' => true])
            ->assertSuccessful();
        $this->assertSame([1, 2, 3], DB::connection('matrixone')->table('tool_videos')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_copy_from_matrixone_to_cockroachdb(): void
    {
        Schema::connection('matrixone')->table('tool_videos', function (Blueprint $table) {
            $table->string('title', 100)->change();
        });
        DB::connection('matrixone')->table('tool_videos')->insert([
            ['id' => 10, 'title' => 'From MatrixOne', 'view_count' => 7, 'score' => 3, 'is_public' => 0, 'meta' => '{"b": true}', 'published_at' => '2026-02-02 10:00:00.000000'],
        ]);
        DB::connection('crdb')->table('tool_videos')->delete();

        $this->artisan('db-portable:copy', ['--from' => 'matrixone', '--to' => 'crdb', '--table' => ['tool_videos'], '--force' => true])
            ->assertSuccessful();

        $row = DB::connection('crdb')->table('tool_videos')->where('id', 10)->first();

        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->is_public);
        $this->assertEquals(['b' => true], json_decode((string) $row->meta, true));
    }

    public function test_copy_orders_tables_by_foreign_keys(): void
    {
        foreach (['matrixone', 'crdb'] as $connection) {
            Schema::connection($connection)->create('tool_authors', fn (Blueprint $table) => $table->bigInteger('id')->primary());
            Schema::connection($connection)->create('tool_books', function (Blueprint $table) use ($connection) {
                $table->bigInteger('id')->primary();
                $table->bigInteger('author_id');

                // CockroachDB, the target, keeps checking foreign keys during the copy.
                if ($connection === 'crdb') {
                    $table->foreign('author_id')->references('id')->on('tool_authors');
                }
            });
        }
        DB::connection('matrixone')->table('tool_authors')->insert(['id' => 1]);
        DB::connection('matrixone')->table('tool_books')->insert(['id' => 1, 'author_id' => 1]);

        // Children first on the command line: the copier puts the parent first.
        $this->artisan('db-portable:copy', ['--from' => 'matrixone', '--to' => 'crdb', '--table' => ['tool_books', 'tool_authors'], '--force' => true])
            ->assertSuccessful();

        $this->assertSame(1, DB::connection('crdb')->table('tool_books')->count());
    }

    public function test_copy_moves_the_target_sequence_past_the_copied_keys(): void
    {
        Schema::connection('matrixone')->create('tool_sequences', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->integer('n');
        });
        DB::connection('crdb')->statement('create table tool_sequences (id bigint generated by default as identity primary key, n int not null)');
        DB::connection('matrixone')->table('tool_sequences')->insert([['id' => 1, 'n' => 1], ['id' => 2, 'n' => 2], ['id' => 3, 'n' => 3]]);

        $this->artisan('db-portable:copy', ['--from' => 'matrixone', '--to' => 'crdb', '--table' => ['tool_sequences'], '--force' => true])
            ->assertSuccessful();

        // Without the reset the identity would hand out 1 and collide with a copied row.
        $this->assertGreaterThan(3, DB::connection('crdb')->table('tool_sequences')->insertGetId(['n' => 4]));
    }

    public function test_copy_reports_rows_the_target_ignores(): void
    {
        Schema::connection('crdb')->create('tool_codes', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->string('code');
        });
        Schema::connection('matrixone')->create('tool_codes', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->string('code')->unique();
        });
        DB::connection('crdb')->table('tool_codes')->insert([['id' => 1, 'code' => 'a'], ['id' => 2, 'code' => 'a'], ['id' => 3, 'code' => 'b']]);

        $report = (new Copier('crdb', 'matrixone'))->copy(['tool_codes']);

        $this->assertSame(['rows' => 2, 'status' => 'incomplete: the target has 2 of 3 row(s), 1 skipped'], $report['tool_codes']);

        DB::connection('matrixone')->table('tool_codes')->delete();
        $this->artisan('db-portable:copy', ['--from' => 'crdb', '--to' => 'matrixone', '--table' => ['tool_codes'], '--force' => true])
            ->expectsOutputToContain('incomplete')
            ->assertFailed();
    }

    private function dropToolTables(): void
    {
        foreach (['crdb', 'matrixone'] as $connection) {
            foreach (['tool_videos', 'tool_only_source', 'tool_books', 'tool_authors', 'tool_sequences', 'tool_codes'] as $table) {
                Schema::connection($connection)->dropIfExists($table);
            }
        }
    }
}
