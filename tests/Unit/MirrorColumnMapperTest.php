<?php

namespace DbPortable\Tests\Unit;

use DbPortable\Mirror\Schema\ColumnMapper;
use DbPortable\Mirror\Schema\ColumnSpec;
use DbPortable\Schema\Family;
use DbPortable\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Owner columns (as Schema::getColumns() reports them on each database) mapped to
 * mirror columns. Connections are not opened: only their grammars are used.
 */
class MirrorColumnMapperTest extends TestCase
{
    public function test_types_across_families(): void
    {
        $this->assertMaps('boolean', [], ['mysql', 'flag', 'tinyint', 'tinyint(1)'], 'pgsql');
        $this->assertMaps('boolean', [], ['matrixone', 'flag', 'tinyint', 'tinyint', 'boolean'], 'pgsql');
        $this->assertMaps('tinyInteger', [], ['matrixone', 'level', 'tinyint', 'tinyint'], 'pgsql');
        $this->assertMaps('json', [], ['sqlite', 'meta', 'text', 'text', 'array'], 'mysql');
        $this->assertMaps('jsonb', [], ['mysql', 'meta', 'json', 'json'], 'crdb');
        $this->assertMaps('json', [], ['pgsql', 'meta', 'jsonb', 'jsonb'], 'matrixone');

        $this->assertMaps('unsignedBigInteger', [], ['mysql', 'id', 'bigint', 'bigint unsigned'], 'mysql', primary: true);
        $this->assertMaps('bigInteger', [], ['sqlite', 'id', 'integer', 'integer'], 'pgsql');
        $this->assertMaps('integer', [], ['pgsql', 'num', 'int4', 'integer'], 'mysql');

        $this->assertMaps('decimal', [10, 2], ['pgsql', 'total', 'numeric', 'numeric(10,2)'], 'mysql');
        $this->assertMaps('decimal', [38, 2], ['sqlite', 'total', 'numeric', 'numeric', 'decimal:2'], 'mysql');
        $this->assertMaps('decimal', [38, 10], ['pgsql', 'amount', 'numeric', 'numeric(60,10)'], 'matrixone');

        $this->assertMaps('string', [20], ['pgsql', 'name', 'varchar', 'character varying(20)'], 'sqlite');
        $this->assertMaps('text', [], ['pgsql', 'note', 'varchar', 'character varying'], 'mysql');
        $this->assertMaps('string', [255], ['sqlite', 'name', 'varchar', 'varchar'], 'pgsql');
        $this->assertMaps('text', [], ['mysql', 'body', 'varchar', 'varchar(20000)'], 'matrixone');
        $this->assertMaps('char', [3], ['pgsql', 'code', 'bpchar', 'character(3)'], 'mysql');
        $this->assertMaps('longText', [], ['pgsql', 'body', 'text', 'text'], 'mysql');
        $this->assertMaps('text', [], ['pgsql', 'body', 'text', 'text'], 'matrixone');
        $this->assertMaps('text', [], ['pgsql', 'secret', 'text', 'text', 'encrypted:array'], 'pgsql');
        $this->assertMaps('string', [255], ['mysql', 'status', 'enum', "enum('new','paid')"], 'pgsql');
        $this->assertMaps('uuid', [], ['pgsql', 'uid', 'uuid', 'uuid'], 'mysql');
        $this->assertMaps('ipAddress', [], ['crdb', 'ip', 'inet', 'inet'], 'mysql');

        // Timestamps: datetime on the MySQL family (no 2038 limit), time zones kept on PostgreSQL.
        $this->assertMaps('dateTime', [0], ['pgsql', 'ts', 'timestamp', 'timestamp(0) without time zone'], 'mysql');
        $this->assertMaps('timestampTz', [0], ['pgsql', 'at', 'timestamptz', 'timestamp(0) with time zone'], 'crdb');
        $this->assertMaps('timestamp', [6], ['mysql', 'dt', 'datetime', 'datetime(6)'], 'pgsql');
        $this->assertMaps('dateTime', [0], ['sqlite', 'ts', 'datetime', 'datetime'], 'sqlite');

        $this->assertNull($this->mapper('pgsql', 'mysql')->map(['name' => 'area', 'type_name' => 'geometry', 'type' => 'geometry'], null));
    }

    public function test_values_that_are_not_owner_columns(): void
    {
        $mapper = $this->mapper('pgsql', 'mysql');

        $this->assertSame(['string', [255]], $this->describe($mapper->infer('country', 'VN')));
        $this->assertSame(['bigInteger', []], $this->describe($mapper->infer('count', 3)));
        $this->assertSame(['boolean', []], $this->describe($mapper->infer('vip', true)));
        $this->assertSame(['json', []], $this->describe($mapper->infer('tags', ['a'])));
        $this->assertSame(['dateTime', [0]], $this->describe($mapper->infer('seen_at', now()->startOfSecond())));
        $this->assertSame(['text', []], $this->describe($mapper->infer('bio', str_repeat('x', 300))));
    }

    /**
     * @param  list<int|string>  $arguments
     * @param  array{0: string, 1: string, 2: string, 3: string, 4?: string}  $owner  [connection, column, type_name, type, cast]
     */
    private function assertMaps(string $method, array $arguments, array $owner, string $mirror, bool $primary = false): void
    {
        $spec = $this->mapper($owner[0], $mirror)->map(['name' => $owner[1], 'type_name' => $owner[2], 'type' => $owner[3]], $owner[4] ?? null, primary: $primary);

        $this->assertNotNull($spec, "{$owner[0]} {$owner[3]} → {$mirror}");
        $this->assertSame([$method, $arguments], $this->describe($spec), "{$owner[0]} {$owner[3]} → {$mirror}");
        $this->assertSame($primary, $spec->primary);
    }

    private function mapper(string $owner, string $mirror): ColumnMapper
    {
        /** @var Connection $ownerConnection */
        $ownerConnection = DB::connection($owner);
        /** @var Connection $mirrorConnection */
        $mirrorConnection = DB::connection($mirror);

        return new ColumnMapper($mirrorConnection, Family::of($ownerConnection) ?? Family::POSTGRES);
    }

    /**
     * @return array{0: string, 1: list<int|string>}
     */
    private function describe(ColumnSpec $spec): array
    {
        return [$spec->method, $spec->arguments];
    }
}
