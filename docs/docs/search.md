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

---

## Driver Capabilities Summary

| Feature | CockroachDB | PostgreSQL | MatrixOne | MySQL / MariaDB | SQLite |
|---|---|---|---|---|---|
| `whereStartsWith()` | `ILIKE` | `ILIKE` | `ILIKE` | `LIKE` (`_ci` collation) | `LIKE` (ASCII) |
| `whereContains()` | `ILIKE` | `ILIKE` | `ILIKE` | `LIKE` (`_ci` collation) | `LIKE` (ASCII) |
| `unaccent: true` | `unaccent(lower(col))` | `unaccent()` extension | Collation-dependent | Collation-dependent | Not supported |
| `whereSimilar()` | Trigram `%` | Trigram `%` (`pg_trgm`) | Contains fallback | Contains fallback | Contains fallback |
| `searchFullText()` | `ts_rank` | `ts_rank` | `MATCH ... AGAINST` | `MATCH ... AGAINST` | Skipped with warning |
