# Laravel DB Portable

Query builder helpers that compile for **PostgreSQL / CockroachDB**, **MySQL / MariaDB / MatrixOne** and **SQLite**, plus three Artisan commands for switching an application from one database to another:

- `db-portable:scan` finds database-specific SQL in your code;
- `db-portable:audit` checks that the data of one connection fits the schema of another;
- `db-portable:copy` copies the rows.

It grew out of moving a Laravel application from CockroachDB to MatrixOne. Most of the changes that move needed were raw SQL written for one database (`plan_data->>'amount'`, `::bigint`, `NULLS LAST`, `jsonb_set`), and integer columns that were wider on the old database than on the new one.

## Installation

```bash
composer require vuthaihoc/laravel-db-portable
```

Requires PHP 8.2+ and Laravel 12 or 13. The service provider is discovered automatically. The package works with Laravel's own drivers (PostgreSQL, MySQL, MariaDB, SQLite) and with these third-party drivers:

| Database | Driver | Install |
|----------|--------|---------|
| MatrixOne | [vuthaihoc/laravel-matrixone](https://github.com/vuthaihoc/laravel-matrixone) | `composer require vuthaihoc/laravel-matrixone` |
| CockroachDB | [vuthaihoc/cockroachdb-laravel](https://github.com/vuthaihoc/crdb2025) | `composer require vuthaihoc/cockroachdb-laravel` |

The API may still change before 1.0: pin a minor version (`^0.3`).

## Laravel already covers a lot

Prefer Laravel's own methods where they exist. They compile for every database:

| Instead of | Write |
|------------|-------|
| `whereRaw("flags->>'device' = ?", [$d])` | `where('flags->device', $d)` |
| `whereRaw("(flags->>'sync')::bool is true")` | `where('flags->sync', true)` |
| `whereRaw("jsonb_array_length(meta->'tags') > 0")` | `whereJsonLength('meta->tags', '>', 0)` |
| `whereRaw("meta @> ?", [...])` | `whereJsonContains('meta', [...])` |
| `update(['meta' => DB::raw("jsonb_set(...)")])` with a fixed value | `update(['meta->key' => $value])` |
| `whereRaw('tags::text like ?')`, `ilike` | `whereLike('tags', $pattern)` (case-insensitive by default) |

## Query builder macros

Laravel reads JSON keys as **text**, so numbers compare and sort as strings on PostgreSQL (`"10" < "2"`, `sum(text)` fails). These macros cast per database:

```php
// Numeric comparisons on a JSON key
Video::query()->whereJsonNumber('flags->word_sync_ratio', '>=', 0.8)->get();
Video::query()->whereJsonNumber('flags->ratio', '<', 0.2)->orWhereJsonNumber('flags->ratio', '>', 0.9)->get();

// Numeric ordering, optionally with NULLs (missing keys) last
ToeicExam::query()->orderByJsonNumber('meta->profile_index')->get();
Video::query()->orderByJsonNumber('flags->word_sync_ratio', 'desc', nullsLast: true)->get();

// NULLS LAST for any column (MySQL has no NULLS LAST)
Post::query()->orderByNullsLast('published_at', 'desc')->get();

// Aggregates of a JSON number
DB::table('plan_orders')->where('status', 1)->sumJson('plan_data->amount');
Order::query()->avgJson('meta->total');           // also minJson(), maxJson()

// Increment a JSON counter (a missing key counts as 0; NULL or "[]" starts from {})
Video::query()->whereKey($id)->incrementJson('video_reactions->like');
Video::query()->whereKey($id)->decrementJson('video_reactions->like', 2);
```

The macros are registered on the query builder and on Eloquent builders. Through Eloquent, `incrementJson()` also updates `updated_at`, like `increment()`.

What they compile to:

| Macro | PostgreSQL / CockroachDB | MySQL / MariaDB / MatrixOne | SQLite |
|-------|--------------------------|-----------------------------|--------|
| JSON number | `(col->>'k')::numeric` | `cast(json_unquote(json_extract(col, '$."k"')) as double)` | `cast(json_extract(col, '$."k"') as real)` |
| JSON boolean | `(col->>'k')::boolean` | `json_unquote(json_extract(...)) = 'true'` | `json_extract(...) = 1` |
| `desc` nulls last | `x desc nulls last` | `x desc` (NULLs already last) | `x desc nulls last` |
| `asc` nulls last | `x asc nulls last` | `(x) is null, x asc` | `x asc nulls last` |
| JSON increment | `jsonb_set(<col if object, else '{}'>, '{k}', to_jsonb(... + n), true)` | `json_set(<col if object, else json_object()>, '$."k"', ... + n)` | `json_set(<col if object, else '{}'>, '$."k"', ... + n)` |

### Dashboards: conditional aggregates and subtotals

```php
// Several counts and sums in one query (count(*) FILTER / sum(case ...) without writing either)
DB::table('orders')
    ->selectCountWhere('paid_orders', fn ($q) => $q->where('status', 'paid'))
    ->selectSumWhere('refunded_total', 'total', fn ($q) => $q->where('status', 'refunded'))
    ->selectAggregateWhere('max', 'total', fn ($q) => $q->where('channel', 'ios'), 'ios_max')   // count, sum, avg, min, max
    ->first();

// Subtotals per region and a grand total (rows where the grouped column is NULL)
DB::table('sales')
    ->select('region', 'product')
    ->selectRaw('sum(amount) as total')
    ->groupBy('region', 'product')
    ->rollup()          // call it last
    ->get();
```

| | PostgreSQL | CockroachDB, SQLite | MySQL, MariaDB, MatrixOne |
|---|---|---|---|
| `selectCountWhere()` / `selectSumWhere()` / `selectAggregateWhere()` | `count(case when … then 1 end)`, `sum(case when … then col end)` | same | same |
| `rollup()` | `group by rollup (…)` | `union all` of one query per grouping level | `group by … with rollup` |

On CockroachDB and SQLite, `rollup()` cannot be combined with `having()`, `limit()` or `offset()`, and the `groupBy()` columns must be selected by name.

### Stale and historical reads

Named by intent; the drivers compile them:

```php
Order::query()->readStale()->selectSumWhere('paid', 'total', fn ($q) => $q->where('status', 'paid'))->first();
DB::table('orders')->asOfTime('-10s')->count();              // or a DateTimeInterface
DB::table('orders')->asOfTime(now()->subHour())->readCurrent()->count();   // back to current data
```

| | CockroachDB | MatrixOne | PostgreSQL, MySQL, MariaDB | SQLite |
|---|---|---|---|---|
| `readStale()` | follower read (`AS OF SYSTEM TIME follower_read_timestamp()`, about 4.8 s old) | no change: reads do not contend with writes | no change: Laravel already reads from the `read` connection when one is configured | no change |
| `asOfTime($time)` | `AS OF SYSTEM TIME` | `{as of timestamp '...'}` (in the connection's time zone) | skipped with a warning (throws with `db-portable.strict`) | same |
| `readCurrent()` | removes it | removes it | no change | no change |

They need the drivers' historical reads: `vuthaihoc/cockroachdb-laravel` 2.2.2+ and `vuthaihoc/laravel-matrixone`. CockroachDB does not accept them in subqueries or inside a transaction (the driver then reads current data). The time read must be after the table was created, and within the database's history retention (MatrixOne: PITR or garbage-collection window).

For raw query parts, `Portable` returns the same expressions:

```php
use DbPortable\Portable;

DB::table('plan_orders')->select(Portable::jsonText('flags->device'))->get();       // default connection
$query->selectRaw('sum(' . Portable::on($query)->number('plan_data->amount')->getValue($query->getGrammar()) . ') as total');
Portable::on('crdb')->asText('tags');                                              // "tags"::text
```

### Search boxes and full-text relevance

```php
Word::suggest('word', $search)->limit(10)->get();             // autocomplete
Word::suggest('word', $search, unaccent: true)->limit(10)->get();   // "chao" finds "chào"
Word::whereStartsWith('word', $search)->get();                // % and _ are matched literally
Word::whereContains('word', $search)->get();
Word::whereSimilar('word', $search)->orderBySimilarity('word', $search)->get();   // typo tolerant

Post::searchFullText(['title', 'body'], $search)->get();      // whereFullText(), most relevant first
Post::select('*')->selectFullTextRelevance(['title', 'body'], $search)->get();
```

`suggest()` returns values starting with the search and, from 3 characters, values containing it (or similar to it, with trigrams): prefix matches first, then the most similar, then the shortest.

| | CockroachDB | PostgreSQL | MatrixOne | MySQL, MariaDB | SQLite |
|---|---|---|---|---|---|
| `whereStartsWith()`, `whereContains()` | `ilike`, trigram index | `ilike`, trigram index | `ilike` | `like` (the `_ci` collation) | `like` (ASCII case only) |
| `unaccent: true` | `unaccent(lower(col))` | `unaccent()` (extension) | no effect: accents count | the collation decides | no effect |
| `whereSimilar()`, `orderBySimilarity()` | `%` and `similarity()` (driver) | `%` and `similarity()` (`pg_trgm`) | contains; score 1 prefix / 0.5 contains (warning) | same as MatrixOne | same as MatrixOne |
| `searchFullText()`, `*FullTextRelevance()` | `ts_rank` (driver) | `ts_rank` | `match ... against` (driver) | `match ... against` | no `whereFullText()`; relevance 0 (warning) |

The drivers ([cockroachdb-laravel](https://github.com/vuthaihoc/crdb2025) 2.3+, [laravel-matrixone](https://github.com/vuthaihoc/laravel-matrixone) 1.1+) implement these methods themselves; the macros cover the other databases. MatrixOne has no typo-tolerant search: its `ngram` parser splits only CJK text into n-grams. The trigram threshold of `%` is the session's `pg_trgm.similarity_threshold` (0.3): set it with the connection's `variables` option on CockroachDB.

## Migrations

Blueprint macros for schema features that differ between databases. When a database has no equivalent, the macro skips the feature and logs a warning. Set `config(['db-portable.strict' => true])` to throw instead.

```php
Schema::create('videos', function (Blueprint $table) {
    $table->id();

    // A JSON column with a default value (arrays and scalars are encoded as JSON)
    $table->jsonWithDefault('tags', []);
    $table->jsonWithDefault('settings', ['theme' => 'dark'], binary: true);   // jsonb on PostgreSQL

    // A GIN index on a whole JSON column
    $table->jsonb('meta')->nullable();
    $table->jsonIndex('meta');

    // Descending indexes
    $table->timestamp('published_at')->nullable();
    $table->descIndex('published_at');
    $table->descIndex(['score' => 'desc', 'id' => 'asc'], 'videos_ranking');

    // An index on a JSON key, an index carrying extra columns, fuzzy search, an index on some rows
    $table->jsonKeyIndex('meta->source');
    $table->coveringIndex('video_id', ['title']);
    $table->trigramIndex('title');                    // PostgreSQL needs `create extension pg_trgm`
    $table->partialIndex('slug', 'deleted_at is null');

    // Driver-specific parts: a driver name (crdb, matrixone, mariadb...) wins over its family
    // (pgsql, mysql, sqlite); several keys separated by commas; "default" otherwise.
    $table->forDriver([
        'pgsql' => fn (Blueprint $table) => $table->index('title', null, 'gin'),       // PostgreSQL, CockroachDB
        'matrixone' => fn (Blueprint $table) => $table->fullText('title'),
        'default' => fn (Blueprint $table) => $table->index('title'),
    ]);
});

// Outside a blueprint, e.g. raw statements
Schema::forDriver([
    'pgsql' => fn () => DB::statement("create index files_meta_source on files ((meta->>'source'))"),
    'default' => fn () => null,
]);
```

| Macro | PostgreSQL / CockroachDB | MySQL | MariaDB | MatrixOne | SQLite |
|-------|--------------------------|-------|---------|-----------|--------|
| `jsonWithDefault()` | `default '[]'` | `default ('[]')` | `default ('[]')` | skipped: the column is nullable, set the default in the model's `$attributes` | `default '[]'` |
| `jsonIndex()` | `using gin` (use `jsonb()` on PostgreSQL) | skipped | skipped | skipped | skipped |
| `descIndex()` | `(col desc)` | `(col desc)` | `(col desc)` | accepted, built ascending | `(col desc)` |
| `jsonKeyIndex()` | `((col->>'key'))` | functional index `((cast(... as char(255)) collate utf8mb4_bin))` (8.0.13+) | skipped | skipped (no expression indexes) | `((json_extract(...)))` |
| `coveringIndex()` | `(cols) include (extra)` (CockroachDB's `STORING`) | plain index on `cols` | plain index on `cols` | plain index on `cols` | plain index on `cols` |
| `trigramIndex()` | `using gin (col gin_trgm_ops)`; `unaccent: true`: `(unaccent(lower(col)) gin_trgm_ops)` on CockroachDB, the column on PostgreSQL (warning) | fulltext `with parser ngram` | fulltext | fulltext `with parser ngram` (CJK n-grams, whole words otherwise) | skipped |
| `partialIndex()` | `(cols) where ...` | plain index, condition dropped | plain index, condition dropped | plain index, condition dropped | `(cols) where ...` |

Skipped features and dropped conditions log a warning (or throw with `db-portable.strict`). `forDriver()` runs the callback of the connection's driver or family and emits nothing by itself.

The `where` condition of `partialIndex()` is raw SQL: keep it portable (`deleted_at is null`, `status = 'active'`).

## Switching databases

A suggested workflow, e.g. from CockroachDB (`crdb`) to MatrixOne (`matrixone`):

1. **Scan** the code for SQL the new database will reject, and rewrite it with the methods above.
2. **Migrate** the new database: `php artisan migrate --database=matrixone`.
3. **Audit** the data against the new schema, and widen the columns it reports.
4. **Copy** the data.
5. Point `DB_CONNECTION` at the new database and run your test suite.

### Scan

```bash
php artisan db-portable:scan --target=matrixone          # app/, database/, routes/
php artisan db-portable:scan app/Filament --target=mysql --target=sqlite
php artisan db-portable:scan --json --fail               # for CI
```

The scanner reads the string literals of your PHP files (not comments or code) and reports each construct with the families it breaks on and a replacement:

```
pg-json-operator breaks on mysql, matrixone, sqlite ............ 11 place(s)
  Use: where('col->key', ...), whereJsonNumber(), orderByJsonNumber(), Portable::jsonText()/jsonNumber()
  app/GraphQL/Queries/SituationTopicQuery.php:254  p.settings->>'level'
```

Targets: `mysql` (MySQL, MariaDB), `matrixone`, `pgsql` (PostgreSQL, CockroachDB), `sqlite`. Code that already branches per driver is still reported, because the scanner cannot tell which branch runs.

### Audit

```bash
php artisan db-portable:audit --from=crdb --to=matrixone
php artisan db-portable:audit --from=crdb --to=matrixone --table=videos --table=toeic_exam_user_logs
```

It reports tables and columns missing from the target, integers outside the target column's range, and strings longer than the target `varchar(n)`. It runs one `min`/`max` query per table on the source.

```
| videos               | view_count | integer out of range | int (max 2147483647) but the source has 15950438052     |
| toeic_exam_user_logs | exam_score | integer out of range | tinyint unsigned (max 255) but the source has 500       |
```

### Copy

```bash
php artisan db-portable:copy --from=crdb --to=matrixone --dry-run      # row counts
php artisan db-portable:copy --from=crdb --to=matrixone                # every table on both sides
php artisan db-portable:copy --from=crdb --to=matrixone --table=users --table=videos
php artisan db-portable:copy --from=crdb --to=matrixone --sample=500   # the 500 latest rows of each table
php artisan db-portable:copy --from=crdb --to=matrixone --resume       # continue after an interruption
```

- Copies the columns present on both sides and skips `migrations` (change it with `--except`).
- Reads in primary-key order (keyset pagination, `--chunk=500`). `--resume` starts after the highest key already in the target.
- Converts values for the target: timestamps with a time zone offset become UTC for MySQL-family `datetime` columns, booleans match the target type, and arrays are encoded as JSON.
- Disables foreign key checks on MySQL-family and SQLite targets while copying. Rows are inserted with `insertOrIgnore()`, so a rerun does not duplicate them.

## Testing

```bash
composer test
```

The `Unit` suite needs no server. The `Conformance` suite runs the same assertions on SQLite (in memory), MatrixOne and CockroachDB, and skips a server that is not reachable:

```bash
# MatrixOne on 127.0.0.1:6001 (root / 111), see vuthaihoc/laravel-matrixone
docker run -d --name crdb-test -p 127.0.0.1:26258:26257 cockroachdb/cockroach:v26.2.6 \
    start-single-node --insecure --store=type=mem,size=1GiB
```

Override the servers with `MATRIXONE_HOST`, `MATRIXONE_PORT`, `CRDB_HOST`, `CRDB_PORT` (see `phpunit.xml.dist`). To test against local checkouts of the drivers, add path repositories to a local copy of `composer.json` (`"repositories": [{"type": "path", "url": "../laravel-matrixone"}]`) and require them as `@dev`.

## License

MIT. See [LICENSE](LICENSE).
