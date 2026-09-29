<?php

namespace DbPortable\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\Grammar as QueryGrammar;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;

/**
 * The database family of a connection: pgsql (PostgreSQL, CockroachDB, and
 * XTDB over its PostgreSQL wire protocol), mysql (MySQL, MariaDB, MatrixOne)
 * or sqlite.
 */
final class Family
{
    public const POSTGRES = 'pgsql';

    public const MYSQL = 'mysql';

    public const SQLITE = 'sqlite';

    /** Driver names of vuthaihoc/cockroachdb-laravel, vuthaihoc/laravel-matrixone and vuthaihoc/laravel-xtdb2. */
    public const CRDB = 'crdb';

    public const MATRIXONE = 'matrixone';

    /**
     * Experimental: XTDB 2.2 is not released yet, so no macro is adapted to XTDB and
     * the conformance suite does not run on it. XTDB's grammars extend PostgreSQL's,
     * so the macros compile the PostgreSQL SQL, which XTDB does not always accept.
     */
    public const XTDB = 'xtdb';

    /**
     * The drivers' connection classes, so a driver registered under another name
     * (e.g. "cockroach") is still recognized. Strings: the drivers are optional.
     */
    private const CONNECTIONS = [
        self::CRDB => 'YlsIdeas\CockroachDb\CockroachDbConnection',
        self::MATRIXONE => 'MatrixOne\MatrixOneConnection',
        self::XTDB => 'LaravelXtdb\XtdbConnection',
    ];

    private const MATRIXONE_QUERY_GRAMMAR = 'MatrixOne\Query\Grammar';

    public static function of(Connection $connection): ?string
    {
        // Keep a schema grammar the application installed; only fill in a missing one
        // (Laravel types it as always set, but it is null until first needed).
        /** @var Grammar|null $grammar */
        $grammar = $connection->getSchemaGrammar();

        if ($grammar === null) {
            $connection->useDefaultSchemaGrammar();
        }

        return match (true) {
            $connection->getSchemaGrammar() instanceof PostgresGrammar => self::POSTGRES,
            $connection->getSchemaGrammar() instanceof MySqlGrammar => self::MYSQL,
            $connection->getSchemaGrammar() instanceof SQLiteGrammar => self::SQLITE,
            default => null,
        };
    }

    /**
     * The connection's driver: "crdb" or "matrixone" for the drivers' connections, whatever
     * name they are registered under, else the configured driver name.
     */
    public static function driver(Connection $connection): string
    {
        foreach (self::CONNECTIONS as $driver => $class) {
            if (is_a($connection, $class)) {
                return $driver;
            }
        }

        return $connection->getDriverName();
    }

    public static function isCockroachDb(Connection $connection): bool
    {
        return self::driver($connection) === self::CRDB;
    }

    public static function isMatrixOne(Connection $connection): bool
    {
        return self::driver($connection) === self::MATRIXONE;
    }

    /**
     * Experimental, see XTDB.
     */
    public static function isXtdb(Connection $connection): bool
    {
        return self::driver($connection) === self::XTDB;
    }

    /**
     * For code that only has the query grammar (the dialects).
     */
    public static function isMatrixOneGrammar(QueryGrammar $grammar): bool
    {
        return is_a($grammar, self::MATRIXONE_QUERY_GRAMMAR);
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

        return $keys[self::driver($connection)] ?? $keys[self::of($connection) ?? ''] ?? $keys['default'] ?? null;
    }
}
