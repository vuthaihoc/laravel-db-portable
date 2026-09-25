# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Project Overview

`vuthaihoc/laravel-db-portable`: query builder macros that compile per database family (PostgreSQL/CockroachDB, MySQL/MariaDB/MatrixOne, SQLite) and the `db-portable:scan`, `db-portable:audit`, `db-portable:copy` commands for switching an application between databases. PHP 8.2+, Laravel 12 and 13.

## Commands

- `composer test` — PHPUnit. `Unit` needs no server; `Conformance` runs on SQLite, MatrixOne (127.0.0.1:6001, root/111) and CockroachDB (127.0.0.1:26258, `docker run -d --name crdb-test -p 127.0.0.1:26258:26257 cockroachdb/cockroach:v25.3.2 start-single-node --insecure --store=type=mem,size=1GiB`); unreachable servers are skipped.
- `composer phpstan` (level 8), `composer cs` / `composer cs:fix` (Pint).
- Dev dependencies `vuthaihoc/laravel-matrixone` (`^1.0@beta`) and `vuthaihoc/cockroachdb-laravel` (`^2.2`) come from Packagist; their sources live in `../laravel-matrixone` and `../crdb2025`.

## Architecture

- `src/Dialects/` — `Dialect::for($grammar)` picks `PostgresDialect` (also CockroachDB), `MySqlDialect` (also MariaDB, MatrixOne) or `SQLiteDialect` by grammar class. JSON text extraction reuses the grammar's own `wrap('col->key')`; dialects add casts, NULLS LAST, JSON increments, char length.
- `src/DbPortableServiceProvider.php` — registers the macros on the query builder, plus Eloquent builder macros for the ones returning values (aggregates) or touching `updated_at` (`incrementJson`).
- `src/Portable.php` — the same expressions as `Expression` objects for raw query parts.
- `src/Schema/` — Blueprint macros (`jsonIndex`, `jsonWithDefault`, `descIndex`, `forDriver`, and `jsonKeyIndex`, `coveringIndex`, `trigramIndex`, `partialIndex`, which add a `portableIndex` command compiled by the `compilePortableIndex` schema grammar macro through `IndexCompiler`) and `Schema::forDriver()`; `Family` resolves a connection's family from its schema grammar, `Unsupported::skip()` logs (or throws with `db-portable.strict`).
- `src/Scan/` — token-based scanner of PHP string literals; `Rule::defaults()` lists the constructs and the families they break on (`mysql`, `matrixone`, `pgsql`, `sqlite`). Weak patterns (`sqlOnly`) only match literals that look like SQL or are the first argument of a raw SQL method.
- `src/Audit/Auditor.php`, `src/Copy/Copier.php` — the audit and copy logic behind the commands.

## Rules

- Every macro or dialect change needs a conformance test that passes on all three databases.
- MatrixOne ignores a DESC key that follows a boolean ORDER BY key: the MySQL dialect sorts `desc` nulls last with a plain `x desc`.
- Code comments and docs in English.
