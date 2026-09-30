# Analytics & ROLLUP

Building dashboard metrics and aggregated reports usually requires running multiple queries or resorting to raw SQL with `CASE WHEN` or `FILTER (WHERE ...)`. Furthermore, multidimensional subtotals (`ROLLUP`) are implemented differently across database engines.

**Laravel DB Portable** provides macro helpers that make analytics queries and rollups completely portable.

---

## Conditional Aggregates

Compute multiple counts, sums, or other aggregates filtered by different conditions in a single query:

```php
use Illuminate\Support\Facades\DB;

$metrics = DB::table('orders')
    // Count orders where status is 'paid'
    ->selectCountWhere('paid_orders', fn ($q) => $q->where('status', 'paid'))
    // Sum amount where status is 'refunded' and amount > 50
    ->selectSumWhere('refunded_total', 'amount', fn ($q) => $q->where('status', 'refunded')->where('amount', '>', 50))
    // Generic aggregate: max amount for iOS channel
    ->selectAggregateWhere('max', 'amount', fn ($q) => $q->where('channel', 'ios'), 'ios_max')
    ->first();

echo $metrics->paid_orders;
echo $metrics->refunded_total;
echo $metrics->ios_max;
```

### Supported Aggregates

The `selectAggregateWhere($aggregate, $column, $where, $as)` macro supports:
- `count`
- `sum`
- `avg`
- `min`
- `max`

Unknown aggregates throw an `\InvalidArgumentException`.

### Grouping with Conditional Aggregates

Conditional aggregates integrate seamlessly with `groupBy()`:

```php
$salesByRegion = DB::table('orders')
    ->select('region')
    ->selectSumWhere('paid_total', 'amount', fn ($q) => $q->where('status', 'paid'))
    ->selectSumWhere('refunded_total', 'amount', fn ($q) => $q->where('status', 'refunded'))
    ->groupBy('region')
    ->orderBy('region')
    ->get();
```

---

## Multidimensional Subtotals: `rollup()`

`rollup()` computes hierarchical subtotals and a grand total in a single database pass:

```php
$report = DB::table('sales')
    ->select('region', 'product')
    ->selectRaw('sum(amount) as total')
    ->groupBy('region', 'product')
    ->rollup()
    ->get();
```

In the resulting rows:
- `region = 'north'`, `product = 'widget'`: Total for that specific combination.
- `region = 'north'`, `product = null`: Subtotal for all products in `'north'`.
- `region = null`, `product = null`: Grand total for all regions and products.

### Compilation per Database Engine

| Database | Compiled SQL |
|---|---|
| **PostgreSQL** | `select "region", "product", sum(amount) as total from "sales" group by rollup ("region", "product")` |
| **MySQL / MariaDB / MatrixOne** | `select \`region\`, \`product\`, sum(amount) as total from \`sales\` group by \`region\`, \`product\` with rollup` |
| **CockroachDB / SQLite** | `union all` of one query per grouping level (e.g. `(select region, product, sum(amount) ...) union all (select region, null, sum(amount) ...) union all (select null, null, sum(amount) ...)` |

### Limitations on CockroachDB & SQLite

Because CockroachDB and SQLite lack native `ROLLUP` syntax, the macro simulates it by executing a `UNION ALL` across each grouping level:
- You must call `groupBy()` before calling `rollup()`.
- Grouping columns must be explicitly selected by name in `select()`.
- `rollup()` cannot be combined with `having()`, `limit()`, or `offset()`.
