<?php

namespace DbPortable\Tests\Unit;

use DbPortable\Mirror\Jobs\MirrorKeys;
use DbPortable\Tests\Fixtures\Mirror\Analytics;
use DbPortable\Tests\Fixtures\Mirror\MirrorTables;
use DbPortable\Tests\Fixtures\Mirror\Order;
use DbPortable\Tests\TestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * The mirror:* command options, on SQLite → SQLite (tests/Conformance/MirrorCommandsTest
 * covers every pair).
 */
class MirrorCommandsTest extends TestCase
{
    use MirrorTables;

    private string $migrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mirrorPair('sqlite', 'sqlite_mirror', mirrorTables: false);
        $this->migrations = sys_get_temp_dir().'/db-portable-mirror-'.getmypid();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->migrations);

        parent::tearDown();
    }

    public function test_schema_writes_a_migration(): void
    {
        $this->artisan('db-portable:mirror:schema', ['mirror' => 'analytics', '--migration' => true, '--path' => $this->migrations])
            ->expectsOutputToContain('Migration written')
            ->assertSuccessful();

        $code = $this->migration();
        $this->assertStringContainsString("Schema::connection('mirror')->create('mirror_orders', function (Blueprint \$table) {", $code);
        $this->assertStringContainsString("\$table->bigInteger('id')->primary();", $code);
        $this->assertStringContainsString("\$table->decimal('total', 38, 2)->nullable();", $code);
        $this->assertStringContainsString('\\'.Analytics\Order::class.'::mirrorSchema($table);', $code);
        $this->assertFalse(Schema::connection('mirror')->hasTable('mirror_orders'));

        $this->runMigration('up');
        $this->assertTrue(Schema::connection('mirror')->hasTable('mirror_order_items'));
        $this->assertTrue(Schema::connection('mirror')->hasIndex('mirror_orders', ['status']));
        $this->artisan('db-portable:mirror:schema', ['mirror' => 'analytics'])->expectsOutputToContain('up to date')->assertSuccessful();

        // The owner gains a column: the next migration adds it.
        (new Filesystem)->cleanDirectory($this->migrations);
        Schema::table('mirror_orders', fn (Blueprint $table) => $table->string('note', 40)->nullable());
        $this->artisan('db-portable:mirror:schema', ['mirror' => 'analytics', '--migration' => true, '--path' => $this->migrations])->assertSuccessful();
        $this->assertStringContainsString("\$table->string('note', 255)->nullable();", $this->migration());   // SQLite keeps no varchar length

        $this->runMigration('up');
        $this->assertTrue(Schema::connection('mirror')->hasColumn('mirror_orders', 'note'));
        $this->runMigration('down');
        $this->assertFalse(Schema::connection('mirror')->hasColumn('mirror_orders', 'note'));
    }

    public function test_data_options(): void
    {
        $this->artisan('db-portable:mirror:schema')->assertSuccessful();
        config(['db-portable.mirrors.enabled' => false]);
        $old = Order::create(['status' => 'paid']);
        $old->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
        Order::create(['status' => 'paid']);
        config(['db-portable.mirrors.enabled' => true]);

        $this->artisan('db-portable:mirror:data', ['mirror' => 'analytics', '--model' => ['Order'], '--dry-run' => true])
            ->expectsOutputToContain('2 rows to write')
            ->assertSuccessful();
        $this->artisan('db-portable:mirror:data', ['mirror' => 'analytics', '--model' => ['Order'], '--since' => '-1 hour'])
            ->expectsOutputToContain('1 written')
            ->assertSuccessful();
        $this->assertNull(Analytics\Order::find($old->id));

        Queue::fake();
        $this->artisan('db-portable:mirror:data', ['mirror' => 'analytics', '--model' => ['Order'], '--queue' => true, '--chunk' => 1])->assertSuccessful();
        Queue::assertPushedTimes(MirrorKeys::class, 2);
        Queue::assertPushed(MirrorKeys::class, fn (MirrorKeys $job) => $job->mirror === 'analytics' && count($job->keys) === 1);

        $this->artisan('db-portable:mirror:data', ['--model' => ['Missing']])->expectsOutputToContain('is not an owner model')->assertFailed();
    }

    public function test_mirrors_turned_off_are_skipped(): void
    {
        config(['db-portable.mirrors.summary.enabled' => false]);

        $this->artisan('db-portable:mirror:schema')->expectsOutputToContain('turned off in this environment')->assertSuccessful();
        $this->assertFalse(Schema::connection('mirror')->hasTable('mirror_order_summaries'));
        $this->artisan('db-portable:mirror:schema', ['mirror' => 'summary', '--force' => true])->assertSuccessful();
        $this->assertTrue(Schema::connection('mirror')->hasTable('mirror_order_summaries'));

        $this->artisan('db-portable:mirror:stats', ['mirror' => 'summary'])->expectsOutputToContain('(turned off)')->assertSuccessful();
    }

    public function test_stats_as_json(): void
    {
        $this->artisan('db-portable:mirror:sync')->assertSuccessful();
        Order::create(['status' => 'paid', 'total' => 4]);

        $this->assertSame(0, Artisan::call('db-portable:mirror:stats', ['mirror' => 'analytics', '--model' => ['Order'], '--compare' => true, '--json' => true]));
        $reports = json_decode(Artisan::output(), true);

        $this->assertSame(['analytics', Order::class, 'on', false], [$reports[0]['mirror'], $reports[0]['owner'], $reports[0]['state'], $reports[0]['differs']]);
        $this->assertContains(['count(*)', '1', '1', ''], $reports[0]['rows']);
    }

    private function migration(): string
    {
        $files = glob($this->migrations.'/*_mirror_schema_analytics.php') ?: [];
        $this->assertCount(1, $files);

        return (string) file_get_contents($files[0]);
    }

    private function runMigration(string $direction): void
    {
        $files = glob($this->migrations.'/*.php') ?: [];

        /** @var Migration $migration */
        $migration = require $files[0];
        $migration->{$direction}();
    }
}
