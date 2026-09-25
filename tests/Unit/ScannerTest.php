<?php

namespace DbPortable\Tests\Unit;

use DbPortable\Scan\Scanner;
use PHPUnit\Framework\TestCase;

class ScannerTest extends TestCase
{
    /**
     * @param  list<string>  $targets
     * @return list<string> "line:rule"
     */
    private function scan(string $source, array $targets = []): array
    {
        return array_map(
            fn (array $finding) => $finding[0].':'.$finding[1]->id,
            (new Scanner)->scanSource($source, $targets),
        );
    }

    public function test_postgres_constructs_found_in_songlingo(): void
    {
        $source = <<<'PHP'
<?php
$query->whereRaw("flags->>'device' = ?", [$device]);
$query->sum(DB::raw("(plan_data->>'amount')::bigint"));
$query->orderByRaw("(flags->>'word_sync_ratio')::float desc nulls last");
DB::raw("jsonb_set(video_reactions, '{like}', '1'::jsonb)");
$q->whereRaw("jsonb_array_length(coalesce(d.youtube->'available_auto_captions','[]'::jsonb)) > 0");
$q->orWhereRaw('tags::text not like ?', ['%x%']);
$q->where('created_at', '>=', DB::raw("timestamptz '2026-01-01 00:00:00+07'"));
PHP;

        $this->assertSame([
            '2:pg-json-operator',
            '3:pg-json-operator', '3:pg-cast',
            '4:pg-json-operator', '4:pg-cast', '4:nulls-first-last',
            '5:pg-cast', '5:pg-jsonb-function',
            '6:pg-json-operator', '6:pg-cast', '6:pg-jsonb-function',
            '7:pg-cast',
            '8:pg-typed-literal',
        ], $this->scan($source));
    }

    public function test_mysql_constructs(): void
    {
        $source = <<<'PHP'
<?php
DB::select('select `id`, ifnull(`name`, "") from `users` where if(active, 1, 0) = 1 limit 10, 20');
DB::statement("insert into t (a) values (1) on duplicate key update a = 2");
$q->orderByRaw("cast(json_unquote(json_extract(flags, '$.ratio')) as double) desc");
$q->selectRaw("group_concat(name) as names, date_format(created_at, '%Y') as y");
PHP;

        $this->assertEqualsCanonicalizing([
            '2:mysql-backtick', '2:mysql-backtick', '2:mysql-backtick',
            '2:ifnull', '2:mysql-if', '2:mysql-limit-offset',
            '3:on-duplicate-key',
            '4:mysql-json-function', '4:json-extract-set',
            '5:group-concat', '5:mysql-date-function',
        ], $this->scan($source));
    }

    public function test_portable_code_and_plain_text_are_not_reported(): void
    {
        $source = <<<'PHP'
<?php
$q->where('meta->amount', '>', 10)->orderBy('flags->ratio');
$q->whereRaw("p.settings->>'$.level' = ?", [$level]);
$message = 'Please try again (if possible) later, see `README`.';
$class = User::class;
$time = now()->format('H:i:s');
PHP;

        $this->assertSame([], $this->scan($source));
    }

    public function test_targets_filter_the_rules(): void
    {
        $source = <<<'PHP'
<?php
$q->whereRaw("flags->>'device' = ?")->orderByRaw('x desc nulls last')->whereRaw('ifnull(a, 0) = 1 and name ilike ?');
PHP;

        $this->assertSame(['2:pg-json-operator', '2:nulls-first-last', '2:ilike'], $this->scan($source, ['mysql']));
        $this->assertSame(['2:ifnull'], $this->scan($source, ['pgsql']));
    }

    public function test_heredoc_lines(): void
    {
        $source = <<<'PHP'
<?php
DB::select(<<<SQL
    select *
    from videos
    order by view_count desc nulls last
SQL);
PHP;

        $this->assertSame(['5:nulls-first-last'], $this->scan($source));
    }

    public function test_matrixone_accepts_ilike(): void
    {
        $source = <<<'PHP'
<?php
$q->where('title', 'ilike', $term)->orderByRaw('score desc nulls last');
PHP;

        $this->assertSame(['2:ilike', '2:nulls-first-last'], $this->scan($source, ['mysql']));
        $this->assertSame(['2:nulls-first-last'], $this->scan($source, ['matrixone']));
    }

    public function test_returning_only_matches_sql(): void
    {
        $source = <<<'PHP'
<?php
$prompt = 'Check every field and select the best one before returning JSON. Fix it first.';
DB::select('insert into users (name) values (?) returning id', ['a']);
PHP;

        $this->assertSame(['3:returning'], $this->scan($source, ['mysql']));
    }
}
