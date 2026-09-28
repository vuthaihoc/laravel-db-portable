<?php

namespace DbPortable\Tests\Conformance;

use DbPortable\Tests\TestCase;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The search box helpers: the drivers' own methods on CockroachDB and
 * MatrixOne, the macros on SQLite (and the similarity fallbacks on MatrixOne).
 */
class SearchMacrosTest extends TestCase
{
    private string $connection;

    private function useConnection(string $connection): void
    {
        $this->requireConnection($connection);
        $this->connection = $connection;

        $schema = Schema::connection($connection);
        $schema->dropIfExists('portable_words');
        $schema->create('portable_words', function (Blueprint $table) {
            $table->id();
            $table->string('word');
            $table->string('phrase')->nullable();
            $table->trigramIndex('word');
        });

        $this->table()->insert(array_map(fn ($word) => ['word' => $word], [
            'apple', 'Application', 'apply', 'pineapple', '50% off', '50 of',
        ]));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            Schema::connection($this->connection)->dropIfExists('portable_words');
        }

        parent::tearDown();
    }

    private function table(): Builder
    {
        return DB::connection($this->connection)->table('portable_words');
    }

    /**
     * @return list<string>
     */
    private function words(Builder $query): array
    {
        return $query->pluck('word')->all();
    }

    #[DataProvider('connections')]
    public function test_prefix_and_substring(string $connection): void
    {
        $this->useConnection($connection);

        $this->assertSame(['apple', 'Application', 'apply'], $this->words($this->table()->whereStartsWith('word', 'APP')->orderBy('id')));
        $this->assertSame(['50% off'], $this->words($this->table()->whereContains('word', '0% o')));
        $this->assertSame(['apple', 'pineapple'], $this->words($this->table()->whereContains('word', 'pple')->orderBy('id')));
    }

    #[DataProvider('connections')]
    public function test_suggest(string $connection): void
    {
        $this->useConnection($connection);

        $this->assertSame(['apple', 'apply', 'Application'], $this->words($this->table()->suggest('word', 'ap')));
        $this->assertSame(['apple', 'apply', 'Application'], array_slice($this->words($this->table()->suggest('word', 'app')), 0, 3));
        $this->assertContains('pineapple', $this->words($this->table()->suggest('word', 'app')));
        $this->assertSame([], $this->words($this->table()->suggest('word', ' ')));
    }

    #[DataProvider('connections')]
    public function test_similar(string $connection): void
    {
        $this->useConnection($connection);
        Log::spy();

        $words = $this->words($this->table()->whereSimilar('word', 'aple')->orderBySimilarity('word', 'aple'));

        if ($connection === 'crdb') {
            // Typo tolerant.
            $this->assertSame('apple', $words[0]);
        } else {
            // whereContains() instead.
            $this->assertSame([], $words);
            Log::shouldHaveReceived('warning')->atLeast()->once();
        }

        $this->assertSame('apple', $this->words($this->table()->whereSimilar('word', 'appl')->orderBySimilarity('word', 'appl'))[0]);
    }

    public function test_unaccent_on_cockroachdb(): void
    {
        $this->useConnection('crdb');
        Schema::connection('crdb')->table('portable_words', fn (Blueprint $table) => $table->trigramIndex('word', unaccent: true));
        $this->table()->insert([['word' => 'Xin chào'], ['word' => 'cháo lòng']]);

        $this->assertSame(['cháo lòng', 'Xin chào'], $this->words($this->table()->suggest('word', 'CHAO', unaccent: true)));

        $sql = str_replace('from "portable_words"', 'from "portable_words"@{FORCE_INDEX=portable_words_word_index_trigram_unaccent}', $this->table()->whereSimilar('word', 'chao', unaccent: true)->toRawSql());
        $plan = collect(DB::connection('crdb')->select('explain '.$sql))->pluck('info')->implode("\n");
        $this->assertStringContainsString('portable_words_word_index_trigram_unaccent', $plan);
    }

    #[DataProvider('connections')]
    public function test_full_text_relevance(string $connection): void
    {
        if ($connection === 'sqlite') {
            $this->markTestSkipped('SQLite has no whereFullText().');
        }

        $this->useConnection($connection);
        // MatrixOne allows one FULLTEXT index per column (trigramIndex() made one on "word").
        Schema::connection($connection)->table('portable_words', fn (Blueprint $table) => $table->fullText('phrase'));
        $this->table()->insert([['word' => 'a', 'phrase' => 'apple pie with apple'], ['word' => 'b', 'phrase' => 'green apple']]);

        $rows = $this->table()->select('phrase')->selectFullTextRelevance('phrase', 'apple')->searchFullText('phrase', 'apple')->get();

        $this->assertSame('apple pie with apple', $rows[0]->phrase);
        $this->assertGreaterThanOrEqual((float) $rows[1]->relevance, (float) $rows[0]->relevance);
    }

    #[DataProvider('connections')]
    public function test_similar_throws_in_strict_mode_without_trigrams(string $connection): void
    {
        $this->useConnection($connection);
        config(['db-portable.strict' => true]);

        if ($connection !== 'crdb') {
            $this->expectException(\RuntimeException::class);
        }

        $this->assertNotEmpty($this->words($this->table()->whereSimilar('word', 'apple')));
    }
}
