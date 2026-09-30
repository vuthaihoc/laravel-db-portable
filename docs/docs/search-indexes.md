# Scout Search Indexes

When using **Laravel Scout** with the database engine (`SCOUT_DRIVER=database`, `crdb`, or `matrixone`), models declare search attributes on `toSearchableArray()`. However, the database tables need corresponding full-text, prefix, or trigram indexes to execute those queries efficiently.

The `db-portable:search-indexes` command inspects Scout models, analyzes required indexes against existing database indexes, and generates executable migrations.

---

## Declaring Scout Search Attributes

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use YlsIdeas\CockroachDb\Scout\SearchUsingFuzzy;

class Post extends Model
{
    use Searchable;

    #[SearchUsingFullText(['title', 'body'], ['language' => 'simple'])]
    #[SearchUsingPrefix(['code'])]
    #[SearchUsingFuzzy('author', unaccent: true)]
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'code' => $this->code,
            'author' => $this->author,
        ];
    }

    public function toSearchableEmbedding(): array
    {
        return [1.0, 0.0, 0.0];
    }
}
```

---

## Index Requirements by Database

| Scout Attribute | CockroachDB / PostgreSQL | MatrixOne | MySQL | SQLite |
|---|---|---|---|---|
| `#[SearchUsingFullText(cols)]` | `fullText(cols)->language(...)` | `fullText(cols)` *(Required for `MATCH`)* | `fullText(cols)` | `fullText(cols)`: an FTS5 table ([Search](/docs/search#full-text-search-on-sqlite-fts5)) |
| `#[SearchUsingFuzzy(cols)]` | `trigramIndex(col, unaccent: ...)` | None | None | None |
| `#[SearchUsingPrefix(cols)]` | `trigramIndex(col)` | Plain `index(col)` | Plain `index(col)` | Plain `index(col)` |
| `toSearchableEmbedding()` | `vectorIndex(col)` | `vectorIndex(col)` | None | None |
| Other columns (with `--like`) | `trigramIndex(col)` | None | None | None |

---

## Running the Command

### 1. Verification Mode (CI Check)

Without `--migration`, the command inspects your models and prints a status table:

```bash
# Check all searchable models in app/Models
php artisan db-portable:search-indexes

# Check specific model
php artisan db-portable:search-indexes "App\Models\Post"

# Include LIKE search columns
php artisan db-portable:search-indexes --like
```

The command checks indexes by their **actual SQL definition** (not by name). Statuses reported:
- **`ok`**: The index exists and matches the required definition.
- **`missing`**: The index is absent. The command exits with code `1`, making it ideal for CI verification.
- **`outdated`**: The index exists but needs updating (e.g. CockroachDB fulltext generated with an older grammar or different language). The migration will drop and recreate it.
- **`skipped`**: The database engine does not support this index type.

### 2. Generating Migrations

Pass `--migration` to generate an Artisan migration file containing `up()` and `down()` methods:

```bash
# Create migration in database/migrations
php artisan db-portable:search-indexes --migration

# Create migration in a custom directory
php artisan db-portable:search-indexes --migration --path=database/migrations/custom
```
