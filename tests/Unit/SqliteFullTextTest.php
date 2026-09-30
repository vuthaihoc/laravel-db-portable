<?php

namespace DbPortable\Tests\Unit;

use DbPortable\Sqlite\FullText;
use DbPortable\Sqlite\QueryGrammar;
use DbPortable\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\ScoutServiceProvider;
use Laravel\Scout\Searchable;
use RuntimeException;

class ScoutArticle extends Model
{
    use Searchable;

    protected $table = 'articles';

    /**
     * @return array<string, mixed>
     */
    #[SearchUsingFullText(['title', 'body'])]
    public function toSearchableArray(): array
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}

/**
 * whereFullText() and $table->fullText() on SQLite, with FTS5 tables.
 */
class SqliteFullTextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('slug')->nullable();
            $table->fullText(['title', 'body']);
        });

        DB::table('articles')->insert([
            ['title' => 'Hà Nội mùa thu', 'body' => 'Phố cổ và hồ Gươm', 'slug' => 'ha-noi'],
            ['title' => 'Sài Gòn', 'body' => 'Chợ Bến Thành, phố đi bộ', 'slug' => 'sai-gon'],
        ]);
    }

    public function test_the_grammars_are_laravels_with_fts5(): void
    {
        $this->assertInstanceOf(QueryGrammar::class, DB::connection()->getQueryGrammar());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(['articles_title_body_fulltext' => ['title', 'body']], FullText::tables(DB::connection(), 'articles'));
    }

    public function test_every_word_matches_ignoring_case_and_accents(): void
    {
        $this->assertSame(['ha-noi'], $this->slugs(fn (Builder $query) => $query->whereFullText(['title', 'body'], 'pho co')));
        $this->assertSame(['ha-noi'], $this->slugs(fn (Builder $query) => $query->whereFullText(['title', 'body'], 'HA NOI')));
        $this->assertSame(['ha-noi', 'sai-gon'], $this->slugs(fn (Builder $query) => $query->whereFullText(['title', 'body'], 'phố')));
        $this->assertSame([], $this->slugs(fn (Builder $query) => $query->whereFullText(['title', 'body'], 'huế')));
        $this->assertSame([], $this->slugs(fn (Builder $query) => $query->whereFullText(['title', 'body'], ' - ')));
        $this->assertSame(['sai-gon'], $this->slugs(fn (Builder $query) => $query->where('slug', 'sai-gon')->orWhereFullText(['title', 'body'], 'huế')));
    }

    public function test_letters_with_a_stroke_are_folded(): void
    {
        DB::table('articles')->insert(['title' => 'Đà Nẵng', 'body' => 'Đường biển Mỹ Khê', 'slug' => 'da-nang']);

        $this->assertSame(['da-nang'], $this->slugs(fn (Builder $query) => $query->whereFullText(['title', 'body'], 'da nang')));
        $this->assertSame(['da-nang'], $this->slugs(fn (Builder $query) => $query->whereFullText(['title', 'body'], 'ĐƯỜNG')));

        FullText::rebuild(DB::connection(), 'articles');
        $this->assertSame(['da-nang'], $this->slugs(fn (Builder $query) => $query->whereFullText(['title', 'body'], 'duong bien')));
        $this->assertSame(['ha-noi'], $this->slugs(fn (Builder $query) => $query->whereFullText(['title', 'body'], 'pho co')));
    }

    public function test_the_index_follows_every_write(): void
    {
        DB::table('articles')->insert(['title' => 'Huế', 'body' => 'Đại Nội và sông Hương', 'slug' => 'hue']);
        DB::statement("update articles set body = 'Hồ Tây, phố Hàng Đào' where slug = 'ha-noi'");
        DB::table('articles')->where('slug', 'sai-gon')->delete();

        $this->assertSame(['hue'], $this->slugs(fn (Builder $query) => $query->whereFullText(['title', 'body'], 'song huong')));
        $this->assertSame(['ha-noi'], $this->slugs(fn (Builder $query) => $query->whereFullText(['title', 'body'], 'hang dao')));
        $this->assertSame([], $this->slugs(fn (Builder $query) => $query->whereFullText(['title', 'body'], 'guom')));
        $this->assertSame([], $this->slugs(fn (Builder $query) => $query->whereFullText(['title', 'body'], 'ben thanh')));
    }

    public function test_modes(): void
    {
        $search = fn (string $text, string $mode) => $this->slugs(fn (Builder $query) => $query->whereFullText(['title', 'body'], $text, ['mode' => $mode]));

        $this->assertSame(['ha-noi'], $search('ho guom', 'phrase'));
        $this->assertSame([], $search('guom ho', 'phrase'));
        $this->assertSame(['ha-noi'], $search('pho -thanh', 'websearch'));
        $this->assertSame(['ha-noi', 'sai-gon'], $search('"ben thanh" OR guom', 'websearch'));
        $this->assertSame(['ha-noi'], $search('gu*', 'websearch'));
        $this->assertSame(['sai-gon'], $search('+pho -co', 'boolean'));
        $this->assertSame(['ha-noi', 'sai-gon'], $search('guom thanh', 'boolean'));
        $this->assertSame(['sai-gon'], $search('pho NOT co', 'raw'));
    }

    public function test_scout_database_engine(): void
    {
        $this->app->register(ScoutServiceProvider::class);
        config(['scout.driver' => 'database']);

        $this->assertSame(['ha-noi'], ScoutArticle::search('pho co')->get()->pluck('slug')->all());
    }

    public function test_some_columns_of_the_index(): void
    {
        $this->assertSame([], $this->slugs(fn (Builder $query) => $query->whereFullText('title', 'pho')));
        $this->assertSame(['ha-noi', 'sai-gon'], $this->slugs(fn (Builder $query) => $query->whereFullText('articles.body', 'pho')));
    }

    public function test_an_index_on_existing_rows_with_its_own_name(): void
    {
        Schema::table('articles', fn (Blueprint $table) => $table->fullText('slug', 'articles_slug_search'));

        $this->assertSame(['ha-noi'], $this->slugs(fn (Builder $query) => $query->whereFullText('slug', 'noi')));
        $this->assertSame(['sai-gon'], $this->slugs(fn (Builder $query) => DB::table('articles as a')->whereFullText('a.slug', 'gon')));
    }

    public function test_relevance(): void
    {
        DB::table('articles')->insert(['title' => 'Phố phố phố', 'body' => 'phố', 'slug' => 'pho']);

        $rows = DB::table('articles')->select('slug')
            ->selectFullTextRelevance(['title', 'body'], 'pho')
            ->searchFullText(['title', 'body'], 'pho')
            ->get();

        $this->assertSame('pho', $rows[0]->slug);
        $this->assertCount(3, $rows);
        $this->assertGreaterThan((float) $rows[2]->relevance, (float) $rows[0]->relevance);
    }

    public function test_tokenizers(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->string('text');
            $table->string('code');
            $table->fullText('text')->language('english');
            $table->fullText('code')->language('trigram');
        });
        DB::table('notes')->insert(['text' => 'The runners were running', 'code' => 'Thành-2026']);

        $this->assertSame(1, DB::table('notes')->whereFullText('text', 'run')->count());
        $this->assertSame(1, DB::table('notes')->whereFullText('code', 'hàn')->count());
        $this->assertSame(0, DB::table('notes')->whereFullText('code', 'han')->count());
    }

    public function test_dropping_the_index_or_the_table(): void
    {
        Schema::table('articles', fn (Blueprint $table) => $table->dropFullText(['title', 'body']));
        $this->assertSame([], FullText::tables(DB::connection(), 'articles'));
        $this->assertSame([], $this->schema('trigger'));
        DB::table('articles')->insert(['title' => 'Huế', 'slug' => 'hue']);

        Schema::table('articles', fn (Blueprint $table) => $table->fullText(['title', 'body']));
        Schema::drop('articles');
        $this->assertSame([], $this->schema('table', 'articles%'));

        // A migration run again.
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->fullText(['title', 'body']);
        });
        $this->assertSame(['articles_title_body_fulltext' => ['title', 'body']], FullText::tables(DB::connection(), 'articles'));
    }

    public function test_without_an_index_a_like_per_word_is_used(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });
        DB::table('pages')->insert([['title' => 'Laravel full text'], ['title' => 'Laravel queues'], ['title' => '100% text']]);
        Log::spy();

        $this->assertSame(1, DB::table('pages')->whereFullText('title', 'TEXT laravel')->count());
        $this->assertSame(1, DB::table('pages')->whereFullText('title', '100%')->count());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'no FTS5 index on pages'))->once();

        config(['db-portable.strict' => true]);
        $this->expectException(RuntimeException::class);
        DB::table('pages')->whereFullText('title', 'laravel')->count();
    }

    public function test_it_can_be_turned_off(): void
    {
        config(['db-portable.sqlite_fulltext' => false]);
        DB::purge();

        $this->assertNotInstanceOf(QueryGrammar::class, DB::connection()->getQueryGrammar());
        $this->expectExceptionMessage('does not support fulltext');
        DB::table('articles')->whereFullText('title', 'pho')->toSql();
    }

    public function test_queries_from_search_text(): void
    {
        $this->assertSame('"pho" "co"', FullText::query(' pho  co '));
        $this->assertSame('"say" """hi"""', FullText::query('say "hi"'));
        $this->assertNull(FullText::query(' - + '));
        $this->assertSame('"ho guom"', FullText::query('ho guom', 'phrase'));
        $this->assertSame('"a" AND ("b" OR "c") NOT "d"', FullText::query('a b OR c -d', 'websearch'));
        $this->assertSame('"gu"* AND "ho ta"*', FullText::query('gu* "ho ta"*', 'websearch'));
        $this->assertNull(FullText::query('-a', 'websearch'));
        $this->assertSame('"a" NOT "b"', FullText::query('+a -b c', 'boolean'));
        $this->assertSame('("a" OR "c") NOT "b"', FullText::query('a c -b', 'boolean'));
        $this->assertSame('a NEAR(b c)', FullText::query('a NEAR(b c)', 'raw'));
        $this->assertSame('{"title"} : ("a")', FullText::match('"a"', ['title'], ['title', 'body']));
        $this->assertSame(
            ['columns' => ['title', 'body'], 'content' => 'posts'],
            FullText::definition("CREATE VIRTUAL TABLE \"x\" using fts5(\"title\", body UNINDEXED, content='posts', tokenize='porter unicode61')"),
        );
        $this->assertNull(FullText::definition('create virtual table x using rtree(id, a, b)'));
    }

    /**
     * @param  callable(Builder): Builder  $search
     * @return list<string>
     */
    private function slugs(callable $search): array
    {
        return $search(DB::table('articles'))->orderBy('slug')->pluck('slug')->all();
    }

    /**
     * @return list<string>
     */
    private function schema(string $type, string $name = '%'): array
    {
        return DB::table('sqlite_master')->where('type', $type)->where('name', 'like', $name)->pluck('name')->all();
    }
}
