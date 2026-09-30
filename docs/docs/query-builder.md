# Query Builder Macros

Laravel's built-in query builder treats JSON keys as strings. On PostgreSQL and SQLite, comparing or ordering by JSON values compares them as text:
- `"10" < "2"` evaluates to `true` (alphabetical string comparison).
- Aggregates such as `sum()` on JSON fields fail because string values cannot be summed directly.
- Increments on nested JSON fields require database-specific functions (`jsonb_set` vs `json_set`).

**Laravel DB Portable** introduces query builder and Eloquent macros that compile to the correct syntax for each database engine.

---

## Filtering JSON Numbers

### `whereJsonNumber` & `orWhereJsonNumber`

Filters rows using numeric comparison on a JSON attribute:

```php
use App\Models\Video;

// Filter videos where ratio >= 0.8
Video::query()->whereJsonNumber('flags->word_sync_ratio', '>=', 0.8)->get();

// Compound conditions with orWhereJsonNumber
Video::query()
    ->whereJsonNumber('flags->ratio', '<', 0.2)
    ->orWhereJsonNumber('flags->ratio', '>', 0.9)
    ->get();

// Matching exact numbers or integers
Video::query()->whereJsonNumber('meta->amount', '>', 1000)->get();
```

Path notation must contain `->` (e.g. `'col->key'` or nested `'col->a->b'`). Keys are automatically quoted and escaped.

---

## Sorting Numbers and NULLs

### `orderByJsonNumber`

Sorts numerically by a JSON number field. When sorting as text, `"10"` comes before `"2"`; `orderByJsonNumber` ensures correct numeric ordering:

```php
// Ascending numeric order
ToeicExam::query()->orderByJsonNumber('meta->profile_index')->get();

// Descending order with NULLs (or missing keys) placed last
Video::query()->orderByJsonNumber('flags->word_sync_ratio', 'desc', nullsLast: true)->get();

// Ascending order with NULLs last
Video::query()->orderByJsonNumber('meta->profile_index', 'asc', nullsLast: true)->get();
```

### `orderByNullsLast`

PostgreSQL puts `NULL`s first in descending order; MySQL lacks `NULLS LAST` syntax and puts `NULL`s last in descending order. `orderByNullsLast` standardizes `NULLS LAST` sorting across all database families:

```php
// Published posts first, draft posts (published_at is null) last
Post::query()->orderByNullsLast('published_at', 'desc')->get();

// Ascending order with nulls last
User::query()->orderByNullsLast('last_active_at', 'asc')->get();
```

---

## JSON Aggregates

Compute aggregates on numbers inside JSON objects. Missing keys or rows where the column is null evaluate gracefully (returning `0` for `sumJson`, or `null` for `minJson`/`maxJson`/`avgJson` when no rows match):

```php
use Illuminate\Support\Facades\DB;
use App\Models\Order;

// Sum of JSON numbers
$total = DB::table('plan_orders')->where('status', 1)->sumJson('plan_data->amount');

// Averages, minimums, and maximums
$average = Order::query()->avgJson('meta->total');
$cheapest = Order::query()->minJson('meta->total');
$highest = Order::query()->maxJson('meta->total');
```

---

## JSON Increments & Decrements

### `incrementJson` & `decrementJson`

Atomically increment or decrement numeric values stored in JSON documents:

```php
use App\Models\Video;

// Increment an integer counter (defaults missing keys or non-objects to 0 + amount)
Video::query()->whereKey($id)->incrementJson('video_reactions->like');

// Decrement by a custom amount
Video::query()->whereKey($id)->decrementJson('video_reactions->dislike', 2);

// Increment floating-point numbers
Video::query()->whereKey($id)->incrementJson('meta->score', 0.5);

// Nested paths
Video::query()->whereKey($id)->incrementJson('meta->analytics->views', 1);
```

#### Safe Non-Object Handling
If the JSON column is currently `NULL`, an empty array (`"[]"`), or a non-object value, `incrementJson` initializes it to an object (`{}`) before setting the key, preventing runtime SQL errors across all drivers.

#### Eloquent Timestamps
When called on an **Eloquent builder**, `incrementJson()` automatically updates `updated_at`, mirroring Eloquent's standard `increment()` behaviour.

---

## Compiled SQL Comparison

| Operation | PostgreSQL / CockroachDB | MySQL / MariaDB / MatrixOne | SQLite |
|---|---|---|---|
| `whereJsonNumber('col->k', '>=', 10)` | `("col"->>'k')::numeric >= ?` | `cast(json_unquote(json_extract(\`col\`, '$."k"')) as double) >= ?` | `cast(json_extract("col", '$."k"') as real) >= ?` |
| `orderByJsonNumber('col->k', 'desc')` | `("col"->>'k')::numeric desc` | `cast(json_unquote(json_extract(\`col\`, '$."k"')) as double) desc` | `cast(json_extract("col", '$."k"') as real) desc` |
| `orderByNullsLast('x', 'desc')` | `x desc nulls last` | `x desc` *(NULLs already last in MySQL)* | `x desc nulls last` |
| `orderByNullsLast('x', 'asc')` | `x asc nulls last` | `(x) is null, x asc` | `x asc nulls last` |
| `incrementJson('col->k', 1)` | `jsonb_set(..., '{"k"}', to_jsonb(coalesce(("col"->>'k')::numeric, 0) + 1), true)` | `json_set(..., '$."k"', coalesce(cast(json_unquote(json_extract(...)) as signed), 0) + 1)` | `json_set(..., '$."k"', coalesce(cast(json_extract(...) as real), 0) + 1)` |

---

## Raw SQL Expressions: `Portable`

When constructing complex `selectRaw`, `whereRaw`, or `DB::raw` expressions, use the `DbPortable\Portable` helper to produce dialect-aware expressions:

```php
use DbPortable\Portable;
use Illuminate\Support\Facades\DB;

// Extract JSON value as text
DB::table('orders')->select(Portable::jsonText('flags->device'))->get();

// Specific connection or query builder
$totalSql = Portable::on('crdb')->number('plan_data->amount')->getValue($grammar);

// Casting text portably
Portable::on('mysql')->castText('tags'); // cast(`tags` as char)
Portable::on('matrixone')->castText('tags'); // cast(`tags` as text)
Portable::on('pgsql')->castText('tags'); // "tags"::text

// Boolean extraction
$query->whereRaw(Portable::on($query)->bool('meta->sync')->getValue($grammar));

// Portable character length
$lengthSql = Portable::on('sqlite')->charLength('username'); // length("username")
```

`Portable::on()` accepts:
- Connection name string (e.g. `'pgsql'`, `'matrixone'`)
- Connection instance
- Query Builder instance (`$query`)
- Eloquent Builder instance (`Order::query()`)
- Eloquent Relationship instance (`$order->items()`)
