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

The drivers implement them (`vuthaihoc/cockroachdb-laravel` 2.5+, `vuthaihoc/laravel-matrixone` 1.2+; older versions conflict with this package). CockroachDB does not accept them in subqueries or inside a transaction (the driver then reads current data). The time read must be after the table was created, and within the database's history retention (MatrixOne: PITR or garbage-collection window).

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
| `searchFullText()`, `*FullTextRelevance()` | `ts_rank` (driver) | `ts_rank` | `match ... against` (driver) | `match ... against` | FTS5 `match` and `bm25()` ([below](#full-text-search-on-sqlite-fts5)) |

The drivers ([cockroachdb-laravel](https://github.com/vuthaihoc/crdb2025) 2.5+, [laravel-matrixone](https://github.com/vuthaihoc/laravel-matrixone) 1.2+) implement these methods themselves; the macros cover the other databases.

#### Full-text search on SQLite (FTS5)

Laravel's `whereFullText()` and `$table->fullText()` throw on SQLite. db-portable implements them with FTS5, the
full-text engine built into PHP's SQLite, without another driver: each SQLite connection gets subclasses of
Laravel's SQLite grammars that add only these.

```php
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->text('body');
    $table->fullText(['title', 'body']);   // SQLite: an FTS5 table kept up to date by triggers
});

Post::whereFullText(['title', 'body'], 'pho co')->get();                          // finds "Phố cổ"
Post::whereFullText('body', '"ho guom" OR ben -cho', ['mode' => 'websearch'])->get();
Post::searchFullText(['title', 'body'], 'pho co')->get();                         // most relevant first (bm25)
```

- `$table->fullText(cols)` creates an FTS5 table named as the index (`posts_title_body_fulltext`) that reads the
  table's rows, indexes the existing ones, and three triggers keep it up to date on every insert, update and delete,
  through Eloquent or not. The default tokenizer ignores case and accents, and folds `đ`, `ł` and `ø` ("da nang" finds
  "Đà Nẵng"); `->language('english')` also stems English words, `->language('trigram')` matches any part of a word of
  3 characters or more.
- `whereFullText()` matches every word, as on PostgreSQL (MySQL's natural language mode matches any word). Its
  `mode` option: `phrase` (the text as one phrase), `websearch` (`"phrases"`, `OR`, `-excluded`, `prefix*`),
  `boolean` (MySQL's `+required -excluded`), `raw` (FTS5 query syntax). It uses the FTS5 table covering the columns,
  whatever its name; without one, a `LIKE` per word, with a warning (an exception with `db-portable.strict`).
- `dropFullText()` drops the FTS5 table and its triggers, and so does dropping the table. Scout's database engine
  (`#[SearchUsingFullText]`) and `db-portable:search-indexes` work with them.
- `'sqlite_fulltext' => false` in `config/db-portable.php` turns this off; a connection with its own grammar is left
  alone.
- FTS5 matches rows by rowid: after a `VACUUM`, a table without an integer primary key needs
  `DbPortable\Sqlite\FullText::rebuild($connection, 'posts')`. Drop the full-text index before renaming the table or
  dropping one of its columns. Each index adds four FTS5 tables to `Schema::getTables()`.

#### Contracts

A query builder that implements these methods itself declares it with the interfaces of `DbPortable\Contracts`, and the macros of the same names then never run on it:

| Interface | Methods | Implemented by |
|---|---|---|
| `HistoricalReads` | `readStale()`, `asOfTime()`, `readCurrent()` | cockroachdb-laravel, laravel-matrixone |
| `SearchBox` | `whereStartsWith()`, `whereContains()`, `suggest()`, `searchFullText()`, `selectFullTextRelevance()`, `orderByFullTextRelevance()` | cockroachdb-laravel, laravel-matrixone |
| `SimilaritySearch` | `whereSimilar()`, `selectSimilarity()`, `orderBySimilarity()` | cockroachdb-laravel |

```php
if ($query instanceof \DbPortable\Contracts\HistoricalReads) { /* the driver compiles the historical read */ }
```

[laravel-xtdb2](https://github.com/vuthaihoc/laravel-xtdb2) implements `HistoricalReads` (`asOfTime()` reads at an XTDB
system time) and `SearchBox`.

#### XTDB (experimental)

`Family::isXtdb()` recognizes laravel-xtdb2's connections (`Family::driver()` returns `xtdb`, and `forDriver()`
accepts an `xtdb` key). Until XTDB 2.2 is released, nothing else is adapted to XTDB and the conformance suite does
not run on it: XTDB's grammars extend PostgreSQL's, so the other macros compile PostgreSQL SQL, which XTDB does not
always accept (e.g. `incrementJson()` uses `jsonb_set()`). MatrixOne has no typo-tolerant search: its `ngram` parser splits only CJK text into n-grams. The trigram threshold of `%` is the session's `pg_trgm.similarity_threshold` (0.3): set it with the connection's `variables` option on CockroachDB.

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
| `jsonIndex()` | `using gin ((col::jsonb))` on PostgreSQL, the expression of `whereJsonContains()`; `using gin (col)` on CockroachDB | skipped | skipped | skipped | skipped |
| `descIndex()` | `(col desc)` | `(col desc)` | `(col desc)` | accepted, built ascending | `(col desc)` |
| `jsonKeyIndex()` | `((col->>'key'))` | functional index `((cast(... as char(255)) collate utf8mb4_bin))` (8.0.13+) | skipped | skipped (no expression indexes) | `((json_extract(...)))` |
| `coveringIndex()` | `(cols) include (extra)` (CockroachDB's `STORING`) | plain index on `cols` | plain index on `cols` | plain index on `cols` | plain index on `cols` |
| `trigramIndex()` | `using gin (col gin_trgm_ops)`; `unaccent: true`: `(unaccent(lower(col)) gin_trgm_ops)` on CockroachDB, the column on PostgreSQL (warning) | fulltext `with parser ngram` | fulltext | fulltext `with parser ngram` (CJK n-grams, whole words otherwise) | skipped |
| `partialIndex()` | `(cols) where ...` | plain index, condition dropped | plain index, condition dropped | plain index, condition dropped | `(cols) where ...` |

Skipped features and dropped conditions log a warning (or throw with `db-portable.strict`). `forDriver()` runs the callback of the connection's driver or family and emits nothing by itself.

The `where` condition of `partialIndex()` is raw SQL: keep it portable (`deleted_at is null`, `status = 'active'`).

## Search indexes for Scout models

`db-portable:search-indexes` reads the Scout attributes of your models and checks that their tables have the indexes the database engines need (`SCOUT_DRIVER=database`, `crdb` or `matrixone`), or writes a migration creating them:

```bash
php artisan db-portable:search-indexes                          # the Searchable models of app/Models
php artisan db-portable:search-indexes "App\Models\Post" --migration
php artisan db-portable:search-indexes --like                   # also the LIKE columns
```

| Declared on `toSearchableArray()` | CockroachDB / PostgreSQL | MatrixOne | MySQL | SQLite |
|---|---|---|---|---|
| `#[SearchUsingFullText(cols, ['language' => ...])]` | `fullText(cols)->language(...)`, matching `whereFullText()` | `fullText(cols)`: **required**, `MATCH` fails without it | `fullText(cols)` | `fullText(cols)`: an FTS5 table |
| `#[SearchUsingFuzzy(cols, unaccent: ...)]` ([cockroachdb-laravel](https://github.com/vuthaihoc/crdb2025) 2.4) | `trigramIndex(col, unaccent: ...)` | none (no trigram similarity) | none | none |
| `#[SearchUsingPrefix(cols)]` | `trigramIndex(col)` (serves `ilike 'x%'`) | `index(col)` | `index(col)` | `index(col)` |
| other columns, with `--like` | `trigramIndex(col)` | none (`LIKE '%x%'` cannot use an index) | none | none |
| `toSearchableEmbedding()` | `vectorIndex(embedding)` | `vectorIndex(embedding)` | none | none |

- Without `--migration` the command lists every index as `ok`, `missing`, `outdated` or `skipped` (with the reason) and fails when one is missing: usable as a CI check.
- Existing indexes are recognized by their definition, whatever their name (e.g. a hand-written `using gin (word gin_trgm_ops)`).
- `outdated`: a CockroachDB full-text index made before cockroachdb-laravel 2.3 (no `coalesce()`) or with another language; the migration drops it first.
- MatrixOne: a table with its own foreign keys or a column already in another FULLTEXT index is reported instead of migrated (4.2.4 crashes on inserts into a table with both a FULLTEXT index and a foreign key; one FULLTEXT index per column).
- The migration is a regular file in `database/migrations` (or `--path`), with `down()`: review and commit it.

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

It reports tables and columns missing from the target, integers outside the target column's range, and strings longer than the target `varchar(n)`. It runs one `min`/`max` query per table on the source. Boolean source columns fit any integer column, and SQLite targets store 64-bit integers whatever the declared type, so neither is range-checked.

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
- Reads in primary-key order (keyset pagination, `--chunk=500`); tables with a composite key or none are read in pages ordered by those columns. `--resume` starts after the highest key already in the target when the key is an integer; other keys are read again from the start.
- Converts values for the target: timestamps with a time zone offset become UTC for MySQL-family `datetime` columns, booleans match the target type, and arrays are encoded as JSON.
- Copies parent tables before the tables whose foreign keys reference them (PostgreSQL and CockroachDB targets keep checking foreign keys), and disables foreign key checks on MySQL-family and SQLite targets while copying.
- Moves the sequences of serial and identity columns on a PostgreSQL or CockroachDB target past the copied keys, so the next insert does not collide.
- Rows are inserted with `insertOrIgnore()`, so a rerun does not duplicate them. Rows the target ignores (duplicates, values it rejects) are reported as `skipped`, and a table whose target ends with fewer rows than the source is reported as `incomplete`; the command then fails, like for a failed table (reported with the rows copied before the error).

## Mirrors: databases in parallel (in progress)

Keep **mirrors** of chosen tables in other databases (MatrixOne for analytics, a second PostgreSQL for reporting, XTDB
for history), synchronised through the queue like Laravel Scout, and read them with Eloquent models:

```php
#[MirroredAs('analytics', Analytics\Order::class)]      // config: 'mirrors' => ['analytics' => ['connection' => 'matrixone']]
class Order extends Model
{
    use Mirrored;
}

Analytics\Order::with('customer')->where('status', 'paid')->latest()->paginate(20);   // customers from the owner
```

Implemented: owner and mirror models, the queue engine, and the `db-portable:mirror:schema`, `mirror:data`,
`mirror:stats`, `mirror:sync`, `mirror:flush` commands, on every pair of SQLite, PostgreSQL, MySQL, CockroachDB and
MatrixOne; XTDB mirrors, including history mirrors that keep every version, are experimental. See
[docs/docs/mirrors.md](docs/docs/mirrors.md) for the API and [docs/plans/parallel-databases.md](docs/plans/parallel-databases.md)
for the plan (sync engines, native change capture per database pair, phases).

## Testing

```bash
composer test
```

The `Unit` suite needs no server. The `Conformance` suite runs the same assertions on SQLite (in memory), MatrixOne, CockroachDB, PostgreSQL and MySQL, and skips a server that is not reachable (set `DB_PORTABLE_REQUIRE_SERVERS=1` to fail instead, as CI does):

```bash
# MatrixOne on 127.0.0.1:6001 (root / 111), see vuthaihoc/laravel-matrixone
docker run -d --name crdb-test -p 127.0.0.1:26258:26257 cockroachdb/cockroach:v26.2.6 \
    start-single-node --insecure --store=type=mem,size=1GiB
docker run -d --name db-portable-pg -p 127.0.0.1:5433:5432 -e POSTGRES_PASSWORD=secret postgres:17
docker run -d --name db-portable-mysql -p 127.0.0.1:3307:3306 -e MYSQL_ROOT_PASSWORD=secret mysql:8.4
```

Override the servers with `MATRIXONE_*`, `CRDB_*`, `PGSQL_*` and `MYSQL_*` (`_HOST`, `_PORT`, `_USERNAME`, `_PASSWORD`; see `phpunit.xml.dist`). The PostgreSQL test database gets the `pg_trgm` and `unaccent` extensions. To test against local checkouts of the drivers, add path repositories to a local copy of `composer.json` (`"repositories": [{"type": "path", "url": "../laravel-matrixone"}]`) and require them as `@dev`.

## License

MIT. See [LICENSE](LICENSE).
