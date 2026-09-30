# Search & Autocomplete

Search features such as autocomplete, typo tolerance, accent removal, and full-text relevance vary significantly between database families. **Laravel DB Portable** normalizes these into an intuitive set of builder methods.

---

## Prefix and Substring Matching

Standard `LIKE` queries often treat `%` and `_` as wildcard operators, leading to incorrect matches or SQL injection vulnerabilities when handling user input.

`whereStartsWith` and `whereContains` automatically escape `%` and `_` wildcards so they match literally:

```php
use App\Models\Word;

// Matches "apple", "Application", "apply" (case-insensitive)
Word::query()->whereStartsWith('word', 'APP')->get();

// Literal search for special characters: matches "50% off"
Word::query()->whereContains('word', '50% o')->get();

// Substring match: matches "apple", "pineapple"
Word::query()->whereContains('word', 'pple')->get();
```

---

## Autocomplete Suggestions: `suggest()`

`suggest()` provides an out-of-the-box autocomplete implementation:
1. Returns prefix matches first.
2. From 3 characters onwards, includes substring and trigram similarity matches.
3. Orders results by relevance, then shortest length, then alphabetically.

```php
// Basic autocomplete
Word::suggest('word', 'app')->limit(10)->get();

// Accent-insensitive autocomplete: "chao" finds "chào" and "cháo"
Word::suggest('word', 'chao', unaccent: true)->limit(10)->get();
```

---

## Typo Tolerance & Trigram Similarity

### `whereSimilar()` & `orderBySimilarity()`

Performs fuzzy, typo-tolerant string matching:

```php
// User types "aple" (misspelled); matches "apple"
Word::query()
    ->whereSimilar('word', 'aple')
    ->orderBySimilarity('word', 'aple')
    ->get();
```

### Behaviour Across Engines

| Database | Implementation |
|---|---|
| **CockroachDB** | Native trigram operator `%` and `similarity()` function. |
| **PostgreSQL** | Uses `pg_trgm` extension (`%` and `similarity()`). |
| **MySQL / MariaDB / MatrixOne** | Falls back to `whereContains()` with a warning (or throws in strict mode). |
| **SQLite** | Falls back to `whereContains()` with a warning (or throws in strict mode). |

---

## Full-Text Relevance

Combine full-text matching with ranking relevance:

```php
use App\Models\Article;

// Filter and order by relevance (most relevant first)
Article::query()->searchFullText(['title', 'body'], 'database indexing')->get();

// Explicit relevance score selection
Article::query()
    ->select('id', 'title')
    ->selectFullTextRelevance(['title', 'body'], 'database indexing')
    ->searchFullText(['title', 'body'], 'database indexing')
    ->paginate(20);
```

### Modes and Options

Options can be customized via the `$options` parameter:

```php
$options = [
    'mode' => 'websearch', // 'natural', 'boolean', 'websearch'
    'language' => 'simple', // tsquery language dictionary (Postgres/CockroachDB)
];

Article::query()->searchFullText('title', 'postgres AND cockroach', $options)->get();
```

## Full-Text Search on SQLite (FTS5)

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

---

## Driver Capabilities Summary

| Feature | CockroachDB | PostgreSQL | MatrixOne | MySQL / MariaDB | SQLite |
|---|---|---|---|---|---|
| `whereStartsWith()` | `ILIKE` | `ILIKE` | `ILIKE` | `LIKE` (`_ci` collation) | `LIKE` (ASCII) |
| `whereContains()` | `ILIKE` | `ILIKE` | `ILIKE` | `LIKE` (`_ci` collation) | `LIKE` (ASCII) |
| `unaccent: true` | `unaccent(lower(col))` | `unaccent()` extension | Collation-dependent | Collation-dependent | Not supported |
| `whereSimilar()` | Trigram `%` | Trigram `%` (`pg_trgm`) | Contains fallback | Contains fallback | Contains fallback |
| `searchFullText()` | `ts_rank` | `ts_rank` | `MATCH ... AGAINST` | `MATCH ... AGAINST` | FTS5 `MATCH`, `bm25()` |
