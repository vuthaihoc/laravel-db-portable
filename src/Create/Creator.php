<?php

namespace DbPortable\Create;

use DbPortable\Schema\Family;
use Illuminate\Database\Connection;
use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDOException;
use Throwable;

/**
 * Creates the database of a connection, and a user limited to it, as an admin
 * user: the connection's own user or another one. The admin connects to the
 * server (to a maintenance database on the PostgreSQL family: the connection's
 * database may not exist yet); schema grants run in the new database.
 *
 * Statements holding a password run on the PDO directly: no query event or
 * query log receives them.
 */
final class Creator
{
    private const ADMIN = 'db_portable_create';

    private const IN_DATABASE = 'db_portable_create_database';

    /** An unknown driver is resolved under this name, to learn its family. */
    private const TARGET = 'db_portable_create_target';

    /**
     * The settings of a connection given by its driver only (the user and password
     * stay unset: the command asks for an admin).
     *
     * @var array<string, array<string, mixed>>
     */
    private const DEFAULTS = [
        'pgsql' => ['host' => '127.0.0.1', 'port' => 5432, 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer'],
        'crdb' => ['host' => '127.0.0.1', 'port' => 26257, 'charset' => 'utf8', 'prefix' => '', 'schema' => 'public', 'sslmode' => 'prefer'],
        'mysql' => ['host' => '127.0.0.1', 'port' => 3306, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => ''],
        'mariadb' => ['host' => '127.0.0.1', 'port' => 3306, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => ''],
        'matrixone' => ['host' => '127.0.0.1', 'port' => 6001, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => ''],
        'sqlite' => ['prefix' => '', 'foreign_key_constraints' => true],
    ];

    /** @var array<string, mixed> */
    private array $config;

    private string $kind;

    private ?Connection $admin = null;

    /** @var array{string, string|null}|null */
    private ?array $credentials = null;

    /**
     * @param  string|null  $connection  A configured connection; null: a connection made of $overrides, which then name the driver.
     * @param  array<string, mixed>  $overrides  Settings replacing the connection's (host, port, driver...).
     */
    public function __construct(private readonly ?string $connection, array $overrides = [])
    {
        if ($connection === null) {
            $driver = $overrides['driver'] ?? null;

            if (! is_string($driver) || $driver === '') {
                throw new InvalidArgumentException('Name a connection, or a driver (--driver) with its settings (-o host=...).');
            }

            $config = self::DEFAULTS[$driver] ?? ['prefix' => ''];
        } else {
            $config = config("database.connections.{$connection}");

            if (! is_array($config)) {
                throw new InvalidArgumentException("Database connection [{$connection}] not configured.");
            }

            // The url wins over the other keys; the write host is the primary.
            $config = (new ConfigurationUrlParser)->parseConfiguration($config);
            $write = is_array($config['write'] ?? null) ? $config['write'] : [];
            $config = array_merge(Arr::except($config, ['url', 'read', 'write', 'sticky', 'connect_via_database']), $write);
        }

        $config = array_merge($config, $overrides);

        if (is_array($config['host'] ?? null)) {
            $config['host'] = reset($config['host']);
        }

        $this->config = $config;

        // Known drivers without a connection: the connection's user may be refused (and CockroachDB's connects at once).
        $this->kind = match ($config['driver'] ?? null) {
            Family::SQLITE, Family::POSTGRES, Family::MYSQL => $config['driver'],
            'mariadb' => Family::MYSQL,
            Family::CRDB, Family::MATRIXONE => $config['driver'],
            default => self::kind($this->target()),
        };
    }

    /**
     * The drivers --driver knows the default settings of.
     *
     * @return list<string>
     */
    public static function drivers(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /**
     * sqlite, pgsql, crdb, mysql (MySQL, MariaDB) or matrixone.
     */
    public static function kind(Connection $connection): string
    {
        $driver = Family::driver($connection);

        return match (true) {
            $driver === Family::CRDB, $driver === Family::MATRIXONE => $driver,
            $driver === Family::XTDB => throw new InvalidArgumentException('XTDB creates no database: one database per node.'),
            default => Family::of($connection) ?? throw new InvalidArgumentException("db-portable:db:create does not support the [{$driver}] driver."),
        };
    }

    public function driver(): string
    {
        return $this->kind;
    }

    public function database(): ?string
    {
        return $this->setting('database');
    }

    public function username(): ?string
    {
        return isset($this->config['username']) && $this->config['username'] !== '' ? (string) $this->config['username'] : null;
    }

    public function password(): ?string
    {
        return isset($this->config['password']) ? (string) $this->config['password'] : null;
    }

    /**
     * A string setting of the connection (charset, collation...).
     */
    public function setting(string $key): ?string
    {
        return is_string($this->config[$key] ?? null) && $this->config[$key] !== '' ? $this->config[$key] : null;
    }

    /**
     * The admin's credentials: [user, password].
     *
     * @return array{string, string|null}|null
     */
    public function credentials(): ?array
    {
        return $this->credentials;
    }

    /**
     * Connect to the server as $user, to the maintenance database on the PostgreSQL
     * family (default: postgres, defaultdb on CockroachDB).
     */
    public function connectAs(string $user, ?string $password, ?string $maintenanceDatabase = null): void
    {
        $this->disconnect();

        $database = match ($this->kind) {
            Family::POSTGRES => $maintenanceDatabase ?? 'postgres',
            Family::CRDB => $maintenanceDatabase ?? 'defaultdb',
            default => '',
        };

        config(['database.connections.'.self::ADMIN => ['database' => $database, 'username' => $user, 'password' => $password ?? ''] + $this->config]);
        $this->admin = DB::connection(self::ADMIN);

        try {
            $this->admin->getPdo()->query('select 1');
        } catch (Throwable $e) {
            $this->disconnect();

            throw $e;
        }

        $this->credentials = [$user, $password];
    }

    public function facts(CreateOptions $options): ServerFacts
    {
        $admin = $this->admin();

        if ($this->kind === Family::POSTGRES || $this->kind === Family::CRDB) {
            $row = (array) $admin->selectOne(
                'select current_user as name, (select rolsuper from pg_roles where rolname = current_user) as superuser,'
                .' exists (select 1 from pg_database where datname = ?) as db, exists (select 1 from pg_roles where rolname = ?) as usr',
                [$options->database, (string) $options->user],
            );

            return new ServerFacts((string) $row['name'], (bool) $row['db'], (bool) $row['usr'], (bool) $row['superuser']);
        }

        $current = (string) $admin->scalar('select current_user()');
        $database = (int) $admin->scalar('select count(*) from information_schema.schemata where schema_name = ?', [$options->database]);
        $user = $options->user === null ? 0 : (int) ($this->kind === Family::MATRIXONE
            ? $admin->scalar('select count(*) from mo_catalog.mo_user where user_name = ?', [$options->user])
            : $admin->scalar('select count(*) from mysql.user where user = ? and host = ?', [$options->user, $options->userHost]));

        // user@host on MySQL.
        $name = str_contains($current, '@') ? substr($current, 0, (int) strrpos($current, '@')) : $current;

        return new ServerFacts($name, $database > 0, $user > 0);
    }

    public function planner(CreateOptions $options, ?ServerFacts $facts = null): Planner
    {
        $admin = $this->admin();

        return new Planner($this->kind, $options, $facts ?? $this->facts($options), fn (string $value): string => $admin->escape($value));
    }

    /**
     * Run the plan as the admin.
     */
    public function run(Plan $plan, string $database): void
    {
        foreach ($plan->server as $statement) {
            $this->admin()->getPdo()->exec($statement);
        }

        if ($plan->database === []) {
            return;
        }

        [$user, $password] = $this->credentials ?? ['', null];
        config(['database.connections.'.self::IN_DATABASE => ['database' => $database, 'username' => $user, 'password' => $password ?? ''] + $this->config]);

        try {
            foreach ($plan->database as $statement) {
                DB::connection(self::IN_DATABASE)->getPdo()->exec($statement);
            }
        } finally {
            DB::purge(self::IN_DATABASE);
        }
    }

    /**
     * CockroachDB in insecure mode refuses passwords.
     */
    public static function refusesPasswords(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'password is not supported in insecure mode');
    }

    /**
     * The error of a user that cannot log in, or lacks a privilege.
     */
    public static function deniedAccess(Throwable $e): bool
    {
        $previous = $e->getPrevious();
        $info = $e instanceof PDOException ? $e->errorInfo : ($previous instanceof PDOException ? $previous->errorInfo : null);

        if (is_array($info) && (in_array($info[0] ?? null, ['42501', '28000', '28P01'], true)
            || in_array((int) ($info[1] ?? 0), [1044, 1045, 1142, 1227, 1410, 20101], true))) {
            return true;
        }

        return (bool) preg_match(
            '/access denied|permission denied|authentication failed|insufficient privilege|do not have privilege|user does not have|role "[^"]*" does not exist|password authentication/i',
            $e->getMessage(),
        );
    }

    /**
     * SQLite: create the database file (and nothing for :memory:).
     *
     * @return bool Whether the file was created.
     */
    public function createSqliteFile(): bool
    {
        $path = (string) $this->database();

        if ($path === '' || $path === ':memory:' || str_contains($path, 'mode=memory') || file_exists($path)) {
            return false;
        }

        if (! is_dir(dirname($path))) {
            throw new InvalidArgumentException("The directory of [{$path}] does not exist.");
        }

        touch($path);

        return true;
    }

    public function disconnect(): void
    {
        $this->admin = null;
        DB::purge(self::ADMIN);
    }

    /**
     * Forget the target connection, so its next use connects to the new database.
     */
    public function forgetConnection(): void
    {
        if ($this->connection !== null) {
            DB::purge($this->connection);
        }
    }

    private function target(): Connection
    {
        config(['database.connections.'.self::TARGET => $this->config]);
        DB::purge(self::TARGET);

        return DB::connection(self::TARGET);
    }

    private function admin(): Connection
    {
        return $this->admin ?? throw new InvalidArgumentException('Not connected: call connectAs() first.');
    }
}
