# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Project Overview

`vuthaihoc/laravel-db-portable`: query builder macros that compile per database family (PostgreSQL/CockroachDB, MySQL/MariaDB/MatrixOne, SQLite) and the `db-portable:scan`, `db-portable:audit`, `db-portable:copy` commands for switching an application between databases. PHP 8.2+, Laravel 12 and 13.

## Commands

- `composer test` — PHPUnit. `Unit` needs no server; `Conformance` runs on SQLite, MatrixOne (127.0.0.1:6001, root/111) CockroachDB (127.0.0.1:26258, `docker run -d --name crdb-test -p 127.0.0.1:26258:26257 cockroachdb/cockroach:v26.2.6 start-single-node --insecure --store=type=mem,size=1GiB`), PostgreSQL (127.0.0.1:5433, postgres/secret, `postgres:17`) and MySQL (127.0.0.1:3307, root/secret, `mysql:8.4`); unreachable servers are skipped (`DB_PORTABLE_REQUIRE_SERVERS=1` fails instead).
- `composer phpstan` (level 8), `composer cs` / `composer cs:fix` (Pint).
- Dev dependencies `vuthaihoc/laravel-matrixone` (`^1.2`) and `vuthaihoc/cockroachdb-laravel` (`^2.5`) come from Packagist; their sources live in `../laravel-matrixone` and `../crdb2025`.

## Architecture

- `src/Dialects/` — `Dialect::for($grammar)` picks `PostgresDialect` (also CockroachDB), `MySqlDialect` (also MariaDB, MatrixOne) or `SQLiteDialect` by grammar class. JSON text extraction reuses the grammar's own `wrap('col->key')`; dialects add casts, NULLS LAST, JSON increments, char length.
- `src/DbPortableServiceProvider.php` — registers the macros on the query builder, plus Eloquent builder macros for the ones returning values (aggregates) or touching `updated_at` (`incrementJson`).
- `src/Portable.php` — the same expressions as `Expression` objects for raw query parts.
- `src/Query/AnalyticsMacros.php` — `selectCountWhere`, `selectSumWhere`, `selectAggregateWhere` (CASE WHEN everywhere) and `rollup()` (WITH ROLLUP on the MySQL family, ROLLUP on PostgreSQL, UNION ALL per grouping level on CockroachDB and SQLite).
- `src/Search/` + `src/Console/SearchIndexesCommand.php` — `db-portable:search-indexes`: indexes of Scout models from `#[SearchUsingFullText]`, `#[SearchUsingPrefix]`, `#[SearchUsingFuzzy]` and `toSearchableEmbedding()`; existing indexes found by definition (`pg_indexes`) on the PostgreSQL family, by `getIndexes()` elsewhere; `--migration` writes the migration.
- `src/Query/SearchMacros.php` — `whereStartsWith()`, `whereContains()`, `whereSimilar()`, `selectSimilarity()`, `orderBySimilarity()`, `suggest()`, `searchFullText()`, `*FullTextRelevance()`. The drivers implement them (cockroachdb-laravel: trigrams and ts_rank; laravel-matrixone: prefixes and MATCH); the macros only run for methods the builder lacks (PostgreSQL, MySQL, SQLite, similarity on MatrixOne), with a warning where they fall back.
- `src/Query/HistoricalReadMacros.php` — `readStale()`, `asOfTime()`, `readCurrent()` delegate to the drivers (`followerRead`/`asOfSystemTime`/`withoutHistoricalRead` of cockroachdb-laravel, `asOfTimestamp`/`timeTravel` of laravel-matrixone); other databases skip with a warning.
- XTDB (laravel-xtdb2) is recognized only (`Family::XTDB`, `isXtdb()`): experimental until XTDB 2.2 is released; no macro is adapted and it is not a conformance connection. `tests/Stubs/XtdbConnection.php` stands in for its connection class.
- `src/Schema/` — Blueprint macros (`jsonIndex`, `jsonWithDefault`, `descIndex`, `forDriver`, and `jsonKeyIndex`, `coveringIndex`, `trigramIndex`, `partialIndex`, which add a `portableIndex` command compiled by the `compilePortableIndex` schema grammar macro through `IndexCompiler`) and `Schema::forDriver()`; `Family` resolves a connection's family from its schema grammar, `Unsupported::skip()` logs (or throws with `db-portable.strict`).
- `src/Scan/` — token-based scanner of PHP string literals; `Rule::defaults()` lists the constructs and the families they break on (`mysql`, `matrixone`, `pgsql`, `sqlite`). Weak patterns (`sqlOnly`) only match literals that look like SQL or are the first argument of a raw SQL method.
- `src/Audit/Auditor.php`, `src/Copy/Copier.php` — the audit and copy logic behind the commands.

## Rules

- Every macro or dialect change needs a conformance test that passes on every connection (SQLite, MatrixOne, CockroachDB, PostgreSQL, MySQL).
- MatrixOne ignores a DESC key that follows a boolean ORDER BY key: the MySQL dialect sorts `desc` nulls last with a plain `x desc`.
- Code comments and docs in English.
