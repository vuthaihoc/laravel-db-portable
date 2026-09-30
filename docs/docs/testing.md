# Testing & Conformance

`vuthaihoc/laravel-db-portable` features an extensive test suite divided into **Unit** tests (fast, requiring no database servers) and **Conformance** tests (run across real database instances).

---

## Running Unit Tests

Unit tests verify grammar dialect compilation, the code scanner, value mapping, and SQL generation without requiring database connections:

```bash
composer test -- --testsuite=Unit
```

---

## Running the Conformance Suite

The conformance suite runs identical assertions across **SQLite**, **CockroachDB**, **MatrixOne**, **PostgreSQL**, and **MySQL**.

By default, any database server that is unreachable is skipped automatically. In CI (or locally when `DB_PORTABLE_REQUIRE_SERVERS=1` is set), missing servers will cause the tests to fail.

### 1. Start Test Database Containers

You can run the required database engines using Docker:

```bash
# CockroachDB (v26.2.6)
docker run -d --name crdb-test -p 127.0.0.1:26258:26257 \
    cockroachdb/cockroach:v26.2.6 start-single-node --insecure --store=type=mem,size=1GiB

# MatrixOne (4.2.4+)
docker run -d --name mo-test --hostname matrixone -p 127.0.0.1:6001:6001 \
    matrixorigin/matrixone:4.2.4

# PostgreSQL 17
docker run -d --name pgsql-test -p 127.0.0.1:5433:5432 \
    -e POSTGRES_PASSWORD=secret postgres:17

# MySQL 8.4
docker run -d --name mysql-test -p 127.0.0.1:3307:3306 \
    -e MYSQL_ROOT_PASSWORD=secret mysql:8.4
```

### 2. Run All Tests

```bash
composer test
```

To run only the conformance suite and enforce that all servers are online:

```bash
DB_PORTABLE_REQUIRE_SERVERS=1 vendor/bin/phpunit --testsuite=Conformance
```

---

## Environment Variables

Connection settings can be overridden in `phpunit.xml` or via environment variables:

| Variable | Default | Description |
|---|---|---|
| `DB_PORTABLE_REQUIRE_SERVERS` | `0` | When `1`, missing database servers fail tests instead of skipping. |
| `MATRIXONE_HOST` / `_PORT` | `127.0.0.1` / `6001` | MatrixOne connection endpoint (user: `root`, pass: `111`) |
| `CRDB_HOST` / `_PORT` | `127.0.0.1` / `26258` | CockroachDB connection endpoint (user: `root`, pass: empty) |
| `PGSQL_HOST` / `_PORT` | `127.0.0.1` / `5433` | PostgreSQL connection endpoint (user: `postgres`, pass: `secret`) |
| `MYSQL_HOST` / `_PORT` | `127.0.0.1` / `3307` | MySQL connection endpoint (user: `root`, pass: `secret`) |

---

## Static Analysis & Code Style

The package enforces strict typing and code style:

```bash
# PHPStan (Level 8)
composer phpstan

# Laravel Pint (Code Style)
composer cs

# Automatically fix code style
composer cs:fix
```
