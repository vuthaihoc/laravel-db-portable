# Historical & Stale Reads

Distributed databases like **CockroachDB** and **MatrixOne** offer historical reads (also known as Time Travel or Point-in-Time queries) and Follower Reads. These allow applications to read past consistent snapshots or slightly stale data without contending with active write locks.

**Laravel DB Portable** exposes these capabilities using intent-based query builder macros.

---

## Stale Reads: `readStale()`

In distributed multi-region databases, querying the primary leaseholder can introduce latency. `readStale()` routes queries to local follower replicas:

```php
use App\Models\Order;

// Read from follower replicas (e.g. CockroachDB follower_read_timestamp(), ~4.8s lag)
Order::query()
    ->readStale()
    ->selectSumWhere('paid', 'total', fn ($q) => $q->where('status', 'paid'))
    ->first();
```

### Driver Behaviour

| Database | Behaviour |
|---|---|
| **CockroachDB** | Adds `AS OF SYSTEM TIME follower_read_timestamp()`. |
| **MatrixOne** | Unchanged: MatrixOne reads do not contend with writes. |
| **PostgreSQL / MySQL / SQLite** | Unchanged: If a separate read connection is configured in Laravel (`database.connections.<name>.read`), Laravel routes reads there. |

---

## Point-in-Time Queries: `asOfTime()`

Query data as it existed at a specific moment in the past.

```php
use Illuminate\Support\Facades\DB;

// Query as of 10 seconds ago
DB::table('orders')->asOfTime('-10s')->count();

// Query using a DateTimeInterface
DB::table('orders')->asOfTime(now()->subHours(2))->sum('total');
```

### Resetting to Current Data: `readCurrent()`

Chain `readCurrent()` to remove any previously configured historical timestamp:

```php
$query = DB::table('orders')->asOfTime('-1m');

// Decide conditionally to read current data instead
if ($userNeedsLatest) {
    $query->readCurrent();
}

$count = $query->count();
```

---

## Database Compatibility Matrix

| Macro | CockroachDB | MatrixOne | PostgreSQL / MySQL / MariaDB / SQLite |
|---|---|---|---|
| `readStale()` | `AS OF SYSTEM TIME follower_read_timestamp()` | No change | No change |
| `asOfTime($time)` | `AS OF SYSTEM TIME ...` | `{as of timestamp '...'}` | Logs warning (Throws in `strict` mode) |
| `readCurrent()` | Clears historical read | Clears historical read | No change |

> [!NOTE]
> The target time must be after the table was created and within the database's configured retention window (MatrixOne PITR / GC window, or CockroachDB `gc.ttlseconds`).

---

## Driver Contracts

When a driver natively provides these methods, it implements the contracts in `DbPortable\Contracts`. The package macros detect these interfaces and delegate directly to the driver's implementation:

| Contract | Provided Methods | Implemented By |
|---|---|---|
| `DbPortable\Contracts\HistoricalReads` | `readStale()`, `asOfTime()`, `readCurrent()` | `cockroachdb-laravel`, `laravel-matrixone`, `laravel-xtdb2` |
| `DbPortable\Contracts\SearchBox` | `whereStartsWith()`, `whereContains()`, `suggest()`, `searchFullText()`, `selectFullTextRelevance()`, `orderByFullTextRelevance()` | `cockroachdb-laravel`, `laravel-matrixone` |
| `DbPortable\Contracts\SimilaritySearch` | `whereSimilar()`, `selectSimilarity()`, `orderBySimilarity()` | `cockroachdb-laravel` |

```php
if ($query instanceof \DbPortable\Contracts\HistoricalReads) {
    // Compiled directly by the driver without macro overhead
}
```

---

## Experimental XTDB Integration

`Family::isXtdb()` identifies connections backed by [laravel-xtdb2](https://github.com/vuthaihoc/laravel-xtdb2). XTDB 2.x natively supports bitemporal queries (system-time and valid-time). As XTDB 2.2 matures, full historical queries will be integrated into the conformance suite.
