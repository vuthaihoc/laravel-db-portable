<?php

namespace DbPortable\Tests\Conformance;

use DbPortable\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * db-portable:db:create on every server: the database, a user limited to it
 * with each privileges level, and the admin asked for when the connection's
 * user is refused.
 */
class CreateDatabaseTest extends TestCase
{
    private const CREATED = 'dbp_created';

    /** @var list<string> */
    private const USERS = ['dbp_app', 'dbp_nobody'];

    private ?string $connection = null;

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $this->dropCreated($this->connection);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function servers(): array
    {
        return array_diff_key(self::connections(), ['sqlite' => true]);
    }

    #[DataProvider('servers')]
    public function test_creates_the_database_and_a_user_owning_it(string $connection): void
    {
        $this->prepare($connection);

        $this->artisan('db-portable:db:create', [
            'connection' => $connection, '--database' => self::CREATED, '--user' => 'dbp_app', '--password' => 'Dbp-secret-1', '--no-interaction' => true,
        ])->expectsOutputToContain('is ready for [dbp_app] (all)')->assertSuccessful();

        $app = $this->connectAs($connection, 'dbp_app', 'Dbp-secret-1');
        $app->statement('create table dbp_items (id int primary key, name varchar(20))');
        $app->table('dbp_items')->insert(['id' => 1, 'name' => 'one']);
        $this->assertSame('one', $app->table('dbp_items')->value('name'));

        // Again: nothing to create, the grants are applied again.
        $this->artisan('db-portable:db:create', [
            'connection' => $connection, '--database' => self::CREATED, '--user' => 'dbp_app', '--no-interaction' => true,
        ])->expectsOutputToContain('exists')->assertSuccessful();
        $this->assertSame(1, $app->table('dbp_items')->count());
    }

    #[DataProvider('servers')]
    public function test_a_readonly_user_reads_the_tables_created_later(string $connection): void
    {
        $this->prepare($connection);

        $this->artisan('db-portable:db:create', [
            'connection' => $connection, '--database' => self::CREATED, '--user' => 'dbp_app', '--password' => 'Dbp-secret-1',
            '--privileges' => 'readonly', '--no-interaction' => true,
        ])->assertSuccessful();

        // The admin migrates, after the grants.
        $admin = $this->connectAs($connection, null, null);
        $admin->statement('create table dbp_items (id int primary key, name varchar(20))');
        $admin->table('dbp_items')->insert(['id' => 1, 'name' => 'one']);

        $app = $this->connectAs($connection, 'dbp_app', 'Dbp-secret-1');
        $this->assertSame('one', $app->table('dbp_items')->value('name'));
        $this->assertRefused(fn () => $app->table('dbp_items')->insert(['id' => 2, 'name' => 'two']));
        $this->assertRefused(fn () => $app->statement('create table dbp_more (id int primary key)'));
    }

    #[DataProvider('servers')]
    public function test_a_readwrite_user_writes_rows_but_not_tables(string $connection): void
    {
        $this->prepare($connection);

        $this->artisan('db-portable:db:create', [
            'connection' => $connection, '--database' => self::CREATED, '--user' => 'dbp_app', '--password' => 'Dbp-secret-1',
            '--privileges' => 'readwrite', '--no-interaction' => true,
        ])->assertSuccessful();

        $admin = $this->connectAs($connection, null, null);
        $admin->statement('create table dbp_items (id int primary key, name varchar(20))');

        $app = $this->connectAs($connection, 'dbp_app', 'Dbp-secret-1');
        $app->table('dbp_items')->insert(['id' => 1, 'name' => 'one']);
        $app->table('dbp_items')->where('id', 1)->update(['name' => 'uno']);
        $this->assertSame('uno', $app->table('dbp_items')->value('name'));
        $this->assertRefused(fn () => $app->statement('create table dbp_more (id int primary key)'));
    }

    #[DataProvider('servers')]
    public function test_asks_for_an_admin_when_the_connection_user_is_refused(string $connection): void
    {
        $this->prepare($connection);
        $root = config("database.connections.{$connection}");
        config(["database.connections.{$connection}_app" => ['database' => self::CREATED, 'username' => 'dbp_nobody', 'password' => 'Dbp-nobody-1'] + $root]);
        $admin = (string) $root['username'];

        $this->artisan('db-portable:db:create', ['connection' => "{$connection}_app"])
            ->expectsQuestion('Admin user (allowed to create databases and users)', $admin)
            ->expectsQuestion("Password of [{$admin}]", (string) $root['password'])
            ->expectsQuestion('User for ['.self::CREATED."] (empty: none, the admin [{$admin}] only)", 'dbp_nobody')
            ->expectsChoice('Privileges of [dbp_nobody] on ['.self::CREATED.']', 'all', ['all', 'readwrite', 'readonly'])
            ->expectsConfirmation('Run these statements?', 'yes')
            ->expectsOutputToContain('is ready for [dbp_nobody] (all)')
            ->assertSuccessful();

        // The connection's own credentials work now.
        DB::purge("{$connection}_app");
        DB::connection("{$connection}_app")->statement('create table dbp_items (id int primary key)');
        $this->assertSame(0, DB::connection("{$connection}_app")->table('dbp_items')->count());
    }

    #[DataProvider('servers')]
    public function test_pretend_prints_the_statements_without_the_password(string $connection): void
    {
        $this->prepare($connection);

        $this->artisan('db-portable:db:create', [
            'connection' => $connection, '--database' => self::CREATED, '--user' => 'dbp_app', '--password' => 'Dbp-secret-1',
            '--pretend' => true, '--no-interaction' => true,
        ])->expectsOutputToContain('create')->doesntExpectOutputToContain('Dbp-secret-1')->assertSuccessful();

        $this->assertFalse($this->databaseExists($connection));
    }

    public function test_creates_the_sqlite_file(): void
    {
        $path = sys_get_temp_dir().'/dbp-created-'.getmypid().'.sqlite';
        @unlink($path);
        config(['database.connections.sqlite_file' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '']]);

        try {
            $this->artisan('db-portable:db:create', ['connection' => 'sqlite_file', '--no-interaction' => true])
                ->expectsOutputToContain('Created the SQLite database')
                ->assertSuccessful();
            $this->assertFileExists($path);

            $this->artisan('db:create', ['connection' => 'sqlite_file', '--no-interaction' => true])
                ->expectsOutputToContain('Nothing to create')
                ->assertSuccessful();
        } finally {
            @unlink($path);
        }
    }

    private function prepare(string $connection): void
    {
        $this->requireConnection($connection);
        $this->connection = $connection;
        $this->dropCreated($connection);
    }

    /**
     * A connection to the created database, as $user (null: the connection's user).
     */
    private function connectAs(string $connection, ?string $user, ?string $password): Connection
    {
        $name = 'dbp_created_'.($user ?? 'admin');
        $config = config("database.connections.{$connection}");
        $config['database'] = self::CREATED;

        if ($user !== null) {
            // CockroachDB runs in insecure mode in the tests: no password.
            [$config['username'], $config['password']] = [$user, $connection === 'crdb' ? '' : $password];
        }

        config(["database.connections.{$name}" => $config]);
        DB::purge($name);

        return DB::connection($name);
    }

    private function assertRefused(callable $statement): void
    {
        try {
            $statement();
        } catch (Throwable $e) {
            $this->assertMatchesRegularExpression('/denied|permission|privilege/i', $e->getMessage());

            return;
        }

        $this->fail('The statement was not refused.');
    }

    private function databaseExists(string $connection): bool
    {
        return (bool) DB::connection($connection)->scalar(in_array($connection, ['pgsql', 'crdb'], true)
            ? 'select count(*) from pg_database where datname = ?'
            : 'select count(*) from information_schema.schemata where schema_name = ?', [self::CREATED]);
    }

    private function dropCreated(string $connection): void
    {
        foreach (['dbp_created_admin', 'dbp_created_dbp_app', $connection.'_app'] as $name) {
            DB::purge($name);
        }

        $db = DB::connection($connection);
        $statements = match ($connection) {
            'pgsql' => ['drop database if exists '.self::CREATED.' with (force)', ...array_map(fn ($user) => "drop role if exists {$user}", self::USERS)],
            'crdb' => ['drop database if exists '.self::CREATED.' cascade', ...array_map(fn ($user) => "drop user if exists {$user}", self::USERS)],
            'matrixone' => [
                'drop database if exists '.self::CREATED,
                ...array_map(fn ($user) => "drop user if exists {$user}", self::USERS),
                ...array_map(fn ($level) => 'drop role if exists '.self::CREATED."_{$level}", ['all', 'readwrite', 'readonly']),
            ],
            default => ['drop database if exists '.self::CREATED, ...array_map(fn ($user) => "drop user if exists '{$user}'@'%'", self::USERS)],
        };

        foreach ($statements as $statement) {
            $db->statement($statement);
        }
    }
}
