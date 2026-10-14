<?php

namespace DbPortable\Create;

use Closure;
use DbPortable\Schema\Family;
use InvalidArgumentException;

/**
 * The statements creating a database and a user limited to it, per database:
 * pgsql, crdb (CockroachDB), mysql (MySQL, MariaDB) or matrixone. Pure: the
 * server's state comes from ServerFacts, string literals from $quote.
 */
final class Planner
{
    private const MASK = "'********'";

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param  Closure(string): string  $quote  Quotes a string literal (the connection's escape()).
     */
    public function __construct(
        private readonly string $kind,
        private readonly CreateOptions $options,
        private readonly ServerFacts $facts,
        private readonly Closure $quote,
    ) {
        if (! in_array($kind, [Family::POSTGRES, Family::CRDB, Family::MYSQL, Family::MATRIXONE], true)) {
            throw new InvalidArgumentException("db-portable:db:create does not support [{$kind}].");
        }
    }

    /**
     * @param  bool  $mask  Hide the password, to print the plan.
     */
    public function plan(bool $mask = false): Plan
    {
        $this->warnings = [];

        return match ($this->kind) {
            Family::MYSQL => $this->mysql($mask),
            Family::MATRIXONE => $this->matrixOne($mask),
            default => $this->postgres($mask),
        };
    }

    /**
     * The user the plan creates or grants: not the admin itself, which already has its privileges.
     */
    public function user(): ?string
    {
        return $this->options->user !== null && $this->options->user !== $this->facts->currentUser
            ? $this->options->user
            : null;
    }

    private function mysql(bool $mask): Plan
    {
        $o = $this->options;
        $database = $this->mysqlId($o->database);
        $statements = ["create database if not exists {$database}"
            .($o->charset !== null ? " character set {$o->charset}" : '')
            .($o->collation !== null ? " collate {$o->collation}" : '')];

        $this->ignore(['owner' => $o->owner, 'role' => $o->role, 'schema' => $o->schema], 'MySQL');

        if (($user = $this->user()) !== null) {
            $account = $this->literal($user).'@'.$this->literal($o->userHost);
            $attributes = ($o->requireSsl ? ' require ssl' : '')
                .($o->connectionLimit !== null ? " with max_user_connections {$o->connectionLimit}" : '')
                .($o->passwordDays !== null ? " password expire interval {$o->passwordDays} day" : '');

            if (! $this->facts->userExists) {
                $statements[] = "create user if not exists {$account}".$this->mysqlPassword($mask).$attributes;
            } elseif ($o->password !== null || $attributes !== '') {
                $statements[] = "alter user {$account}".$this->mysqlPassword($mask).$attributes;
            }

            $statements[] = 'grant '.match ($o->privileges) {
                CreateOptions::ALL => 'all privileges',
                CreateOptions::READWRITE => 'select, insert, update, delete, execute, create temporary tables, lock tables, show view',
                default => 'select, show view',
            }." on {$database}.* to {$account}";
        }

        return new Plan($statements, [], $this->warnings);
    }

    private function mysqlPassword(bool $mask): string
    {
        if ($this->options->password === null) {
            if (! $this->facts->userExists) {
                $this->warnings[] = "The user [{$this->options->user}] has no password.";
            }

            return '';
        }

        return ' identified by '.$this->password($mask);
    }

    /**
     * MatrixOne grants privileges to roles: a role per database and privileges,
     * the user's default role.
     */
    private function matrixOne(bool $mask): Plan
    {
        $o = $this->options;
        $database = $this->mysqlId($o->database);
        $statements = ["create database if not exists {$database}"];

        $this->ignore([
            'charset' => $o->charset, 'collation' => $o->collation, 'owner' => $o->owner, 'schema' => $o->schema,
            'require SSL' => $o->requireSsl ?: null, 'connection limit' => $o->connectionLimit, 'password expiry' => $o->passwordDays,
        ], 'MatrixOne');

        if (($user = $this->user()) !== null) {
            $roleName = $o->role ?? $o->database.'_'.$o->privileges;
            $role = $this->mysqlId($roleName);
            $statements[] = "create role if not exists {$role}";
            $statements[] = "grant connect on account * to {$role}";

            if ($o->privileges === CreateOptions::ALL) {
                $statements[] = "grant all on database {$database} to {$role}";
                $statements[] = "grant all on table {$database}.* to {$role}";
            } else {
                $statements[] = "grant show tables on database {$database} to {$role}";
                $statements[] = 'grant '.($o->privileges === CreateOptions::READWRITE ? 'select, insert, update, delete' : 'select')
                    ." on table {$database}.* to {$role}";
            }

            $account = $this->mysqlId($user);

            if (! $this->facts->userExists) {
                $statements[] = "create user if not exists {$account}"
                    .($o->password !== null ? ' identified by '.$this->password($mask) : '')
                    ." default role {$role}";

                if ($o->password === null) {
                    $this->warnings[] = "The user [{$user}] has no password.";
                }
            } else {
                if ($o->password !== null) {
                    $statements[] = "alter user {$account} identified by ".$this->password($mask);
                }

                $statements[] = "grant {$role} to {$account}";
                $this->warnings[] = "The existing user [{$user}] keeps its default role: connect as [<account>:{$user}:{$roleName}], or run \"set secondary role all\" after connecting.";
            }
        }

        return new Plan($statements, [], $this->warnings);
    }

    /**
     * PostgreSQL and CockroachDB: the user is a login role; with "all" it owns a
     * database created for it, else it is granted rows in the schema, now and in
     * the tables the database owner creates later (default privileges).
     */
    private function postgres(bool $mask): Plan
    {
        $o = $this->options;
        $crdb = $this->kind === Family::CRDB;
        $user = $this->user();
        $creating = ! $this->facts->databaseExists;
        $database = $this->pgId($o->database);
        $server = [];
        $inDatabase = [];

        // The owner given, or the user with "all"; an existing database keeps its owner unless one is given.
        $owner = $o->owner ?? ($creating && $user !== null && $o->privileges === CreateOptions::ALL ? $user : null);
        $databaseOwner = $owner ?? ($creating ? $this->facts->currentUser : null);

        $this->ignore(['role' => $o->role], 'the PostgreSQL family');

        if ($o->requireSsl) {
            $this->warnings[] = $crdb
                ? 'CockroachDB requires SSL per user through the server.host_based_authentication.configuration cluster setting.'
                : 'PostgreSQL requires SSL per user through pg_hba.conf (a hostssl line).';
        }

        if ($user !== null) {
            $role = $this->pgId($user);
            $attributes = ' nocreatedb nocreaterole'
                .($o->connectionLimit !== null && ! $crdb ? " connection limit {$o->connectionLimit}" : '')
                .($o->passwordDays !== null ? ' valid until '.$this->literal(gmdate('Y-m-d H:i:s+00', time() + $o->passwordDays * 86400)) : '');

            if ($crdb && $o->connectionLimit !== null) {
                $this->warnings[] = 'CockroachDB has no connection limit per user; the connection limit is ignored.';
            }

            if (! $this->facts->userExists) {
                $server[] = ($crdb ? "create user if not exists {$role} with login" : "create role {$role} with login nosuperuser")
                    .($o->password !== null ? ' password '.$this->password($mask) : '')
                    .$attributes;

                if ($o->password === null) {
                    $this->warnings[] = "The user [{$user}] has no password.";
                }
            } elseif ($o->password !== null || $o->connectionLimit !== null || $o->passwordDays !== null) {
                $server[] = "alter role {$role} with login"
                    .($o->password !== null ? ' password '.$this->password($mask) : '')
                    .$attributes;
            }
        }

        // A non-superuser can only give a database to a role it can SET ROLE to (PostgreSQL 16).
        if (! $crdb && $owner !== null && $owner !== $this->facts->currentUser && ! $this->facts->superuser) {
            $server[] = 'grant '.$this->pgId($owner).' to current_user';
        }

        if ($creating) {
            $server[] = ($crdb ? "create database if not exists {$database}" : "create database {$database}")
                .($owner !== null ? ' owner '.$this->pgId($owner) : '')
                .$this->pgEncoding($crdb);

            // Before PostgreSQL 15, the public schema belongs to the bootstrap superuser and anyone can create in it.
            if (! $crdb && $owner !== null) {
                $inDatabase[] = 'alter schema public owner to '.$this->pgId($owner);
            }

            if (! $o->keepPublic) {
                $server[] = "revoke all on database {$database} from public";
                $inDatabase[] = 'revoke create on schema public from public';
            }
        } elseif ($owner !== null) {
            $server[] = "alter database {$database} owner to ".$this->pgId($owner);
        }

        $schemaName = $o->schema ?? 'public';
        $schema = $this->pgId($schemaName);

        if ($schemaName !== 'public') {
            $inDatabase[] = "create schema if not exists {$schema}".($databaseOwner !== null ? ' authorization '.$this->pgId($databaseOwner) : '');
        }

        if ($user !== null && $user !== $databaseOwner) {
            $role = $this->pgId($user);
            $creator = $this->pgId($databaseOwner ?? $this->facts->currentUser);
            [$onDatabase, $tables, $sequences] = match ($o->privileges) {
                CreateOptions::ALL => ['all privileges', 'all privileges', 'all privileges'],
                // CockroachDB 25 has no TEMPORARY privilege (its temporary tables are experimental anyway).
                CreateOptions::READWRITE => [$crdb ? 'connect' : 'connect, temporary', 'select, insert, update, delete', 'usage, select, update'],
                default => ['connect', 'select', 'select'],
            };

            if ($crdb && $o->privileges === CreateOptions::READWRITE) {
                $this->warnings[] = "CockroachDB: [{$user}] can connect to [{$o->database}] but not create temporary tables (no TEMPORARY privilege before CockroachDB 26; its temporary tables are experimental).";
            }

            $server[] = "grant {$onDatabase} on database {$database} to {$role}";
            $inDatabase[] = 'grant '.($o->privileges === CreateOptions::ALL ? 'all privileges' : 'usage')." on schema {$schema} to {$role}";
            $inDatabase[] = "grant {$tables} on all tables in schema {$schema} to {$role}";
            $inDatabase[] = "grant {$sequences} on all sequences in schema {$schema} to {$role}";
            $inDatabase[] = "alter default privileges for role {$creator} in schema {$schema} grant {$tables} on tables to {$role}";
            $inDatabase[] = "alter default privileges for role {$creator} in schema {$schema} grant {$sequences} on sequences to {$role}";
        }

        return new Plan($server, $inDatabase, $this->warnings);
    }

    private function pgEncoding(bool $crdb): string
    {
        $o = $this->options;

        if ($crdb) {
            $this->ignore(['collation' => $o->collation], 'CockroachDB');

            return '';
        }

        // LC_COLLATE needs template0, whose locale is not copied.
        return ($o->charset !== null ? ' encoding '.$this->literal($o->charset) : '')
            .($o->collation !== null ? ' lc_collate '.$this->literal($o->collation).' lc_ctype '.$this->literal($o->collation).' template template0' : '');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function ignore(array $options, string $database): void
    {
        foreach ($options as $name => $value) {
            if ($value !== null) {
                $this->warnings[] = "{$database} has no {$name} option here; it is ignored.";
            }
        }
    }

    private function password(bool $mask): string
    {
        return $mask ? self::MASK : $this->literal((string) $this->options->password);
    }

    private function literal(string $value): string
    {
        return ($this->quote)($value);
    }

    private function mysqlId(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private function pgId(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
