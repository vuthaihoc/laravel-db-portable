<?php

namespace DbPortable\Tests;

use DbPortable\DbPortableServiceProvider;
use Illuminate\Foundation\Application;
use MatrixOne\MatrixOneServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use PDO;
use Throwable;
use YlsIdeas\CockroachDb\CockroachDbServiceProvider;

/**
 * Connections: sqlite (in memory), matrixone, crdb, pgsql (PostgreSQL) and mysql.
 * Server connections are skipped when unreachable; their test database is
 * created on first use.
 */
abstract class TestCase extends OrchestraTestCase
{
    public const DATABASE = 'laravel_db_portable_test';

    /** @var array<string, bool> */
    private static array $available = [];

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [MatrixOneServiceProvider::class, CockroachDbServiceProvider::class, DbPortableServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('database.connections.matrixone', [
            'driver' => 'matrixone',
            'host' => self::env('MATRIXONE_HOST', '127.0.0.1'),
            'port' => (int) self::env('MATRIXONE_PORT', '6001'),
            'database' => self::DATABASE,
            'username' => self::env('MATRIXONE_USERNAME', 'root'),
            'password' => self::env('MATRIXONE_PASSWORD', '111'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
        $app['config']->set('database.connections.crdb', [
            'driver' => 'crdb',
            'host' => self::env('CRDB_HOST', '127.0.0.1'),
            'port' => (int) self::env('CRDB_PORT', '26258'),
            'database' => self::DATABASE,
            'username' => self::env('CRDB_USERNAME', 'root'),
            'password' => self::env('CRDB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'disable',
        ]);
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => self::env('PGSQL_HOST', '127.0.0.1'),
            'port' => (int) self::env('PGSQL_PORT', '5433'),
            'database' => self::DATABASE,
            'username' => self::env('PGSQL_USERNAME', 'postgres'),
            'password' => self::env('PGSQL_PASSWORD', 'secret'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'disable',
        ]);
        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => self::env('MYSQL_HOST', '127.0.0.1'),
            'port' => (int) self::env('MYSQL_PORT', '3307'),
            'database' => self::DATABASE,
            'username' => self::env('MYSQL_USERNAME', 'root'),
            'password' => self::env('MYSQL_PASSWORD', 'secret'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function connections(): array
    {
        return [
            'sqlite' => ['sqlite'],
            'matrixone' => ['matrixone'],
            'crdb' => ['crdb'],
            'pgsql' => ['pgsql'],
            'mysql' => ['mysql'],
        ];
    }

    /**
     * Skip the test when the server of $connection is unreachable.
     */
    protected function requireConnection(string $connection): void
    {
        if ($connection === 'sqlite') {
            return;
        }

        self::$available[$connection] ??= self::createDatabase($connection);

        if (! self::$available[$connection]) {
            // CI sets DB_PORTABLE_REQUIRE_SERVERS so a missing server fails instead of passing silently.
            if (self::env('DB_PORTABLE_REQUIRE_SERVERS', '') !== '') {
                $this->fail("The {$connection} server is not reachable.");
            }

            $this->markTestSkipped("The {$connection} server is not reachable.");
        }
    }

    private static function createDatabase(string $connection): bool
    {
        try {
            $pdo = match ($connection) {
                'matrixone' => new PDO(
                    sprintf('mysql:host=%s;port=%s', self::env('MATRIXONE_HOST', '127.0.0.1'), self::env('MATRIXONE_PORT', '6001')),
                    self::env('MATRIXONE_USERNAME', 'root'),
                    self::env('MATRIXONE_PASSWORD', '111'),
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3],
                ),
                'crdb' => new PDO(
                    sprintf('pgsql:host=%s;port=%s;dbname=defaultdb;sslmode=disable', self::env('CRDB_HOST', '127.0.0.1'), self::env('CRDB_PORT', '26258')),
                    self::env('CRDB_USERNAME', 'root'),
                    self::env('CRDB_PASSWORD', ''),
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3],
                ),
                'pgsql' => new PDO(
                    sprintf('pgsql:host=%s;port=%s;dbname=postgres;sslmode=disable', self::env('PGSQL_HOST', '127.0.0.1'), self::env('PGSQL_PORT', '5433')),
                    self::env('PGSQL_USERNAME', 'postgres'),
                    self::env('PGSQL_PASSWORD', 'secret'),
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3],
                ),
                'mysql' => new PDO(
                    sprintf('mysql:host=%s;port=%s', self::env('MYSQL_HOST', '127.0.0.1'), self::env('MYSQL_PORT', '3307')),
                    self::env('MYSQL_USERNAME', 'root'),
                    self::env('MYSQL_PASSWORD', 'secret'),
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3],
                ),
                default => null,
            };

            if ($connection === 'pgsql' && $pdo !== null) {
                // PostgreSQL has no "create database if not exists"; the search macros need pg_trgm and unaccent.
                if (! $pdo->query("select 1 from pg_database where datname = '".self::DATABASE."'")?->fetchColumn()) {
                    $pdo->exec('create database '.self::DATABASE);
                }

                $pdo = new PDO(
                    sprintf('pgsql:host=%s;port=%s;dbname=%s;sslmode=disable', self::env('PGSQL_HOST', '127.0.0.1'), self::env('PGSQL_PORT', '5433'), self::DATABASE),
                    self::env('PGSQL_USERNAME', 'postgres'),
                    self::env('PGSQL_PASSWORD', 'secret'),
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3],
                );
                $pdo->exec('create extension if not exists pg_trgm');
                $pdo->exec('create extension if not exists unaccent');

                return true;
            }

            $pdo?->exec('create database if not exists '.self::DATABASE);

            return $pdo !== null;
        } catch (Throwable) {
            return false;
        }
    }

    protected static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }
}
