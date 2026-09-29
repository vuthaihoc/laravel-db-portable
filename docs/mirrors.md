# Mirrors: several databases in parallel

> **Status: Phase 1 of [the plan](plans/parallel-databases.md), in progress.** Implemented: owner models
> (`#[MirroredAs]`, `Mirrored`), mirror models, and the queue engine with `'versions' => 'latest'`, tested on every pair of
> the conformance databases (SQLite, PostgreSQL, MySQL, CockroachDB, MatrixOne). Coming next: the `mirror:*`
> commands, then XTDB history mirrors (`'versions' => 'all'`). Names may still change before the release.

An application keeps writing to the database it uses today, its **owner**, and keeps copies of chosen tables in
other databases, its **mirrors**, to use what each does best: MatrixOne for analytics and search, XTDB for history
and audit, a second PostgreSQL for reporting. Each mirrored table has a **mirror model**, an Eloquent model on the
mirror's connection, and a query runs on a mirror by using that model.

It works like Laravel Scout: a trait on the owner model, observers that queue the sync, commands to backfill and
check, and models to query the other side. Scout does it for search engines; mirrors do it for any database and
capability.

Three rules keep it simple:

- **One owner per table.** A table is written in one database only; mirrors are read-only copies. Different tables
  may have different owners.
- **Eventually consistent.** A mirror follows its owner through a queue, a few seconds behind.
- **Explicit.** A query runs on a mirror because it uses a mirror model (`Analytics\Order`), never implicitly.

## Quick start

```php
// app/Models/Order.php: the owner model
use DbPortable\Mirror\Attributes\MirroredAs;
use DbPortable\Mirror\Mirrored;

#[MirroredAs('analytics', Analytics\Order::class)]
class Order extends Model
{
    use Mirrored;
}

// app/Models/Analytics/Order.php: the mirror model
use DbPortable\Mirror\MirrorModel;

class Order extends MirrorModel
{
    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Customer::class);   // customers stay in the owner database
    }
}
```

```php
// config/db-portable.php (php artisan vendor:publish --tag=db-portable-config)
'mirrors' => [
    'enabled' => env('DB_PORTABLE_MIRRORS_ENABLED', true),
    'analytics' => ['connection' => 'matrixone'],
    'models' => [],
],
```

The mirror table needs the owner's columns, with the owner's key as its (not auto-incrementing) primary key. Until
`mirror:schema` creates it, create it with a migration on the mirror connection; then copy the existing rows with
`Order::query()->mirrorable()` (soon `mirror:sync analytics`), and run a queue worker.

```php
// Paid revenue per day, computed by MatrixOne: one row per day (_wstart, _wend, paid)
Analytics\Order::query()
    ->timeWindow('created_at', '1 day')
    ->select('_wstart', '_wend')
    ->selectSumWhere('paid', 'total', fn ($q) => $q->where('status', 'paid'))
    ->orderBy('_wstart')
    ->get();

// Paid orders read from MatrixOne, with their customers from the owner database
Analytics\Order::with('customer')->where('status', 'paid')->latest()->paginate(20);
```

From then on, every order saved, deleted or restored through Eloquent reaches MatrixOne through the queue.

## Declaring mirrors

The code says what is mirrored where; the configuration says how each mirror runs in an environment.

```php
// The owner model: one #[MirroredAs] per mirror, with the mirror's name and the mirror model
#[MirroredAs('analytics', Analytics\Order::class)]
#[MirroredAs('history', History\Order::class)]
class Order extends Model
{
    use Mirrored;
}
```

```php
// config/db-portable.php
'mirrors' => [
    'enabled' => env('DB_PORTABLE_MIRRORS_ENABLED', true),      // every mirror

    // Each mirror, by its name in #[MirroredAs]
    'analytics' => [
        'connection' => env('DB_PORTABLE_ANALYTICS_CONNECTION', 'matrixone'),
        'queue' => 'mirrors',
    ],
    'history' => [
        'enabled' => env('DB_PORTABLE_HISTORY_ENABLED', true),
        'connection' => 'xtdb',
        'versions' => 'all',
        'erase_on_force_delete' => true,
    ],

    // Owner models turned off, in all their mirrors
    'models' => [
        App\Models\AuditLog::class => false,
    ],
],
```

`#[MirroredAs($mirror, $model, validTime: 'updated_at')]`:

| Parameter | Meaning |
|---|---|
| `mirror` | The mirror's name: its settings are `mirrors.<name>`, the `mirror:*` commands select mirrors by it, and `shouldMirror()` / `toMirrorArray()` receive it. Several owner models share a name to form one mirror. `enabled` and `models` are settings, not names. |
| `model` | The mirror model. |
| `validTime` | History mirrors: the owner's column each version is valid from (default `updated_at`). |

A mirror's settings, `mirrors.<name>`:

| Key | Default | Meaning |
|---|---|---|
| `connection` | required | A connection of `config/database.php`: every mirror model of the mirror reads it, so relations and joins between them run there. |
| `enabled` | `true` | Turns this mirror off (see below). |
| `queue_connection` | the default queue connection | The queue connection of the mirror's jobs. |
| `queue` | the default queue | The queue of the mirror's jobs. |
| `versions` | `latest` | `latest`: the rows' current state; `all`: every version, for history mirrors (XTDB, coming). See [Latest state or every version](#latest-state-or-every-version). |
| `erase_on_force_delete` | `false` | History mirrors: a force delete erases the row's history. |
| `encrypt` | `false` | Encrypt the queued jobs. |

- A mirror named by an owner model but missing from the configuration is off in that environment, with a warning
  (an exception with `db-portable.strict`); reading its mirror models throws.
- Attributes take constants only. When the mirrors of a model depend on the environment in other ways, override
  `mirroredAs()` and return `MirroredAs` objects.
- Owner models are found in `app/Models` (and register themselves when they boot). Register the others in a service
  provider: `app(\DbPortable\Mirror\MirrorRegistry::class)->register(Order::class)`.
- Queue workers must run for the mirrors to follow the owner (`php artisan queue:work --queue=mirrors`).

### Turning mirrors off

- An owner model reaches a mirror when `mirrors.enabled` is true, the mirror's `enabled` is true, and the owner model
  is not `false` in `mirrors.models`.
- Off: no job is queued, `mirrorable()` does nothing, and jobs already queued do nothing when they run. Changes made
  while off do not reach the mirrors: run `mirrorable()` (soon `mirror:data`) once mirroring is on again.
- Mirror models still read their tables.
- Tests switch it at runtime (`config(['db-portable.mirrors.enabled' => false])`); `Order::withoutMirroring(fn () => ...)`
  pauses one model for a block of code.

## Owner models

```php
#[MirroredAs('analytics', Analytics\Order::class)]
class Order extends Model
{
    use Mirrored;

    // Optional: skip some rows for some mirrors.
    public function shouldMirror(string $mirror): bool
    {
        return $mirror !== 'analytics' || $this->status !== 'draft';
    }
}
```

- `shouldMirror(string $mirror): bool` decides whether a row belongs in a mirror: a row that stops belonging is
  removed from it.
- `toMirrorArray(string $mirror): array` returns the row written to a mirror: the model's raw attributes by default.
  A mirror model's `fromOwner()` takes precedence.
- Updates that bypass Eloquent events (`Order::where(...)->update(...)`, `DB::table()`) are not seen: call
  `Order::where(...)->mirrorable()` afterwards to queue those rows (in jobs of 500 keys, `mirrorable(1000)` for
  another size), and `unmirrorable()` to remove rows from the mirrors. `Order::withoutMirroring(fn () => ...)`
  pauses mirroring for a block of code.

## Mirror models

A mirror model is a read-only Eloquent model on the mirror's connection. It can be empty
(`class Order extends MirrorModel {}`) or reshape and index the mirrored rows:

```php
namespace App\Models\Analytics;

use DbPortable\Mirror\MirrorModel;

class Order extends MirrorModel
{
    protected $table = 'orders';                  // default: the owner's table name

    // Optional: the row written for an owner model.
    public static function fromOwner(\App\Models\Order $order): array
    {
        return $order->only('id', 'customer_id', 'status', 'total', 'created_at', 'updated_at');
    }

    // Optional: the query loading the owner rows, with what fromOwner() reads.
    public static function ownerQuery(Builder $query): Builder
    {
        return $query->with('customer');
    }

    // Optional: mirror-only schema, for mirror:schema (coming).
    public static function mirrorSchema(Blueprint $table): void
    {
        $table->trigramIndex('status');
    }
}
```

- Writing a mirror model throws `MirrorIsReadOnly`: `save()`, `update()`, `delete()`, `create()`, `increment()`,
  and the query builder's `insert()`, `update()`, `upsert()`, `delete()`, `truncate()`... Only the mirror writer
  changes mirror tables (it writes through the connection, as `toBase()` or `DB::connection()` would).
- The table, the key and its type follow the owner model; so do the casts, so a mirror model reads its columns as the
  owner does (override `ownerCasts()` to drop them).
- `$mirrorOrder->ownerModel` is the owner row, read in the owner database; it is a relation, so
  `Analytics\Order::with('ownerModel')` eager loads it.
- `MirrorModel::mirrorName()` and `MirrorModel::ownerClass()` tell where the model comes from.
- A mirror of a soft-deleting owner keeps its soft-deleted rows, with their `deleted_at`: use `SoftDeletes` on the
  mirror model to hide them.
- A history mirror model on XTDB (coming) also uses laravel-xtdb2's `Bitemporal` trait, for `history()`,
  `asOfValidTime()` and `versions()`.
- Mirror models are ordinary Eloquent models otherwise: scopes, casts, accessors and the mirror driver's builder
  methods (`timeWindow()` and `sample()` on MatrixOne, `readStale()` on CockroachDB). A custom Eloquent builder of a
  mirror model extends `DbPortable\Mirror\MirrorBuilder`.

## Relations

Relations are declared on the mirror model, like on any model. Their target decides where the related rows are
read:

```php
class Order extends MirrorModel   // App\Models\Analytics\Order
{
    // A mirror model of the same mirror: read from MatrixOne (customers must be mirrored there).
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }   // Analytics\Customer

    // An owner model: read from the owner database.
    public function coupon(): BelongsTo { return $this->belongsTo(\App\Models\Coupon::class); }
}
```

| Target of the relation | `with()`, lazy loading | `whereHas()`, `withCount()`, joins |
|---|---|---|
| a mirror model of the same mirror | in the mirror database | in the mirror database |
| an owner model | in the owner database: a second query by key | not possible (two databases): `whereHas()` and the relation aggregates throw a `LogicException`, joins fail in the mirror database |

A related model without its own `$connection` would, in plain Laravel, inherit the parent model's connection, so an
owner model related to a mirror model would be read from the mirror database. `MirrorModel` prevents that
(`morphTo()` included): mirror models use the mirror's connection, the other models keep their own (the owner's).

Mirror tables have no foreign keys: rows arrive out of order, so a child can land before its parent.

### When to mirror related tables

A related table does not have to be mirrored. It depends on what the mirror database has to do with it:

| You need the related data to | Do | Mirror the related table? |
|---|---|---|
| display it next to mirror results (a list, a paginated report) | relate the mirror model to the owner model: `with()` reads it from the owner database by key | no |
| filter or group by a few of its fields (revenue by customer country) | copy those fields into the mirrored row with `fromOwner()` | no |
| query it freely: joins, `whereHas()`, many of its columns | mirror it too, and relate the mirror models | yes |

```php
class Order extends MirrorModel   // App\Models\Analytics\Order
{
    public static function fromOwner(\App\Models\Order $order): array
    {
        return $order->only('id', 'customer_id', 'status', 'total', 'created_at', 'updated_at') + [
            'customer_country' => $order->customer->country,
        ];
    }

    public static function ownerQuery(Builder $query): Builder
    {
        return $query->with('customer');   // one query for the customers of a batch
    }
}

// Revenue by country, in MatrixOne, without a join
Analytics\Order::groupBy('customer_country')->selectRaw('customer_country, sum(total) as revenue')->get();
```

- Copied fields keep the value they had when the row was mirrored (the customer's country when the order was
  written), which is often what analytics want; `mirrorable()` (soon `mirror:data`) rewrites the rows when they
  should follow later changes.
- Reading related rows from the owner suits pages of results; for exports of millions of rows, copy the fields or
  mirror the table, so the owner database does not serve the related rows.

## Querying a mirror

```php
Analytics\Order::with('customer', 'items')
    ->where('created_at', '>=', now()->subMonth())
    ->get();

History\Order::whereKey($id)->history()->get();          // XTDB (coming): every version of an order
History\Order::asOfValidTime('2026-03-01')->count();     // XTDB (coming): the orders as they were on March 1st
```

Planned for Phase 2: `->fresh()` waits until the mirror has caught up with the owner's latest change, and
`->orOwner()` reads from the owner when the mirror lags.

## How changes flow

```
created / updated / deleted  ──after commit──▶  queue job      ──▶  mirror writer  ──▶  mirror table
(owner, Eloquent events)                        (row, mirror)       (upsert / delete, version guard)
```

- A restore is an update; a soft delete is a change too (the mirror keeps the row with its `deleted_at`); a force
  delete removes the row.
- Jobs are dispatched after the transaction commits: a rolled-back change never reaches a mirror.
- One job per row and mirror waits in the queue: while it waits, later changes of the row need no other job.
- The worker reads the owner row when it runs (global scopes off, soft-deleted rows included, through the mirror
  model's `ownerQuery()`) and writes it; a row gone from the owner, or for which `shouldMirror()` is false, is deleted
  from the mirror. Two jobs of one row never run at once (a cache lock), so the last one writes the latest state.
- Failed jobs retry with the queue's settings.

### Latest state or every version

`versions` sets what a mirror keeps:

| `versions` | The job carries | The mirror gets | Use it for |
|---|---|---|---|
| `latest` (default) | the model's class and key | the row as it is when the worker runs: five quick updates of one row make one write | analytics, search, reporting: mirrors that need the current state |
| `all` (coming) | a copy of the row's attributes as committed | every committed version, each valid from its `validTime` column | history mirrors: XTDB only |

- Only a history database holds every version. PostgreSQL, MySQL, MatrixOne, CockroachDB and SQLite keep one row per
  key, where every version would end as the latest one, with more writes and weaker deletes: `'versions' => 'all'`
  on them is rejected.
- With `all`, the row's values sit in the queue until the job runs: set `'encrypt' => true` when they carry personal
  data.

### Writes

- Every write is an upsert keyed by the mirror table's key and guarded by a version, the owner's `updated_at`: a row
  never replaces a newer version, so replaying a job, or running two out of order, leaves the mirror correct. An equal
  version overwrites (rows changed without touching `updated_at` can be rewritten), and a row without a version always
  writes. PostgreSQL, CockroachDB and SQLite get `insert ... on conflict ... do update ... where`, the MySQL family
  `insert ... on duplicate key update col = if(...)`.
- Values are converted for the mirror as `db-portable:copy` does (booleans, JSON, dates without an offset, enums).
- Columns the mirror table lacks are left out with a warning (an exception with `db-portable.strict`): an owner
  migration added them, `mirror:schema` (coming) adds them to the mirror.
- A delete removes the mirror row (on XTDB, coming: ends its validity, the history stays; a force delete erases it
  when `erase_on_force_delete` is on).
- A mirror on the owner's own connection and table is refused: it would write the owner's table.

## Commands (coming next)

The next step of Phase 1. Until then, `mirrorable()` backfills and repairs, and the mirror tables are created with
migrations.

### mirror:schema

```bash
php artisan db-portable:mirror:schema analytics --dry-run      # the DDL, without running it
php artisan db-portable:mirror:schema analytics --migration    # write a migration for the mirror connection
php artisan db-portable:mirror:schema analytics                # create or alter the mirror tables
```

Compares each owner table with its mirror table and creates or alters the mirror table: the columns of the mirrored
rows mapped to the mirror's types (the same checks as `db-portable:audit`: string widths, integer ranges, JSON), plus
the mirror model's `mirrorSchema()` (portable indexes such as `trigramIndex()`, full-text or vector indexes). No
foreign keys. XTDB mirrors get `CREATE TABLE (columns)`. A column dropped on the owner stays on the mirror and is
reported by `mirror:stats`.

### mirror:data

```bash
php artisan db-portable:mirror:data analytics                  # backfill every mirrored model
php artisan db-portable:mirror:data analytics --model='App\Models\Order' --since='-1 hour'
php artisan db-portable:mirror:data analytics --queue          # spread the batches over queue workers
php artisan db-portable:mirror:data analytics --prune          # also remove mirror rows gone from the owner
```

Reads the owner in key order (batches of `--chunk=500`) and writes through the mirror writer, with the same value
conversions and version guard as the queue.

### mirror:stats

```bash
php artisan db-portable:mirror:stats analytics                 # server statistics of both sides
php artisan db-portable:mirror:stats analytics --compare       # plus exact aggregates of what both share
php artisan db-portable:mirror:stats analytics --keys          # plus the key ranges and keys that differ
```

The owner table and the mirror table are two tables on two servers. `mirror:stats` first shows what each server
knows about its table, without scanning it, as fresh as that server keeps it:

| Server | Statistics shown |
|---|---|
| PostgreSQL | estimated rows (`pg_class.reltuples`), size, live and dead rows, last analyze, per-column null fraction and distinct values (`pg_stats`) |
| MySQL / MariaDB | estimated rows, data and index size, index cardinality |
| CockroachDB | per-column rows, distinct values and nulls (`SHOW STATISTICS`), estimated rows |
| MatrixOne | rows and size (`mo_table_rows()`, `mo_table_size()`, refreshed asynchronously), rows and nulls of flushed data (`metadata_scan()`) |
| XTDB | none: rows are counted |
| SQLite | `sqlite_stat1`, after `ANALYZE` |

With `--compare`, it runs one aggregate query per side over what both tables share: `count(*)`, and for the key and
each column present on both sides, null counts, `min` / `max`, and `sum` of numeric columns; `max(updated_at)` gives
the lag. With `--keys`, it counts rows per key range on both sides and lists the missing and extra keys of the ranges
that differ.

```
Order → analytics (matrixone)          owner (pgsql)          mirror (matrixone)
server: rows                           ~1,204,330             1,198,020 (async)
server: size                           212 MB                 88 MB
count(*)                               1,204,512              1,204,498             ≠ 14
id min / max                           1 / 1,204,640          1 / 1,204,640
status nulls                           0                      0
total sum                              98,120,331.50          98,119,870.00         ≠
updated_at max                         2026-09-29 10:15:02    2026-09-29 10:14:58   lag 4 s
queue                                  3 pending, 0 failed
relations                              items → Analytics\OrderItem: table missing
```

`mirror:data` fixes missing or stale rows, `mirror:data --prune` extra ones.

### mirror:sync and mirror:flush

```bash
php artisan db-portable:mirror:sync analytics    # schema, then data, then stats: a new mirror, or after a deployment
php artisan db-portable:mirror:flush analytics   # empty the mirror tables (ERASE on XTDB)
```

## Recipes

### Analytics in MatrixOne, OLTP in PostgreSQL or MySQL

Mirror the tables a dashboard needs, and copy into them the few fields of other tables it filters or groups by; the
dashboard queries run in MatrixOne without touching the OLTP database:

```php
#[MirroredAs('analytics', Analytics\Order::class)]      class Order extends Model { use Mirrored; }
#[MirroredAs('analytics', Analytics\OrderItem::class)]  class OrderItem extends Model { use Mirrored; }

// config/db-portable.php: 'analytics' => ['connection' => 'matrixone', 'queue' => 'mirrors']

// Items sold per product, joined inside MatrixOne
Analytics\OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
    ->where('orders.status', 'paid')
    ->groupBy('order_items.product_id')
    ->selectRaw('order_items.product_id, sum(order_items.quantity) as sold')
    ->get();
```

### Audit history in XTDB, for any owner (coming)

```php
#[MirroredAs('history', History\Order::class)]
class Order extends Model { use Mirrored; }

// config/db-portable.php: 'history' => ['connection' => 'xtdb', 'versions' => 'all', 'erase_on_force_delete' => true]

// app/Models/History/Order.php
class Order extends MirrorModel { use Bitemporal; }   // Bitemporal from laravel-xtdb2

History\Order::whereKey($order->id)->history()->get();   // every version, with _valid_from / _valid_to
History\Order::asOfValidTime('2026-03-01')->get();      // the orders as they were on March 1st
```

With `'versions' => 'all'` (each version valid from `updated_at`, the default `validTime`), the owner keeps its usual
tables while XTDB keeps the full history, and `erase_on_force_delete` covers erasure requests.

### A reporting copy

A second PostgreSQL (or MySQL) with the same tables, for reports and exports that should not load the owner: an
empty mirror model per table (`class Order extends MirrorModel {}` in `App\Models\Reporting`),
`#[MirroredAs('reporting', Reporting\Order::class)]` on the owner models, `'reporting' => ['connection' =>
'pgsql_reporting']` in the configuration, the tables copied once, then `Reporting\Order::...`.

### Moving to another database without downtime

Mirror everything to the new database, check that the two sides match (`mirror:stats --compare --keys`, coming), then
make the new database the owner (the connection of the models) and, for a while, mirror back to the old one.

## Limits

- Writes that bypass Eloquent events are not mirrored unless followed by `mirrorable()`; the poll engine (Phase 3)
  and native change capture (Phase 4) will cover them.
- Mirrors are eventually consistent: a read right after a write may not see it yet.
- `whereHas()` and joins between a mirror and the owner are not possible (two databases): copy the fields you need
  with `fromOwner()`, or mirror the related tables.
- Mirror tables have no foreign keys, and owner schema changes need the mirror tables altered too (`mirror:schema`,
  coming).
- The queue engine needs a cache store with locks (Laravel's default stores have them): jobs are unique per row while
  they wait, and two jobs of one row do not run at once.
- XTDB mirrors depend on laravel-xtdb2 and XTDB 2.2, both pre-releases.
