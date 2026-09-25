<?php

namespace DbPortable\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;

/**
 * The database family of a connection: pgsql (PostgreSQL, CockroachDB),
 * mysql (MySQL, MariaDB, MatrixOne) or sqlite.
 */
final class Family
{
    public const POSTGRES = 'pgsql';

    public const MYSQL = 'mysql';

    public const SQLITE = 'sqlite';

    public static function of(Connection $connection): ?string
    {
        $connection->useDefaultSchemaGrammar();

        return match (true) {
            $connection->getSchemaGrammar() instanceof PostgresGrammar => self::POSTGRES,
            $connection->getSchemaGrammar() instanceof MySqlGrammar => self::MYSQL,
            $connection->getSchemaGrammar() instanceof SQLiteGrammar => self::SQLITE,
            default => null,
        };
    }

    public static function isMatrixOne(Connection $connection): bool
    {
        return $connection->getDriverName() === 'matrixone';
    }

    /**
     * Pick the callback for a connection from keys naming drivers (crdb,
     * matrixone, mariadb...) or families (pgsql, mysql, sqlite), several
     * separated by commas, or "default". A driver name wins over its family.
     *
     * @template T
     *
     * @param  array<string, T>  $callbacks
     * @return T|null
     */
    public static function pick(Connection $connection, array $callbacks): mixed
    {
        $keys = [];

        foreach ($callbacks as $key => $callback) {
            foreach (array_map('trim', explode(',', (string) $key)) as $name) {
                $keys[$name] ??= $callback;
            }
        }

        return $keys[$connection->getDriverName()] ?? $keys[self::of($connection) ?? ''] ?? $keys['default'] ?? null;
    }
}
