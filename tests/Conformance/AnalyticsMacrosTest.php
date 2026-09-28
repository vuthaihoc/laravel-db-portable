<?php

namespace DbPortable\Tests\Conformance;

use DbPortable\Tests\TestCase;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class AnalyticsMacrosTest extends TestCase
{
    private string $connection;

    private function useConnection(string $connection): void
    {
        $this->requireConnection($connection);
        $this->connection = $connection;

        Schema::connection($connection)->dropIfExists('portable_sales');
        Schema::connection($connection)->create('portable_sales', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->string('region');
            $table->string('product');
            $table->string('status');
            $table->integer('amount');
        });

        $this->table()->insert([
            ['id' => 1, 'region' => 'north', 'product' => 'a', 'status' => 'paid', 'amount' => 10],
            ['id' => 2, 'region' => 'north', 'product' => 'b', 'status' => 'refunded', 'amount' => 20],
            ['id' => 3, 'region' => 'north', 'product' => 'b', 'status' => 'paid', 'amount' => 5],
            ['id' => 4, 'region' => 'south', 'product' => 'a', 'status' => 'paid', 'amount' => 40],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            Schema::connection($this->connection)->dropIfExists('portable_sales');
        }

        parent::tearDown();
    }

    private function table(): Builder
    {
        return DB::connection($this->connection)->table('portable_sales');
    }

    #[DataProvider('connections')]
    public function test_conditional_aggregates(string $connection): void
    {
        $this->useConnection($connection);

        $row = $this->table()
            ->selectCountWhere('paid_orders', fn ($q) => $q->where('status', 'paid'))
            ->selectSumWhere('paid_total', 'amount', fn ($q) => $q->where('status', 'paid')->where('amount', '>', 5))
            ->selectAggregateWhere('max', 'amount', fn ($q) => $q->whereIn('region', ['north']), 'north_max')
            ->selectRaw('count(*) as all_orders')
            ->first();

        $this->assertEquals(3, $row?->paid_orders);
        $this->assertEquals(50, $row?->paid_total);
        $this->assertEquals(20, $row?->north_max);
        $this->assertEquals(4, $row?->all_orders);

        $byRegion = $this->table()
            ->select('region')
            ->selectSumWhere('refunded', 'amount', fn ($q) => $q->where('status', 'refunded'))
            ->groupBy('region')
            ->orderBy('region')
            ->get()
            ->map(fn ($r) => [$r->region, $r->refunded === null ? null : (int) $r->refunded])
            ->all();

        $this->assertSame([['north', 20], ['south', null]], $byRegion);
    }

    #[DataProvider('connections')]
    public function test_rollup(string $connection): void
    {
        $this->useConnection($connection);

        $rows = $this->table()
            ->select('region', 'product')
            ->selectRaw('sum(amount) as total')
            ->groupBy('region', 'product')
            ->rollup()
            ->get()
            ->map(fn ($r) => ($r->region ?? '*').'/'.($r->product ?? '*').'='.(int) $r->total)
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            '*/*=75',
            'north/*=35',
            'north/a=10',
            'north/b=25',
            'south/*=40',
            'south/a=40',
        ], $rows);
    }

    #[DataProvider('connections')]
    public function test_rollup_with_filters(string $connection): void
    {
        $this->useConnection($connection);

        $totals = $this->table()
            ->select('region')
            ->selectRaw('sum(amount) as total')
            ->where('status', 'paid')
            ->groupBy('region')
            ->rollup()
            ->get()
            ->mapWithKeys(fn ($r) => [$r->region ?? 'all' => (int) $r->total])
            ->sortKeys()
            ->all();

        $this->assertSame(['all' => 55, 'north' => 15, 'south' => 40], $totals);
    }
}
