# Mirrors: several databases in parallel

> **Design preview.** Nothing on this page is implemented yet. It describes the planned API of the queue engine
> (Phase 1 of [the plan](plans/parallel-databases.md)); names and options may still change.

An application keeps writing to the database it uses today, its **owner**, and keeps copies of chosen tables in
other databases, its **mirrors**, to use what each does best: MatrixOne for analytics and search, XTDB for history
and audit, a second PostgreSQL for reporting. Reads from a mirror use Eloquent models and relations, like reads from
the owner.

It works like Laravel Scout: a trait on the model, observers that queue the sync, commands to backfill and check,
and a query API on the other side. Scout does it for search engines; mirrors do it for any database and capability.

Three rules keep it simple:

- **One owner per table.** A table is written in one database only; mirrors are read-only copies. Different tables
  may have different owners.
- **Eventually consistent.** A mirror follows its owner through a queue, a few seconds behind; `mirror:stats` shows
  how far and whether the two differ.
- **Explicit.** A query runs on a mirror only when it says so: `Order::mirror('analytics')`.

## Quick start

```php
// config/db-portable.php
'mirrors' => [
    'analytics' => ['connection' => 'matrixone'],
],
```

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
use DbPortable\Mirror\MirrorsOwnerRelations;

class Order extends MirrorModel
{
    use MirrorsOwnerRelations;
}
```

```bash
php artisan db-portable:mirror:sync analytics     # create the mirror tables, copy the rows, compare both sides
```

```php
Order::mirror('analytics')
    ->with('customer')
    ->timeWindow('created_at', '1 day')
    ->selectSumWhere('paid', 'total', fn ($q) => $q->where('status', 'paid'))
    ->get();
```

From then on, every order saved, deleted or restored through Eloquent reaches MatrixOne through the queue.

## Configuration

```php
'mirrors' => [
    'analytics' => [
        'connection' => 'matrixone',     // a connection of config/database.php
        'queue' => 'mirrors',            // queue name (default: the default queue)
        'queue_connection' => null,      // queue connection (default: the default one)
        'payload' => 'fresh',            // fresh | snapshot, see "How changes flow"
    ],
    'history' => [
        'connection' => 'xtdb',
        'payload' => 'snapshot',         // every version, for bitemporal history
        'valid_time' => 'updated_at',    // each version is valid from this column
        'erase_on_force_delete' => false,
    ],
],
```

Queue workers must run for the mirrors to follow the owner (`php artisan queue:work --queue=mirrors`).

## Owner models

```php
#[MirroredAs('analytics', Analytics\Order::class)]
#[MirroredAs('history', History\Order::class)]
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

- `#[MirroredAs($mirror, $class)]` names the model representing the owner in a mirror; repeat it per mirror. A
  `mirroredAs(): array` method (`['analytics' => Analytics\Order::class]`) does the same when the mapping depends on
  configuration. Without a class (`#[MirroredAs('reporting')]`), the mirror is read through a generic read-only model
  on the owner's table name.
- `toMirrorArray(string $mirror): array` returns the row written to a mirror: the model's raw attributes by default.
  A mirror model's `fromOwner()` takes precedence.
- Updates that bypass Eloquent events (`Order::where(...)->update(...)`) are not seen: call
  `Order::where(...)->mirrorable()` afterwards to queue those rows, and `unmirrorable()` to remove rows from the
  mirrors. `Order::withoutMirroring(fn () => ...)` pauses mirroring for a block of code.

## Mirror models

A mirror model is a read-only Eloquent model on the mirror's connection:

```php
namespace App\Models\Analytics;

use DbPortable\Mirror\MirrorModel;

class Order extends MirrorModel
{
    protected $table = 'orders';                  // default: the owner's table name

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);  // Analytics\Customer: runs in the mirror
    }

    // Optional: the row written for an owner model.
    public static function fromOwner(\App\Models\Order $order): array
    {
        return $order->only('id', 'customer_id', 'status', 'total', 'created_at', 'updated_at');
    }

    // Optional: mirror-only schema used by mirror:schema.
    public static function mirrorSchema(Blueprint $table): void
    {
        $table->trigramIndex('status');
    }
}
```

- Saving, updating or deleting a mirror model throws `MirrorIsReadOnly`; only the mirror writer changes mirror
  tables.
- The connection comes from the mirror's configuration, and the key follows the target: the owner's key column
  (`id`), or `_id` on XTDB.
- `$mirrorOrder->owner()` loads the owner row.
- A history mirror model on XTDB also uses laravel-xtdb2's `Bitemporal` trait, for `history()`, `asOfValidTime()`
  and `versions()`.

## Relations and eager loading

Mirror models have relations like any model. Declare them, or derive them from the owner model:

```php
class Order extends MirrorModel
{
    use MirrorsOwnerRelations;   // customer(), items()... from App\Models\Order, retargeted to this mirror
}
```

`MirrorsOwnerRelations` reads each relation of the owner model and builds the same relation on the mirror model:
a related owner model mirrored in the same mirror is replaced by its mirror model, one that is not stays the owner
model (a cross-database relation). A relation declared on the mirror model wins over the derived one.

| Relation | `with()` (eager loading) | `whereHas()`, joins |
|---|---|---|
| mirror model → mirror model of the same mirror | yes, in the mirror database | yes, in the mirror database |
| mirror model → owner model | yes, Eloquent runs that query on the owner's connection | no (two databases): a clear error |
| owner model → its mirror row, `hasMirror('analytics')` | yes: `Order::with('analytics')->get()` | no |
| owner model → its versions, `hasMirrorHistory('history')` | yes: `Order::with('history')->get()` | no |

```php
// Owner side: relations to the mirrors.
class Order extends Model
{
    use Mirrored;

    public function history(): HasMany { return $this->hasMirrorHistory('history'); }
}

Order::with('history')->find($id)->history;   // every version of the order, from XTDB
```

Joins and `whereHas()` need the related tables in the same mirror: mirror the related models too, or
`mirror:stats` warns about relations that point to a mirror table that does not exist or is empty. Mirror tables
have no foreign keys: rows arrive out of order, so a child can land before its parent.

## Querying a mirror

```php
Order::mirror('analytics')                       // = Analytics\Order::query()
    ->with('customer', 'items')
    ->where('created_at', '>=', now()->subMonth())
    ->get();

Order::mirror('history')->whereKey($id)->history()->get();      // XTDB: every version
Order::mirror('history')->asOfValidTime('2026-03-01')->count(); // XTDB: as it was on March 1st
```

The models are the mirror models, with the capabilities of the mirror's driver: `timeWindow()`, `sample()` and
full-text on MatrixOne, bitemporal queries on XTDB, `readStale()` on CockroachDB.

Planned for Phase 2: `->fresh()` waits until the mirror has caught up with the owner's latest change, and
`->orOwner()` reads from the owner when the mirror lags.

## How changes flow

```
save / delete / restore  ──after commit──▶  queue job  ──▶  mirror writer  ──▶  mirror table
(owner, Eloquent events)                    (per mirror)     (upsert / delete, version guard)
```

- Jobs are dispatched after the transaction commits: a rolled-back change never reaches a mirror.
- **`fresh` payload** (default): the job carries the key, and the worker reads the owner row when it runs. A burst of
  updates to one row becomes one write, and the mirror gets the latest state.
- **`snapshot` payload** (history mirrors): the job carries the attributes as committed, so every version is written;
  on XTDB each version is valid from its `valid_time` column (`updated_at`), so history is right even when jobs run
  out of order.
- Every write is an upsert guarded by a version (the owner's `updated_at`): replaying a job, or running two out of
  order, leaves the mirror correct.
- A delete removes the mirror row (on XTDB: ends its validity, the history stays). A soft delete writes `deleted_at`;
  a restore writes the row again; a force delete erases the XTDB history when `erase_on_force_delete` is on.
- Failed jobs retry with the queue's settings; `mirror:stats` counts them.

## Commands

### mirror:schema

```bash
php artisan db-portable:mirror:schema analytics --dry-run      # the DDL, without running it
php artisan db-portable:mirror:schema analytics --migration    # write a migration for the mirror connection
php artisan db-portable:mirror:schema analytics                # create or alter the mirror tables
```

Compares each owner table with its mirror table and creates or alters the mirror table: the owner's columns mapped
to the mirror's types (the same checks as `db-portable:audit`: string widths, integer ranges, JSON), plus the mirror
model's `mirrorSchema()` (portable indexes such as `trigramIndex()`, full-text or vector indexes). No foreign keys.
XTDB mirrors get `CREATE TABLE (columns)`. A column dropped on the owner stays on the mirror and is reported by
`mirror:stats`.

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
relations                              items → analytics.order_items: missing table
```

`mirror:data` fixes missing or stale rows, `mirror:data --prune` extra ones.

### mirror:sync and mirror:flush

```bash
php artisan db-portable:mirror:sync analytics    # schema, then data, then stats: a new mirror, or after a deployment
php artisan db-portable:mirror:flush analytics   # empty the mirror tables (ERASE on XTDB)
```

## Recipes

### Analytics in MatrixOne, OLTP in PostgreSQL or MySQL

Mirror the tables a dashboard needs (orders, their items, customers) to MatrixOne; the dashboard queries run there,
joins included, without touching the OLTP database:

```php
#[MirroredAs('analytics', Analytics\Order::class)]      class Order extends Model { use Mirrored; }
#[MirroredAs('analytics', Analytics\OrderItem::class)]  class OrderItem extends Model { use Mirrored; }
#[MirroredAs('analytics', Analytics\Customer::class)]   class Customer extends Model { use Mirrored; }

Order::mirror('analytics')
    ->join('customers', 'customers.id', '=', 'orders.customer_id')
    ->timeWindow('orders.created_at', '1 hour')
    ->selectCountWhere('paid_orders', fn ($q) => $q->where('orders.status', 'paid'))
    ->get();
```

### Audit history in XTDB, for any owner

```php
#[MirroredAs('history', History\Order::class)]
class Order extends Model
{
    use Mirrored;

    public function history(): HasMany { return $this->hasMirrorHistory('history'); }
}

// app/Models/History/Order.php
class Order extends MirrorModel { use Bitemporal; }          // Bitemporal from laravel-xtdb2

$order->history;                                              // every version, with _valid_from / _valid_to
Order::mirror('history')->asOfValidTime('2026-03-01')->get(); // the orders as they were on March 1st
```

With the `snapshot` payload and `valid_time => 'updated_at'`, the owner keeps its usual tables while XTDB keeps the
full history, and `erase_on_force_delete` covers erasure requests.

### A reporting copy

A second PostgreSQL (or MySQL) with the same tables, for reports and exports that should not load the owner:
`#[MirroredAs('reporting')]` on the models, `mirror:sync reporting`, then `Order::mirror('reporting')`.

### Moving to another database without downtime

Mirror everything to the new database, check with `mirror:stats --compare --keys` until the two sides match, then
make the new database the owner (the connection of the models) and, for a while, mirror back to the old one.

## Limits

- Writes that bypass Eloquent events are not mirrored unless followed by `mirrorable()`; the poll engine (Phase 3)
  and native change capture (Phase 4) will cover them.
- Mirrors are eventually consistent: a read right after a write may not see it yet.
- `whereHas()` and joins across the owner and a mirror are not possible (two databases); mirror the related tables.
- Mirror tables have no foreign keys, and owner schema changes need `mirror:schema` (or `mirror:sync`).
- XTDB mirrors depend on laravel-xtdb2 and XTDB 2.2, both pre-releases.
