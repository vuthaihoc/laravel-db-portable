# Schema & Blueprint Macros

Database schemas differ considerably across engines in how they handle default JSON values, descending indexes, partial indexes, expression indexes, and full-text/trigram indexes.

**Laravel DB Portable** adds Blueprint macros that compile each feature into its dialect-appropriate DDL statement, or safely skip it with a descriptive warning when a database engine lacks support.

---

## JSON Columns with Defaults

### `jsonWithDefault`

Different databases require different syntax for default JSON values (e.g. MySQL requires parenthesized expressions `default ('[]')`):

```php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

Schema::create('articles', function (Blueprint $table) {
    $table->id();

    // Default JSON array
    $table->jsonWithDefault('tags', []);

    // Default JSON object with binary: true (jsonb on PostgreSQL)
    $table->jsonWithDefault('settings', ['theme' => 'dark'], binary: true);
});
```

| Engine | DDL Generated |
|---|---|
| **PostgreSQL / CockroachDB** | `"tags" json not null default '[]'` (`jsonb` if `binary: true`) |
| **MySQL / MariaDB** | \`tags\` json not null default ('[]') |
| **SQLite** | "tags" text not null default '[]' |
| **MatrixOne** | Column created as nullable; warning logged. Set default in Eloquent `$attributes`. |

---

## Indexing JSON Columns: `jsonIndex`

Creates a GIN index on an entire JSON column for fast containment checks (`whereJsonContains`):

```php
Schema::table('articles', function (Blueprint $table) {
    $table->json('meta')->nullable();
    $table->jsonIndex('meta');
});
```

- **PostgreSQL**: Creates `CREATE INDEX ... USING GIN ((meta::jsonb))`. (PostgreSQL has no GIN opclass for raw `json`, so the cast to `jsonb` is automatically inserted).
- **CockroachDB**: Creates `CREATE INDEX ... USING GIN (meta)`.
- **MySQL / SQLite / MatrixOne**: Skipped with a warning.

---

## Descending Indexes: `descIndex`

Create single or multi-column indexes with explicit ascending and descending directions:

```php
Schema::table('articles', function (Blueprint $table) {
    // Single descending column
    $table->descIndex('published_at');

    // Multi-column index with custom directions
    $table->descIndex(['score' => 'desc', 'id' => 'asc'], 'articles_ranking_idx');
});
```

---

## Portable Indexes

The following macros compile through the `IndexCompiler` and `compilePortableIndex` grammar extension:

### 1. `jsonKeyIndex`
Creates an expression index on a specific JSON key:

```php
$table->jsonKeyIndex('meta->source');
```
- **PostgreSQL**: `CREATE INDEX ... (("meta"->>'source'))`
- **MySQL (8.0.13+)**: Functional index using `cast(json_unquote(...) as char(255)) collate utf8mb4_bin`
- **SQLite**: Index on `((json_extract(meta, '$."source"')))`
- **MariaDB / MatrixOne**: Skipped (no expression index support)

### 2. `coveringIndex`
Creates an index that includes auxiliary columns without adding them to the index search key (CockroachDB's `STORING`, PostgreSQL's `INCLUDE`):

```php
$table->coveringIndex('video_id', ['title', 'slug']);
```
- **PostgreSQL**: `CREATE INDEX ... (video_id) INCLUDE (title, slug)`
- **CockroachDB**: `CREATE INDEX ... (video_id) STORING (title, slug)`
- **Other Databases**: Plain index on `(video_id)`

### 3. `trigramIndex`
Creates a trigram index for fuzzy matching or full-text CJK support:

```php
$table->trigramIndex('word');
$table->trigramIndex('word', unaccent: true); // CockroachDB unaccented trigram
```
- **CockroachDB / PostgreSQL**: GIN index with `gin_trgm_ops` (PostgreSQL requires `CREATE EXTENSION IF NOT EXISTS pg_trgm`).
- **MySQL / MatrixOne**: FULLTEXT index `WITH PARSER ngram`.
- **MariaDB**: Standard FULLTEXT index.
- **SQLite**: Skipped with warning.

### 4. `partialIndex`
Creates a filtered index on rows matching a specific `WHERE` condition:

```php
$table->partialIndex('slug', 'deleted_at is null');
$table->partialIndex(['email', 'tenant_id'], 'is_active = true');
```
- **PostgreSQL / CockroachDB / SQLite**: `CREATE INDEX ... (slug) WHERE deleted_at is null`
- **MySQL / MariaDB / MatrixOne**: Plain index created without `WHERE` condition (warning logged).

---

## Full-Text Indexes on SQLite: `fullText`

Laravel's `$table->fullText()` throws on SQLite; with db-portable it creates an FTS5 table, kept up to date by
triggers, that `whereFullText()` searches:

```php
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->text('body');
    $table->fullText(['title', 'body']);                 // accents and case ignored
    $table->fullText('summary')->language('english');    // English stemming
});
```

See [Full-Text Search on SQLite](/docs/search#full-text-search-on-sqlite-fts5) for the queries, options and limits.

---

## Conditional Migration: `forDriver`

Run driver-specific Blueprint logic or raw statements without messy `if/else` checks:

```php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Inside Blueprint
Schema::create('items', function (Blueprint $table) {
    $table->id();

    $table->forDriver([
        'pgsql' => fn (Blueprint $table) => $table->index('title', null, 'gin'),
        'matrixone' => fn (Blueprint $table) => $table->fullText('title'),
        'mysql,sqlite' => fn (Blueprint $table) => $table->index('title'),
        'default' => fn (Blueprint $table) => $table->index('title'),
    ]);
});

// Outside Blueprint
Schema::forDriver([
    'pgsql' => fn () => DB::statement("CREATE EXTENSION IF NOT EXISTS pg_trgm"),
    'default' => fn () => null,
]);
```
