<?php

namespace DbPortable\Tests\Unit;

use DateTimeImmutable;
use DbPortable\Support\ValueMapper;
use DbPortable\Tests\Fixtures\Priority;
use DbPortable\Tests\Fixtures\Status;
use DbPortable\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class ValueMapperTest extends TestCase
{
    public function test_values_the_target_accepts(): void
    {
        // Connections are not opened: only their grammars are used.
        [$pgsql, $mysql, $sqlite] = array_map(
            fn (string $name) => new ValueMapper($this->connection($name)),
            ['pgsql', 'mysql', 'sqlite'],
        );

        $this->assertTrue($pgsql->value(true));
        $this->assertSame(1, $mysql->value(true));
        $this->assertSame(1, $sqlite->value(true));
        $this->assertTrue($pgsql->value(1, 'bool'));
        $this->assertSame(0, $mysql->value('0', 'boolean'));
        $this->assertNull($pgsql->value(null, 'bool'));

        $this->assertSame('{"a":[1,"é"]}', $mysql->value(['a' => [1, 'é']]));
        $this->assertSame('{"a":1}', $sqlite->value((object) ['a' => 1]));
        $this->assertSame('[1,2]', $pgsql->value(collect([1, 2])));

        $this->assertSame('paid', $sqlite->value(Status::Paid));
        $this->assertSame('High', $sqlite->value(Priority::High));

        // Dates as Eloquent stores them: in their own time zone, without an offset.
        $this->assertSame('2026-09-29 10:00:00', $pgsql->value(new DateTimeImmutable('2026-09-29 10:00:00+07:00')));
        $this->assertSame('2026-09-29 10:00:00.250000', $mysql->value(new DateTimeImmutable('2026-09-29 10:00:00.25')));

        // A string with an offset: the MySQL family stores the instant in UTC.
        $this->assertSame('2026-01-01 00:00:00.000000', $mysql->value('2026-01-01 07:00:00+07'));
        $this->assertSame('2026-01-01 07:00:00+07', $pgsql->value('2026-01-01 07:00:00+07'));
    }

    private function connection(string $name): Connection
    {
        /** @var Connection */
        return DB::connection($name);
    }
}
