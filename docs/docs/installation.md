# Installation & Overview

`vuthaihoc/laravel-db-portable` provides query builder helpers, schema macros, Artisan migration tools, and parallel database mirrors that compile per database family:
- **PostgreSQL / CockroachDB** (`pgsql`)
- **MySQL / MariaDB / MatrixOne** (`mysql`)
- **SQLite** (`sqlite`)
- **XTDB** (`xtdb`, experimental)

---

## Requirements

- **PHP**: 8.2 or 8.3 / 8.4+
- **Laravel**: 12.x or 13.x

---

## Installation

Install via Composer:

```bash
composer require vuthaihoc/laravel-db-portable
```

The service provider `DbPortable\DbPortableServiceProvider` is automatically registered by Laravel's package discovery.

### Supported Drivers & Packages

| Database | Driver | Package | Connection Name |
|----------|--------|---------|-----------------|
| **PostgreSQL** (14+) | Built-in | `illuminate/database` | `pgsql` |
| **CockroachDB** (v25+) | [vuthaihoc/cockroachdb-laravel](https://github.com/vuthaihoc/crdb2025) (`^2.5`) | `vuthaihoc/cockroachdb-laravel` | `crdb` |
| **MySQL** (8.0+) | Built-in | `illuminate/database` | `mysql` |
| **MariaDB** (10.6+) | Built-in | `illuminate/database` | `mariadb` |
| **MatrixOne** (4.2+) | [vuthaihoc/laravel-matrixone](https://github.com/vuthaihoc/laravel-matrixone) (`^1.2`) | `vuthaihoc/laravel-matrixone` | `matrixone` |
| **SQLite** (3.35+) | Built-in | `illuminate/database` | `sqlite` |
| **XTDB** (2.x) | [vuthaihoc/laravel-xtdb2](https://github.com/vuthaihoc/laravel-xtdb2) *(Experimental)* | `vuthaihoc/laravel-xtdb2` | `xtdb` |

---

## Configuration

Publish the package configuration:

```bash
php artisan vendor:publish --tag=db-portable-config
```

This creates `config/db-portable.php`:

```php
return [
    /*
    |--------------------------------------------------------------------------
    | Strict Mode
    |--------------------------------------------------------------------------
    |
    | When false (default), unsupported database features log a single warning
    | and fall back gracefully. When true, unsupported features throw a
    | \RuntimeException. Recommended for test suites and CI.
    |
    */
    'strict' => env('DB_PORTABLE_STRICT', false),

    /*
    |--------------------------------------------------------------------------
    | Full-Text Search on SQLite
    |--------------------------------------------------------------------------
    |
    | whereFullText() and $table->fullText() on SQLite, with FTS5 tables kept
    | up to date by triggers: Laravel's SQLite grammars are replaced by
    | subclasses on each SQLite connection (the driver stays Laravel's).
    |
    */
    'sqlite_fulltext' => true,

    /*
    |--------------------------------------------------------------------------
    | Parallel Database Mirrors
    |--------------------------------------------------------------------------
    |
    | Configuration for synchronizing tables in parallel databases via queue.
    |
    */
    'mirrors' => [
        'enabled' => env('DB_PORTABLE_MIRRORS_ENABLED', true),
        'models' => [
            // App\Models\Order::class => false, // disable mirroring for specific model
        ],
        // Named mirrors:
        // 'analytics' => [
        //     'connection' => env('DB_PORTABLE_ANALYTICS_CONNECTION', 'matrixone'),
        //     'queue_connection' => null,
        //     'queue' => 'mirrors',
        //     'versions' => 'latest', // 'latest' or 'all'
        //     'erase_on_force_delete' => true,
        //     'encrypt' => false,
        //     'enabled' => true,
        // ],
    ],
];
```

---

## Strict Mode vs Fallbacks

Different database engines have varying feature sets. When an operation is not supported by a database:
- **Default Mode (`strict = false`)**: Logs a warning once per message using Laravel's logger (e.g., `[db-portable] asOfTime() is not supported on SQLite. Skipped.`) and executes a safe fallback.
- **Strict Mode (`strict = true`)**: Throws a `\RuntimeException` immediately. This ensures your code remains strictly compatible across all targeted database families without silent fallbacks.

You can set strict mode dynamically in tests:

```php
config(['db-portable.strict' => true]);
```
