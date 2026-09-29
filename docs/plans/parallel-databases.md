# Plan: databases working in parallel

Status: proposal (2026-09-29). Nothing here is implemented yet.

## Goal

laravel-db-portable today helps an application **move** between databases: portable macros, `scan`, `audit`,
`copy`. The next goal is to let an application **use several databases at once**, each for what it does best, with
Eloquent-friendly code:

- keep writing to the database the application already uses (PostgreSQL, MySQL, CockroachDB...);
- keep **mirrors** of chosen tables in other databases: MatrixOne for analytics, full-text and vector search,
  XTDB for bitemporal history and audit, a second PostgreSQL for reporting...;
- query a mirror with its own Eloquent models: `Analytics\Order::timeWindow(...)`, `History\Order::whereKey($id)->history()`;
- declare and supervise the native change-data-capture and replication features of the databases (MatrixOne
  `CREATE CDC`, CockroachDB changefeeds, PostgreSQL logical replication, XTDB external sources) from Laravel;
- as a consequence, migrate between databases **online**: copy, keep in sync, verify, cut over.

Laravel Scout is the model: a trait on the model, observers that queue the sync, an import command, and a query
API on the other side. Scout does this for search engines; this plan does it for databases and any capability.

## Principles

1. **One owner per table.** Every table is written in exactly one database (its owner). Other databases hold
   read-only mirrors of it. This avoids conflict resolution entirely; "support each other in parts" means different
   tables can have different owners (orders owned by CockroachDB, the product catalogue owned by PostgreSQL), not
   that two databases write the same rows. Two-way sync of one table is out of scope.
2. **Mirrors are read models.** A mirror can reshape the data (`toMirrorArray()`), add columns, or keep history
   (XTDB), but the application never writes to it directly. The query API refuses writes on a mirror.
3. **Eventual consistency, measured.** Every mirror records how far it has applied changes (a watermark). Lag is
   visible (`mirror:status`), and a read can wait for the mirror to catch up or fall back to the owner.
4. **Idempotent, order-safe apply.** Replaying a change, or applying two changes out of order, leaves the mirror
   correct: writes are upserts keyed by the primary key and guarded by a version (the owner's `updated_at` or a
   sequence), deletes are tombstones. XTDB targets use the version as valid time, so history is right whatever the
   arrival order.
5. **Explicit over magic.** Nothing routes queries to a mirror implicitly: a query runs on a mirror because it uses
   a mirror model (`Analytics\Order`), and a relation reads where its target model lives.
6. **Ops features are declarative and dry-runnable.** Creating a CDC task or a replication stream prints the SQL
   first (`--dry-run`), checks prerequisites (PITR, `wal_level`, rangefeeds), stores the declaration, and can show
   status and drop it. Credentials never reach logs.
7. **Drivers provide primitives through contracts** (like `HistoricalReads`); the orchestration lives in
   db-portable (or a sibling package, see decisions).

## What each database brings (verified 2026-09-29 unless marked)

| Database | Strengths to borrow | Native change capture / replication |
|---|---|---|
| PostgreSQL | rich SQL, extensions (pg_trgm, PostGIS, pgvector), mature OLTP | logical replication (`CREATE PUBLICATION` / `SUBSCRIPTION`, PG → PG); logical decoding slots readable with SQL (`pg_logical_slot_get_changes`, `test_decoding` built in, `wal2json` as extension) *(docs)* |
| MySQL / MariaDB | ubiquitous OLTP | binlog replication (MySQL → MySQL); binlog CDC only through external tools (Debezium) *(docs)* |
| CockroachDB | distributed OLTP, follower reads, `AS OF SYSTEM TIME`, 40001-safe transactions | **changefeeds** (`CREATE CHANGEFEED ... INTO 'webhook-https://…' / 'kafka://…' / cloud storage`) with `updated` and `resolved` timestamps: created and running on v26.2.6 **without a license**; also logical data replication between clusters *(docs)* |
| MatrixOne | HTAP analytics (time windows, sampling), full-text (ngram, BM25), vector indexes, snapshots/PITR | **`CREATE CDC`** to MatrixOne or MySQL sinks: verified MatrixOne → MatrixOne, initial snapshot + insert/update/delete applied in ~15 s; needs a PITR of ≥ 2 h on the source; status in `mo_catalog.mo_cdc_task`, progress in `mo_catalog.mo_cdc_watermark` (`SHOW CDC` returns a column type PDO cannot read) |
| XTDB 2.2 | bitemporal history, audit, as-of queries, `erase()` | **external sources**: `ATTACH DATABASE ... WITH $$ externalSource: !Postgres … $$` mirrors PostgreSQL 17+ (logical replication, needs node YAML `remotes`) and Kafka Connect / Debezium sources *(docs; public API being finalised for 2.2, xtdb/xtdb#5725)*; no change stream out of XTDB yet (xtdb/xtdb#2454) |
| SQLite | local, tests | none (triggers only) |

## Pair matrix (owner → mirror)

Every pair gets the application-level engines: **Q** (queue: Eloquent observers + queue) and **P** (poll by
watermark; the owner needs a version column such as `updated_at`). The cells list what each pair adds natively.

Status: ✅ verified on a test server (2026-09-29), 📄 documented by the vendor (not tried here), 🧪 planned in
db-portable (a relay that reads the owner's change stream and writes the mirror), — nothing native.

| Owner ↓ / Mirror → | PostgreSQL | MySQL / MariaDB | CockroachDB | MatrixOne | XTDB 2.2 | SQLite |
|---|---|---|---|---|---|---|
| **PostgreSQL** | 📄 logical replication (publication / subscription) | 🧪 logical slot relay | 🧪 logical slot relay | 🧪 logical slot relay | 📄 XTDB external source (`ATTACH`, PostgreSQL 17+) | 🧪 logical slot relay |
| **MySQL / MariaDB** | — | 📄 binlog replication | — | — | 📄 Kafka Connect / Debezium source (needs Kafka) | — |
| **CockroachDB** | 🧪 changefeed → webhook relay (changefeed ✅) | 🧪 changefeed relay (changefeed ✅) | 📄 logical data replication; 🧪 changefeed relay | 🧪 changefeed relay (changefeed ✅) | 🧪 changefeed relay (changefeed ✅) | 🧪 changefeed relay |
| **MatrixOne** | — | 📄 `CREATE CDC` with a MySQL sink | — | ✅ `CREATE CDC` (snapshot + insert/update/delete in ~15 s; PITR ≥ 2 h) | — | — |
| **XTDB 2.2** | 🧪 poll by `_system_from` | 🧪 poll by `_system_from` | 🧪 poll by `_system_from` | 🧪 poll by `_system_from` | 🧪 poll by `_system_from` | 🧪 poll by `_system_from` |
| **SQLite** | — | — | — | — | — | — |

What a mirror brings, whoever the owner:

| Mirror | Capabilities to borrow | Query examples |
|---|---|---|
| PostgreSQL | extensions (PostGIS, pgvector, pg_trgm), `ts_rank` full-text, reporting replica | `Geo\Place::whereRaw('ST_DWithin(...)')` |
| MySQL / MariaDB | a copy for MySQL-based BI and legacy tools | `Bi\Order::...` |
| CockroachDB | geo-distributed reads (follower reads), `AS OF SYSTEM TIME`, trigram and `ts_rank` search, Scout `crdb` engine | `Global\Order::readStale()->...` |
| MatrixOne | analytics (`timeWindow()`, `sample()`, `rollup()`), full-text (ngram, BM25), vector search, Scout `matrixone` engines, snapshots / PITR | `Analytics\Order::timeWindow('created_at', '1 hour')->...` |
| XTDB 2.2 | bitemporal history, as-of reads, audit trail, `erase()` | `History\Order::whereKey($id)->history()`, `History\Order::asOfValidTime($date)` |
| SQLite | local or offline copy, tests | `Local\Order::...` |

Features every pair gets from db-portable: mirror models with relations, `mirror:schema`, `mirror:data`,
`mirror:stats`, `mirror:sync`, `fresh()` / `orOwner()` (Phase 2), and the online migration workflow.

## Architecture

```
            writes                                     reads by capability
  App ─────────────▶ Owner DB                 Analytics\Order::timeWindow(...)
   │                  (pgsql/mysql/crdb)       History\Order::whereKey($id)->history()
   │                     │                              │
   │ Eloquent events     │ native CDC                   ▼
   │ (afterCommit)       │ (changefeed, CDC task,   ┌─────────────┐
   ▼                     │  logical slot, ATTACH)   │ Mirror DBs  │
 Queue ──▶ MirrorWriter ◀┘ ─── poll by watermark ──▶│ matrixone   │
            (normalize, version guard, batch)       │ xtdb        │
                    │                                │ pgsql #2    │
                    ▼                                └─────────────┘
          mirror state table (watermark, lag, errors)
```

### Components

- **Mirror registry** (`config/db-portable.php` → `mirrors`): named mirrors with a target connection, a sync
  engine and options.

  ```php
  'mirrors' => [
      'analytics' => ['connection' => 'matrixone', 'engine' => 'queue', 'queue' => 'mirrors'],
      'history'   => ['connection' => 'xtdb',      'engine' => 'queue', 'versions' => 'all', 'valid_time' => 'updated_at'],
      'reporting' => ['connection' => 'pgsql_reporting', 'engine' => 'native'],   // PG logical replication
  ],
  ```

- **`Mirrored` model trait** (Scout's `Searchable` counterpart) on the owner model, with `#[MirroredAs]` naming the
  model that represents it in each mirror (see [Queue engine](#queue-engine-mirror-models-relations-and-commands)).
  Registers an observer (`saved`, `deleted`, `restored`, `forceDeleted`; after commit) that queues a `MirrorChanges`
  job; adds `mirrorable()` / `unmirrorable()` macros on the query builder for mass updates that bypass events (as
  Scout's `searchable()`).
- **`MirrorModel`**: the read-only Eloquent base class of mirror models, on the mirror's connection, with ordinary
  relations (to mirror models, read in the mirror; to owner models, read in the owner database) and optional
  `fromOwner()` / `mirrorSchema()` hooks.

- **Sync engines**
  - `queue` (Phase 1): observer + queue; immediate, portable to every pair; misses writes that bypass Eloquent.
  - `poll` (Phase 3): scheduled incremental pull by watermark (`updated_at`, CockroachDB
    `crdb_internal_mvcc_timestamp`, a PostgreSQL logical slot); catches raw writes; deletes need soft deletes, a
    slot, or a periodic key reconciliation.
  - `native` (Phase 4): the database's own CDC or replication, declared and supervised by db-portable; changes may
    flow server to server (MatrixOne CDC, PG subscription, XTDB ATTACH) or through a db-portable receiver
    (CockroachDB changefeed webhook → route → `MirrorWriter`).
- **`MirrorWriter`** per target family: batched upserts (`on conflict`, `on duplicate key`, MatrixOne upsert,
  XTDB `PATCH`/insert with `validFrom($version)`), tombstone deletes (XTDB: `delete()` for a period or `erase()` on
  force delete), version guard, value normalisation extracted from `Copier::normalize()` into a shared `ValueMapper`,
  retries from the drivers (40001, w-w conflicts).
- **Mirror state** (`db_portable_mirrors` table on the owner): per mirror and table, the applied watermark, lag,
  last error, counts. Feeds `mirror:status`, freshness waits and alerts.
- **Query side**: queries use the mirror models directly (`Analytics\Order::...`). Phase 2 helpers: `->fresh()` waits
  until the mirror's watermark passes the owner's latest version (bounded), `->orOwner()` falls back to the owner when
  the mirror lags.
- **Commands**: `db-portable:mirror:schema`, `mirror:data`, `mirror:stats`, `mirror:sync` (the three in order) and
  `mirror:flush` (see [Commands](#commands)); `db-portable:cdc {create|status|drop}` and
  `db-portable:replicate {create|status|drop}` for native features, all with `--dry-run`.
- **Driver contracts** (in db-portable, implemented by the driver packages):
  - `ProvidesChangeCapture`: create / status / drop a change stream (CockroachDB changefeed; MatrixOne CDC task with
    its PITR preflight; PostgreSQL logical slot or publication, in db-portable itself since `pgsql` is core);
  - `AcceptsExternalSource` (XTDB): attach a PostgreSQL / Kafka Connect source;
  - `MirrorTarget` (optional): driver-specific upsert/delete/history writes when the family default is not right.

## Queue engine: mirror models, relations and commands

### Declaring the mirror model: `#[MirroredAs]`

The owner model names, per mirror, the model class that represents it there:

```php
use DbPortable\Mirror\Attributes\MirroredAs;
use DbPortable\Mirror\Mirrored;

#[MirroredAs('analytics', Analytics\Order::class)]
#[MirroredAs('history', History\Order::class)]
class Order extends Model
{
    use Mirrored;

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function items(): HasMany { return $this->hasMany(OrderItem::class); }
}
```

A `mirroredAs(): array` method (`['analytics' => Analytics\Order::class]`) does the same when the mapping depends on
configuration. Every mirrored table has a mirror model class; it can be empty (`class Order extends MirrorModel {}`).

The mirror model is a regular, read-only Eloquent model on the mirror's connection:

```php
namespace App\Models\Analytics;

use DbPortable\Mirror\MirrorModel;

class Order extends MirrorModel            // the connection comes from the mirror's configuration
{
    protected $table = 'orders';

    // A relation to a mirror model is read in the mirror; one to an owner model, in the owner database.
    public function items(): HasMany { return $this->hasMany(OrderItem::class); }                   // Analytics\OrderItem
    public function customer(): BelongsTo { return $this->belongsTo(\App\Models\Customer::class); } // owner model

    // Optional: the row written for an owner model (default: $order->toMirrorArray('analytics')).
    public static function fromOwner(\App\Models\Order $order): array
    {
        return $order->only('id', 'customer_id', 'status', 'total', 'created_at', 'updated_at');
    }

    // Optional: mirror-only schema for mirror:schema (indexes the owner does not need).
    public static function mirrorSchema(Blueprint $table): void
    {
        $table->trigramIndex('status');
    }
}
```

- Saving, updating or deleting through a mirror model throws `MirrorIsReadOnly`; only the `MirrorWriter` writes.
- `Analytics\Order::find($id)->owner()` loads the owner row; `MirrorModel::mirrorName()` and `::ownerClass()` come
  from the registry.
- A history mirror model (XTDB) also uses laravel-xtdb2's `Bitemporal`: `History\Order::whereKey($id)->history()`.

### Relations

Relations are declared on the mirror model, like on any Eloquent model; there is no owner-side API and no derived
relations. The target decides where related rows are read:

| Target of a mirror model's relation | `with()` (eager loading) | `whereHas()`, joins |
|---|---|---|
| a mirror model of the same mirror | in the mirror database | yes, in the mirror database |
| an owner model | in the owner database: a second query by key | no (two databases): a clear error |

- Laravel gives a related model without its own `$connection` the parent's connection
  (`HasRelationships::newRelatedInstance()`), so an owner model related to a mirror model would be read from the
  mirror database, silently (verified on Laravel 12.69). `MirrorModel` overrides `newRelatedInstance()`: only mirror
  models share the mirror's connection; owner models keep the default (owner) connection. This needs a test in the
  mirror conformance suite.
- Mirror tables get no foreign keys (`mirror:schema` skips them): rows arrive out of order, so a child can land before
  its parent. `mirror:stats` warns when a relation targets a mirror model whose table is missing or empty.
- A related table does not have to be mirrored:

  | The related data is needed to | Do | Mirror it? |
  |---|---|---|
  | display it next to mirror results | relate the mirror model to the owner model | no |
  | filter or group by a few of its fields | copy them into the mirrored row with `fromOwner()` | no |
  | query it freely (joins, `whereHas()`, many columns) | mirror it and relate the mirror models | yes |

### Latest state or every version (`versions`)

- `latest` (default): the job carries the model and its key; the worker reads the owner row when it runs, so a burst
  of updates collapses into one write (unique job per key) and the mirror gets the current state.
- `all` (history mirrors): the job carries a copy of the attributes as committed, so every version reaches XTDB,
  valid from its `valid_time` column (`updated_at`). The values sit in the queue until the job runs: encrypted jobs
  (`ShouldBeEncrypted`) when they carry personal data.
- Jobs are dispatched after commit. Deletes carry the key (soft deletes their `deleted_at`); a force delete can
  `erase()` the row on a history mirror (configurable).

### Commands

| Command | What it does |
|---|---|
| `db-portable:mirror:schema {mirror?} {--model=*} {--dry-run} {--migration}` | Compares each owner table with its mirror table and creates or alters the mirror table: columns mapped across families (the `Auditor` type checks: widths, integer ranges, JSON), the mirror model's `mirrorSchema()` additions (portable indexes such as `trigramIndex()`, full-text, vector), no foreign keys; XTDB mirrors get `CREATE TABLE (columns)`. `--migration` writes a migration for the mirror connection instead of running the DDL. |
| `db-portable:mirror:data {mirror?} {--model=*} {--since=} {--chunk=500} {--queue} {--prune} {--dry-run}` | Backfills or catches up: keyset batches read from the owner (the `Copier` reader and value mapping) and written by the `MirrorWriter` (upserts with the version guard). `--since` limits to rows changed after a time, `--queue` spreads the batches over queue workers, `--prune` removes mirror rows whose key no longer exists on the owner. |
| `db-portable:mirror:stats {mirror?} {--model=*} {--compare} {--keys} {--json}` | A quick look at whether the two tables differ (see [mirror:stats](#mirrorstats)): each side's own statistics as its server provides them, then, with `--compare`, exact aggregates over the key and the columns both sides share, and with `--keys`, the key ranges and keys that differ. Also the watermark and lag, queued and failed jobs, and relations pointing to missing mirror tables. |
| `db-portable:mirror:sync {mirror?} {--model=*}` | `schema`, then `data`, then `stats`: one command for a new mirror, or after a deployment that changed the owner schema. |
| `db-portable:mirror:flush {mirror} {--model=*}` | Empties the mirror tables (`ERASE` on XTDB). |

### mirror:stats

The owner table and the mirror table are two tables on two servers. `mirror:stats` shows what each server knows
about its table, then compares what the two have in common:

1. **Server statistics** (no scan; estimates, as fresh as the server keeps them), whatever each server offers:

   | Server | Statistics (verified 2026-09-29) |
   |---|---|
   | PostgreSQL | `pg_class.reltuples` (rows), `pg_total_relation_size()`, `pg_stat_user_tables` (live and dead rows, last analyze), `pg_stats` (per column: null fraction, distinct values) |
   | MySQL / MariaDB | `information_schema.tables` (rows, data and index size), index cardinality from `information_schema.statistics` |
   | CockroachDB | `SHOW STATISTICS` (per column: rows, distinct, nulls), `SHOW TABLES` estimated row count |
   | MatrixOne | `mo_table_rows()` / `mo_table_size()` (refreshed asynchronously: 0 right after writes), `metadata_scan()` (rows and nulls of flushed data) |
   | XTDB | none (`pg_stat_user_tables.n_live_tup` stays 0): the row count comes from `count(*)` |
   | SQLite | `sqlite_stat1` after `ANALYZE` |

2. **Comparison** (`--compare`; one aggregate query per side): `count(*)`, and for the key and every column both
   tables share (by name, after `fromOwner()` mapping): null counts, `min` / `max`, `sum` of numeric columns,
   `max(updated_at)` as the lag. Differences are flagged.
3. **Keys** (`--keys`): counts per key range on both sides, then the missing and extra keys of the ranges that
   differ (up to a limit). The fix is `mirror:data` (missing, changed) or `mirror:data --prune` (extra).

## Use cases this enables

| Scenario | Owner | Mirror | Code |
|---|---|---|---|
| Dashboards without loading the OLTP database | PostgreSQL / MySQL / CockroachDB | MatrixOne (`analytics`) | `Analytics\Order::timeWindow('created_at', '1 hour')->select('_wstart')->selectSumWhere(...)` |
| Audit trail and "as of" for any model | any | XTDB (`history`) | `History\Order::whereKey($id)->history()->get()`, `History\Order::asOfValidTime('2026-03-01')->get()` |
| Search the owner cannot do well | MySQL | MatrixOne / CockroachDB | a Scout engine that searches the mirror (`SCOUT_DRIVER=mirror:analytics`) with the driver engines already in crdb2025 / laravel-matrixone |
| Reporting replica | PostgreSQL | PostgreSQL #2 (native logical replication) | `Reporting\Order::...` |
| Joins across "systems" | orders in CockroachDB, customers in PostgreSQL | both mirrored to MatrixOne | analytics joins run locally in MatrixOne |
| Online migration (the original goal, live) | old database | new database | `copy` → mirror / CDC catch-up → `mirror:verify` → switch the owner → reverse mirror during a rollback window |

## Phases

| Phase | Content | Size |
|---|---|---|
| 0. Spikes | Prove the risky parts before designing APIs: (a) queue mirror MySQL/PG → MatrixOne and → XTDB with a version guard; (b) CockroachDB webhook changefeed → HTTPS receiver in Laravel (payload, `resolved`, dedupe); (c) PostgreSQL logical slot polled from PHP (`test_decoding` / `wal2json`, deletes with replica identity); (d) MatrixOne CDC lifecycle from Laravel (PITR, `mo_cdc_task` status, MatrixOne → MySQL sink); (e) XTDB `ATTACH` a PostgreSQL 17 source in docker compose | S each |
| 1. Mirror core (queue engine) | registry, `Mirrored` trait with `#[MirroredAs]`, `MirrorModel` (read-only; relations to mirror models and to owner models, the latter kept on the owner connection; `fromOwner()`, `mirrorSchema()`), observer + queue engine (`versions`: `latest` / `all`), `MirrorWriter` for every family (XTDB experimental), `ValueMapper` from `Copier`, state table, `mirror:schema`, `mirror:data`, `mirror:stats`, `mirror:sync`, `mirror:flush`, docs (`docs/mirrors.md`); **mirror conformance tests** over owner × mirror pairs of the existing conformance servers | L |
| 2. Query side | `fresh()` / `orOwner()`, XTDB history sugar, Scout mirror engine | M |
| 3. Poll engine | watermark pull (`updated_at`, CockroachDB MVCC timestamp, PostgreSQL slot), soft deletes and key reconciliation, scheduling | M |
| 4. Native CDC and replication | contracts + implementations: MatrixOne `CREATE CDC` (MatrixOne/MySQL sinks), CockroachDB changefeeds (webhook receiver; Kafka later), PostgreSQL publication/subscription and slots, XTDB external sources (generated `ATTACH` + node YAML + PostgreSQL setup), MySQL replication commands (generated) | L |
| 5. Online migration | `db-portable:migrate-live` orchestrating copy → sync → verify → cutover → reverse sync, with checkpoints and a runbook | M |

Each phase keeps the existing rules: a change needs tests on every conformance connection it concerns; drivers
implement contracts rather than db-portable calling their methods by name.

## Risks and open questions

- **Writes that bypass Eloquent** (`DB::table()->update()`, mass updates) are invisible to the queue engine:
  `mirrorable()` on the builder, the poll engine, or native CDC cover them; the docs must say which engine catches
  what.
- **Deletes**: hard deletes are only visible to events and native CDC; the poll engine needs soft deletes or key
  reconciliation.
- **Ordering**: queued jobs can run out of order; the version guard (and valid time on XTDB) makes that harmless, but
  every writer must implement it.
- **Schema evolution**: after an owner migration, `mirror:schema` (or `mirror:sync` in the deployment) brings the
  mirror tables up to date; a column dropped on the owner is left on the mirror (reported by `mirror:stats`, never
  dropped automatically).
- **Native features differ in guarantees and cost**: MatrixOne CDC needs a ≥ 2 h PITR (storage); XTDB external
  sources need PostgreSQL 17+ and node configuration, and their API is not final before XTDB 2.2; CockroachDB
  licensing may change; webhook sinks need HTTPS reachability from the database to the application.
- **Secrets**: CDC and changefeed statements embed connection URIs with passwords; they must be built from config,
  masked in `--dry-run` output and never logged.
- **Package boundary**: the three drivers now require db-portable, so queues, HTTP receivers and ops commands would
  reach every driver user. See decisions.

## Decisions to make

1. **Where it lives**: inside laravel-db-portable (one package, optional features), or a sibling package
   (e.g. `vuthaihoc/laravel-db-mirror`) that requires db-portable, keeping db-portable small for the drivers.
   Recommendation: a sibling package; db-portable only gains the contracts and the shared `ValueMapper`.
2. **First pairs** for Phase 0/1. Recommendation: PostgreSQL/MySQL → MatrixOne (analytics) and any → XTDB
   (history), the two capabilities no other package offers.
3. **Consistency default** for `mirror()` reads: plain eventual reads (Scout-like), with `fresh()` opt-in.
