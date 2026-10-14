<?php

namespace DbPortable\Tests\Unit;

use DbPortable\Create\CreateOptions;
use DbPortable\Create\Creator;
use DbPortable\Create\Plan;
use DbPortable\Create\Planner;
use DbPortable\Create\ServerFacts;
use DbPortable\Schema\Family;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The statements of db-portable:db:create, per database (tests/Conformance/CreateDatabaseTest runs them).
 */
class CreatePlannerTest extends TestCase
{
    public function test_mysql_creates_the_user_and_grants_the_database(): void
    {
        $plan = $this->plan(Family::MYSQL, new CreateOptions('shop', 'app', "it's", charset: 'utf8mb4', collation: 'utf8mb4_unicode_ci',
            requireSsl: true, connectionLimit: 10, passwordDays: 90));

        $this->assertSame([
            'create database if not exists `shop` character set utf8mb4 collate utf8mb4_unicode_ci',
            "create user if not exists 'app'@'%' identified by 'it''s' require ssl with max_user_connections 10 password expire interval 90 day",
            "grant all privileges on `shop`.* to 'app'@'%'",
        ], $plan->server);
        $this->assertSame([], $plan->warnings);

        $masked = $this->plan(Family::MYSQL, new CreateOptions('shop', 'app', 'secret'), mask: true);
        $this->assertStringContainsString("identified by '********'", $masked->server[1]);
    }

    public function test_mysql_keeps_an_existing_user_password(): void
    {
        $plan = $this->plan(Family::MYSQL, new CreateOptions('shop', 'app', privileges: CreateOptions::READONLY, userHost: '10.0.%'), userExists: true);

        $this->assertSame([
            'create database if not exists `shop`',
            "grant select, show view on `shop`.* to 'app'@'10.0.%'",
        ], $plan->server);

        $plan = $this->plan(Family::MYSQL, new CreateOptions('shop', 'app', 'new'), userExists: true);
        $this->assertSame("alter user 'app'@'%' identified by 'new'", $plan->server[1]);
    }

    public function test_the_admin_itself_gets_no_grants(): void
    {
        $plan = $this->plan(Family::MYSQL, new CreateOptions('shop', 'root', 'x'));

        $this->assertSame(['create database if not exists `shop`'], $plan->server);
    }

    public function test_matrixone_grants_through_a_role(): void
    {
        $plan = $this->plan(Family::MATRIXONE, new CreateOptions('shop', 'app', 'x', privileges: CreateOptions::READWRITE, requireSsl: true));

        $this->assertSame([
            'create database if not exists `shop`',
            'create role if not exists `shop_readwrite`',
            'grant connect on account * to `shop_readwrite`',
            'grant show tables on database `shop` to `shop_readwrite`',
            'grant select, insert, update, delete on table `shop`.* to `shop_readwrite`',
            "create user if not exists `app` identified by 'x' default role `shop_readwrite`",
        ], $plan->server);
        $this->assertSame(['MatrixOne has no require SSL option here; it is ignored.'], $plan->warnings);

        $existing = $this->plan(Family::MATRIXONE, new CreateOptions('shop', 'app', role: 'shop_app'), userExists: true);
        $this->assertSame('grant `shop_app` to `app`', $existing->server[array_key_last($existing->server)]);
        $this->assertStringContainsString('set secondary role all', $existing->warnings[0]);
    }

    public function test_postgres_gives_the_database_to_the_user_with_all(): void
    {
        $plan = $this->plan(Family::POSTGRES, new CreateOptions('shop', 'app', 'x', connectionLimit: 20));

        $this->assertSame([
            "create role \"app\" with login nosuperuser password 'x' nocreatedb nocreaterole connection limit 20",
            'create database "shop" owner "app"',
            'revoke all on database "shop" from public',
        ], $plan->server);
        $this->assertSame([
            'alter schema public owner to "app"',
            'revoke create on schema public from public',
        ], $plan->database);
    }

    public function test_postgres_grants_rows_and_default_privileges(): void
    {
        $plan = $this->plan(Family::POSTGRES, new CreateOptions('shop', 'reader', 'x', privileges: CreateOptions::READONLY, schema: 'app', keepPublic: true));

        $this->assertSame([
            "create role \"reader\" with login nosuperuser password 'x' nocreatedb nocreaterole",
            'create database "shop"',
            'grant connect on database "shop" to "reader"',
        ], $plan->server);
        $this->assertSame([
            'create schema if not exists "app" authorization "postgres"',
            'grant usage on schema "app" to "reader"',
            'grant select on all tables in schema "app" to "reader"',
            'grant select on all sequences in schema "app" to "reader"',
            'alter default privileges for role "postgres" in schema "app" grant select on tables to "reader"',
            'alter default privileges for role "postgres" in schema "app" grant select on sequences to "reader"',
        ], $plan->database);
    }

    public function test_postgres_leaves_an_existing_database_to_its_owner(): void
    {
        $plan = $this->plan(Family::POSTGRES, new CreateOptions('shop', 'app'), userExists: true, databaseExists: true);

        $this->assertSame(['grant all privileges on database "shop" to "app"'], $plan->server);
        $this->assertContains('grant all privileges on schema "public" to "app"', $plan->database);
        $this->assertNotContains('revoke create on schema public from public', $plan->database);
    }

    public function test_a_postgres_admin_without_superuser_joins_the_owner_role(): void
    {
        $plan = $this->plan(Family::POSTGRES, new CreateOptions('shop', 'app', 'x'), superuser: false);

        $this->assertSame('grant "app" to current_user', $plan->server[1]);
    }

    public function test_cockroachdb_ignores_what_it_cannot_do(): void
    {
        $plan = $this->plan(Family::CRDB, new CreateOptions('shop', 'app', 'x', connectionLimit: 5, collation: 'de'));

        $this->assertSame([
            "create user if not exists \"app\" with login password 'x' nocreatedb nocreaterole",
            'create database if not exists "shop" owner "app"',
            'revoke all on database "shop" from public',
        ], $plan->server);
        $this->assertSame(['revoke create on schema public from public'], $plan->database);
        $this->assertCount(2, $plan->warnings);
    }

    public function test_names_are_quoted_and_validated(): void
    {
        $plan = $this->plan(Family::POSTGRES, new CreateOptions('my"db', 'a`b', 'x', privileges: CreateOptions::READWRITE));
        $this->assertSame('create database "my""db"', $plan->server[1]);

        $plan = $this->plan(Family::MYSQL, new CreateOptions('my`db'));
        $this->assertSame('create database if not exists `my``db`', $plan->server[0]);

        $this->expectException(InvalidArgumentException::class);
        new CreateOptions('shop', charset: 'utf8; drop');
    }

    public function test_denied_access_is_recognized(): void
    {
        $denied = new PDOException('SQLSTATE[42000]: 1044 Access denied for user');
        $denied->errorInfo = ['42000', 1044, 'Access denied'];

        $this->assertTrue(Creator::deniedAccess($denied));
        $this->assertTrue(Creator::deniedAccess(new RuntimeException('ERROR: permission denied to create database')));
        $this->assertTrue(Creator::deniedAccess(new RuntimeException('internal error: do not have privilege to execute the statement')));
        $this->assertFalse(Creator::deniedAccess(new RuntimeException('Connection refused')));
    }

    private function plan(string $kind, CreateOptions $options, bool $userExists = false, bool $databaseExists = false, bool $superuser = true, bool $mask = false): Plan
    {
        $admin = $kind === Family::POSTGRES ? 'postgres' : 'root';
        $quote = fn (string $value): string => "'".str_replace("'", "''", $value)."'";

        return (new Planner($kind, $options, new ServerFacts($admin, $databaseExists, $userExists, $superuser), $quote))->plan($mask);
    }
}
