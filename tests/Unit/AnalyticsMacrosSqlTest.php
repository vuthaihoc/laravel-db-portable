<?php

namespace DbPortable\Tests\Unit;

use DbPortable\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use YlsIdeas\CockroachDb\CockroachDbConnection;

class AnalyticsMacrosSqlTest extends TestCase
{
    /**
     * @param  class-string<Connection>  $class
     */
    private function builderFor(string $class, string $driver): Builder
    {
        $connection = new $class(fn () => null, 'app', '', ['driver' => $driver]);

        return $connection->query()->from('sales')->select('region')->selectRaw('sum(amount) as total')->groupBy('region');
    }

    public function test_rollup_sql(): void
    {
        $this->assertSame(
            'select `region`, sum(amount) as total from `sales` group by `region` with rollup',
            $this->builderFor(MySqlConnection::class, 'mysql')->rollup()->toSql()
        );
        $this->assertSame(
            'select "region", sum(amount) as total from "sales" group by rollup ("region")',
            $this->builderFor(PostgresConnection::class, 'pgsql')->rollup()->toSql()
        );
        $this->assertSame(
            '(select "region", sum(amount) as total from "sales" group by "region") union all (select null as "region", sum(amount) as total from "sales")',
            $this->builderFor(CockroachDbConnection::class, 'crdb')->rollup()->toSql()
        );
    }

    public function test_conditional_aggregate_sql(): void
    {
        $query = (new MySqlConnection(fn () => null, 'app', '', ['driver' => 'mysql']))->query()->from('orders')
            ->selectCountWhere('paid', fn ($q) => $q->where('status', 'paid'))
            ->selectSumWhere('big', 'total', fn ($q) => $q->where('total', '>', 100));

        $this->assertSame(
            'select count(case when `status` = ? then 1 end) as `paid`, sum(case when `total` > ? then `total` end) as `big` from `orders`',
            $query->toSql()
        );
        $this->assertSame(['paid', 100], $query->getBindings());
    }

    public function test_rollup_needs_groups(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MySqlConnection(fn () => null, 'app', '', ['driver' => 'mysql']))->query()->from('sales')->rollup();
    }

    public function test_unknown_aggregates_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MySqlConnection(fn () => null, 'app', '', ['driver' => 'mysql']))->query()->from('sales')
            ->selectAggregateWhere('median', 'total', fn ($q) => $q->where('a', 1), 'm');
    }
}
