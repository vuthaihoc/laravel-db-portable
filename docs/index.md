---
layout: home

hero:
  name: "Laravel DB Portable"
  text: "Write Once. Run on Any Database."
  tagline: "Cross-database query builder macros, portable schema tools, migration commands, and parallel database mirrors for Laravel 12 & 13."
  actions:
    - theme: brand
      text: Get Started
      link: /docs/installation
    - theme: alt
      text: View on GitHub
      link: https://github.com/vuthaihoc/laravel-db-portable

features:
  - title: Portable Query Builder
    details: Numeric JSON operations, NULLS LAST sorting, JSON increment/decrement, and JSON aggregates that compile correctly per database engine.
  - title: Analytics & ROLLUP
    details: Conditional aggregates (selectCountWhere, selectSumWhere) and multidimensional rollup subtotals across MySQL, PostgreSQL, CockroachDB, and SQLite.
  - title: Schema & Index Macros
    details: jsonWithDefault, jsonIndex, descIndex, coveringIndex, trigramIndex, and partialIndex across MySQL, PostgreSQL, CockroachDB, and MatrixOne.
  - title: Database Switching Tools
    details: Scan code for incompatible SQL, audit data fits against target schemas, and safely copy data with dependency ordering and sequence resets.
  - title: Historical & Stale Reads
    details: asOfTime and follower reads (readStale) for distributed databases like CockroachDB and MatrixOne with graceful fallback warnings.
  - title: Parallel Database Mirrors
    details: Keep read-only copies of chosen tables in specialised databases (e.g. MatrixOne for analytics, XTDB for audit), synchronized via queues.
---

## Overview

When developing Laravel applications across different database engines—such as moving from CockroachDB to MatrixOne, or supporting MySQL, PostgreSQL, and SQLite simultaneously—developers encounter subtle incompatibilities:

- **JSON values are extracted as text**: Numeric comparisons like `flags->ratio > 0.8` or ordering by numbers fail or sort alphabetically (`"10"` before `"2"`).
- **Sorting nulls**: MySQL has no `NULLS LAST` syntax; PostgreSQL sorts nulls first by default in descending order.
- **Aggregates and Rollups**: `group by ... with rollup` vs `group by rollup (...)` vs `union all`.
- **Search and Indexes**: GIN indexes, trigram similarity, unaccent matching, and expression indexes differ dramatically.

**Laravel DB Portable** bridges these differences. It compiles query macros and schema blueprints to the exact syntax required by the active database connection.

---

## Installation

Install the package via Composer:

```bash
composer require vuthaihoc/laravel-db-portable
```

The package requires **PHP 8.2+** and **Laravel 12 or 13**. The service provider is auto-discovered.

### Supported Databases & Drivers

| Database | Family | Driver Package | Driver Key |
|----------|--------|----------------|------------|
| **PostgreSQL** (14 - 17+) | `pgsql` | Built-in Laravel driver | `pgsql` |
| **CockroachDB** (v25 - v26+) | `pgsql` | [vuthaihoc/cockroachdb-laravel](https://github.com/vuthaihoc/crdb2025) (`^2.5`) | `crdb` |
| **MySQL** (8.0 - 8.4+) | `mysql` | Built-in Laravel driver | `mysql` |
| **MariaDB** (10.6 - 11+) | `mysql` | Built-in Laravel driver | `mariadb` |
| **MatrixOne** (4.2+) | `mysql` | [vuthaihoc/laravel-matrixone](https://github.com/vuthaihoc/laravel-matrixone) (`^1.2`) | `matrixone` |
| **SQLite** (3.35+) | `sqlite` | Built-in Laravel driver | `sqlite` |
| **XTDB** *(Experimental)* | `xtdb` | [vuthaihoc/laravel-xtdb2](https://github.com/vuthaihoc/laravel-xtdb2) | `xtdb` |

---

## Strict Mode

By default, when a database engine does not support a specific feature (for example, expression indexes on MariaDB/MatrixOne, or time-travel reads on SQLite), the package executes a safe fallback and logs a warning once per message:

```text
[db-portable] asOfTime() is not supported on SQLite. Skipped.
```

To enforce strict compatibility during automated tests or CI, enable strict mode in `config/db-portable.php` or dynamically:

```php
config(['db-portable.strict' => true]);
```

In strict mode, unsupported operations immediately throw a `\RuntimeException` instead of logging and falling back.

---

## Publishing Configuration

Publish the configuration file using Artisan:

```bash
php artisan vendor:publish --tag=db-portable-config
```

The file `config/db-portable.php` contains settings for strict mode, mirrors, queue connections, and model-specific overrides.
