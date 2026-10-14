# Creating Databases & Users

`db-portable:db:create` (alias `db:create`) creates the database of a connection and, optionally, a user limited to it, with the same command on every supported server: PostgreSQL, CockroachDB, MySQL, MariaDB, MatrixOne and SQLite (the file).

```bash
# The default connection's database, as its own user (asks for an admin if refused)
php artisan db:create

# Another connection, with an admin user and a read-only user
php artisan db:create matrixone --admin-user=root --user=reporting --privileges=readonly

# Print the statements without running them (the password is masked)
php artisan db:create pgsql --user=app --pretend
```

---

## Who creates the database

The command connects as an **admin**: a user allowed to create databases and users.

1. `--admin-user` / `--admin-password` when given. When only `--admin-user` is given, its password is asked (hidden).
2. Else the connection's own `username` / `password`.
3. When the server refuses that user (it cannot log in, e.g. the user does not exist yet, or it lacks a privilege while running the statements), the command asks for an admin user and password, up to three times. With `--no-interaction`, it fails and tells you to pass `--admin-user`.

The admin connects to the server, not to the connection's database, which may not exist yet: on PostgreSQL to `postgres`, on CockroachDB to `defaultdb` (change it with `--maintenance-database`), on the MySQL family to no database.

When the connection's user could not create the database and an admin did, the connection's user is granted the database: after `php artisan db:create`, the application connects with its `.env` credentials.

## The user

| Situation | User | Password |
|---|---|---|
| `--user=name` | `name` | `--password`; else asked (hidden; empty: generated); `--password=` (empty): generated |
| No `--user`, the connection's user is not the admin | the connection's user (asked, with it as the default) | the connection's password |
| No `--user`, the connection's user is the admin | asked (empty: none) | asked |
| `--no-user` | none: only the database | — |
| The user exists | granted the database | unchanged, unless `--password` is given |

Without a terminal (`--no-interaction`), a new user without a password gets a generated one. A generated password is printed once at the end, as `.env` lines:

```text
  DB_DATABASE=shop
  DB_USERNAME=shop_app
  DB_PASSWORD=ESKBNu4Y4w7XKZKdOFD2docR7ZFdhU3v
```

The statements holding a password run on the PDO directly: query listeners (Telescope, Debugbar, query logs) do not receive them.

### Privileges

`--privileges` (asked when a user is created interactively; default `all`):

| Level | PostgreSQL, CockroachDB | MySQL, MariaDB | MatrixOne (role `<database>_<level>`) |
|---|---|---|---|
| `all` (migrations included) | owns the new database | `all privileges on db.*` | `all` on the database and its tables |
| `readwrite` (rows only) | `select, insert, update, delete` on tables, `usage, select, update` on sequences | `select, insert, update, delete, execute, create temporary tables, lock tables, show view` | `show tables`, `select, insert, update, delete` |
| `readonly` | `select` on tables and sequences | `select, show view` | `show tables`, `select` |

- **PostgreSQL family**: the grants cover the tables existing in the schema (`--schema`, default `public`) and, through `alter default privileges`, the tables the database owner creates later. The owner is `--owner`, else the user with `all`, else the admin: run the migrations as the owner.
- **MySQL family**: `db.*` covers the tables created later.
- **MatrixOne** grants privileges to roles: the command creates the role (`--role` to name it), grants it, and makes it the new user's default role. An existing user is granted the role but keeps its default role: connect as `account:user:role`, or run `set secondary role all`.

## Security options

| Option | PostgreSQL | CockroachDB | MySQL, MariaDB | MatrixOne |
|---|---|---|---|---|
| (always) the user cannot create databases or roles | `nosuperuser nocreatedb nocreaterole` | `nocreatedb nocreaterole` | privileges on its database only | its role only |
| PUBLIC loses access to the new database (`--public` keeps it) | `revoke all on database ... from public`, `revoke create on schema public from public` | same | — | — |
| `--require-ssl` | warning: a `hostssl` line in `pg_hba.conf` | warning: the `server.host_based_authentication.configuration` cluster setting | `require ssl` | warning |
| `--connection-limit=N` | `connection limit N` | warning | `with max_user_connections N` | warning |
| `--password-days=N` | `valid until` (now + N days) | `valid until` | `password expire interval N day` (every N days) | warning |
| `--user-host=10.0.%` | — | — | the host part of the account (default `%`) | — |

What a server cannot do is printed as a warning, and the rest runs. CockroachDB in insecure mode refuses passwords: the user is created without one.

## Database options

- `--database`: the name (default: the connection's `database`).
- `--charset`, `--collation`: on MySQL and MariaDB, default to the connection's `charset` and `collation`. On PostgreSQL, `--charset` is the `encoding` and `--collation` sets `lc_collate` and `lc_ctype` from `template0`. MatrixOne and CockroachDB ignore them.
- On PostgreSQL, a non-superuser admin (with `CREATEDB` and `CREATEROLE`, as on managed services) is first granted the owner role: PostgreSQL 16 only lets it give a database to a role it can switch to.

## Running again

The command reads what exists before planning: an existing database is kept (with its owner and PUBLIC access), an existing user keeps its password, and the grants are applied again. Before running, it prints the statements (passwords masked) and asks for confirmation (`--force` skips it).

```text
   INFO  As [postgres]:

  create role "shop_app" with login nosuperuser password '********' nocreatedb nocreaterole;
  create database "shop";
  revoke all on database "shop" from public;
  grant connect, temporary on database "shop" to "shop_app";
  -- in [shop]
  revoke create on schema public from public;
  grant usage on schema "public" to "shop_app";
  grant select, insert, update, delete on all tables in schema "public" to "shop_app";
  grant usage, select, update on all sequences in schema "public" to "shop_app";
  alter default privileges for role "postgres" in schema "public" grant select, insert, update, delete on tables to "shop_app";
  alter default privileges for role "postgres" in schema "public" grant usage, select, update on sequences to "shop_app";
```

## SQLite

The database file is created (its directory must exist); an in-memory database needs nothing. User options are ignored with a warning.

## In code

```php
use DbPortable\Create\CreateOptions;
use DbPortable\Create\Creator;

$creator = new Creator('pgsql');
$creator->connectAs('postgres', $adminPassword);

$options = new CreateOptions('shop', 'shop_app', $password, privileges: CreateOptions::READWRITE);
$plan = $creator->planner($options)->plan();   // ->plan(mask: true) to print it

$creator->run($plan, $options->database);
$creator->disconnect();
```
