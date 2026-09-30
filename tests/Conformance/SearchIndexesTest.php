<?php

namespace DbPortable\Tests\Conformance;

use DbPortable\Console\SearchIndexesCommand;
use DbPortable\Search\SearchIndex;
use DbPortable\Search\SearchIndexPlanner;
use DbPortable\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Laravel\Scout\Searchable;
use PHPUnit\Framework\Attributes\DataProvider;
use YlsIdeas\CockroachDb\Scout\SearchUsingFuzzy;

class SearchPost extends Model
{
    use Searchable;

    protected $table = 'search_posts';

    #[SearchUsingFullText(['title', 'body'], ['language' => 'simple'])]
    #[SearchUsingPrefix(['code'])]
    #[SearchUsingFuzzy('word', unaccent: true)]
    public function toSearchableArray(): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'body' => $this->body, 'code' => $this->code, 'word' => $this->word, 'note' => $this->note];
    }

    public function toSearchableEmbedding(): array
    {
        return [1.0, 0.0, 0.0];
    }
}

class SearchIndexesTest extends TestCase
{
    private string $connection;

    private function useConnection(string $connection): void
    {
        $this->requireConnection($connection);
        $this->connection = $connection;

        $schema = Schema::connection($connection);
        $schema->dropIfExists('search_posts');
        $schema->create('search_posts', function (Blueprint $table) use ($connection) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('code');
            $table->string('word');
            $table->string('note')->nullable();

            if ($connection !== 'sqlite') {
                $table->vector('embedding', 3)->nullable();
            }
        });
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            Schema::connection($this->connection)->dropIfExists('search_posts');
        }

        parent::tearDown();
    }

    /**
     * @return array<string, string> "kind columns" => status
     */
    private function statuses(bool $like = false): array
    {
        $plans = (new SearchIndexPlanner)->plan((new SearchPost)->setConnection($this->connection), $like);

        return collect($plans)->mapWithKeys(fn (SearchIndex $index) => [$index->kind.' '.implode(',', $index->columns) => $index->status])->all();
    }

    /**
     * The generated migration for the missing indexes.
     */
    private function migration(): object
    {
        $plans = (new SearchIndexPlanner)->plan((new SearchPost)->setConnection($this->connection));
        $file = sys_get_temp_dir().'/db_portable_search_'.uniqid().'.php';
        file_put_contents($file, app(SearchIndexesCommand::class)->migration(array_values(array_filter($plans, fn ($index) => $index->needsMigration()))));
        $migration = require $file;
        unlink($file);

        return $migration;
    }

    public static function expected(): array
    {
        return [
            'crdb' => ['crdb', [
                'fulltext title,body' => 'missing', 'trigram word' => 'missing', 'trigram code' => 'missing', 'vector embedding' => 'missing',
            ]],
            'matrixone' => ['matrixone', [
                'fulltext title,body' => 'missing', 'trigram word' => 'skipped', 'index code' => 'missing', 'vector embedding' => 'missing',
            ]],
            'sqlite' => ['sqlite', [
                'fulltext title,body' => 'missing', 'trigram word' => 'skipped', 'index code' => 'missing', 'vector embedding' => 'skipped',
            ]],
        ];
    }

    /**
     * @param  array<string, string>  $expected
     */
    #[DataProvider('expected')]
    public function test_plans_and_migrates_the_search_indexes(string $connection, array $expected): void
    {
        $this->useConnection($connection);

        $this->assertSame($expected, $this->statuses());

        $migration = $this->migration();
        $migration->up();
        $this->assertSame(array_map(fn ($status) => $status === 'missing' ? 'ok' : $status, $expected), $this->statuses());

        $migration->down();
        $this->assertSame($expected, $this->statuses());
    }

    public function test_like_columns(): void
    {
        $this->useConnection('crdb');

        $this->assertSame('missing', $this->statuses(like: true)['trigram note']);
        $this->assertArrayNotHasKey('trigram id', $this->statuses(like: true));
        $this->assertArrayNotHasKey('trigram note', $this->statuses());
    }

    public function test_outdated_cockroachdb_full_text_index(): void
    {
        $this->useConnection('crdb');
        DB::connection('crdb')->statement("create index search_posts_old_fulltext on search_posts using gin (to_tsvector('simple', (title) || ' ' || (body)))");

        $plans = (new SearchIndexPlanner)->plan((new SearchPost)->setConnection('crdb'));
        $fullText = collect($plans)->firstWhere('kind', 'fulltext');

        $this->assertSame(SearchIndex::OUTDATED, $fullText->status);
        $this->assertSame('search_posts_old_fulltext', $fullText->replaces);

        $this->migration()->up();
        $this->assertSame('ok', $this->statuses()['fulltext title,body']);
    }

    public function test_the_command_writes_a_migration(): void
    {
        $this->useConnection('sqlite');
        $directory = sys_get_temp_dir().'/db_portable_migrations_'.uniqid();

        config(['database.default' => 'sqlite']);
        $this->artisan('db-portable:search-indexes', ['models' => [SearchPost::class]])->assertFailed();
        $this->artisan('db-portable:search-indexes', ['models' => [SearchPost::class], '--migration' => true, '--path' => $directory])->assertSuccessful();

        $files = glob($directory.'/*_add_search_indexes.php') ?: [];
        $this->assertCount(1, $files);
        $this->assertStringContainsString("\$table->index('code');", (string) file_get_contents($files[0]));
        array_map('unlink', $files);
        rmdir($directory);
    }
}
