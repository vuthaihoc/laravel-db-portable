<?php

namespace DbPortable\Tests\Conformance;

use DbPortable\Tests\TestCase;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class HistoricalReadsTest extends TestCase
{
    private string $connection;

    private function useConnection(string $connection): void
    {
        $this->requireConnection($connection);
        $this->connection = $connection;

        Schema::connection($connection)->dropIfExists('portable_history');
        Schema::connection($connection)->create('portable_history', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->integer('total');
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            Schema::connection($this->connection)->dropIfExists('portable_history');
        }

        parent::tearDown();
    }

    private function table(): Builder
    {
        return DB::connection($this->connection)->table('portable_history');
    }

    #[DataProvider('connections')]
    public function test_as_of_time_reads_the_past_where_the_database_can(string $connection): void
    {
        $this->useConnection($connection);
        Log::spy();

        $this->table()->insert(['id' => 1, 'total' => 10]);
        sleep(2);
        $this->table()->insert(['id' => 2, 'total' => 20]);

        $past = $this->table()->asOfTime('-1s')->count();
        $sum = $this->table()->asOfTime(now()->subSecond())->sum('total');

        if ($connection === 'sqlite') {
            // No time travel: current data, and a warning.
            $this->assertSame(2, $past);
            Log::shouldHaveReceived('warning')->atLeast()->once();
        } else {
            $this->assertSame(1, $past);
            $this->assertEquals(10, $sum);
        }

        $this->assertSame(2, $this->table()->asOfTime('-1s')->readCurrent()->count());
    }

    #[DataProvider('connections')]
    public function test_read_stale(string $connection): void
    {
        $this->useConnection($connection);

        if ($connection === 'crdb') {
            sleep(6);   // the table must exist at the follower read timestamp
        }

        $this->table()->insert(['id' => 1, 'total' => 10]);

        if ($connection === 'crdb') {
            // A follower read is about 4.8 seconds old.
            $this->assertSame(0, $this->table()->readStale()->count());
            sleep(6);
        }

        $this->assertSame(1, $this->table()->readStale()->count());
        $this->assertStringContainsString(
            $connection === 'crdb' ? 'follower_read_timestamp()' : 'portable_history',
            $this->table()->readStale()->toSql()
        );
    }

    #[DataProvider('connections')]
    public function test_strict_mode(string $connection): void
    {
        $this->useConnection($connection);
        config(['db-portable.strict' => true]);

        if ($connection === 'sqlite') {
            $this->expectException(RuntimeException::class);
        } else {
            sleep(2);   // the table must exist at the time read
        }

        $this->assertSame(0, $this->table()->asOfTime('-1s')->count());
    }
}
