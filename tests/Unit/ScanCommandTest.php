<?php

namespace DbPortable\Tests\Unit;

use DbPortable\Tests\TestCase;
use Illuminate\Support\Facades\File;

class ScanCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/db-portable-scan-'.getmypid();
        File::ensureDirectoryExists($this->directory);
        File::put($this->directory.'/Widget.php', <<<'PHP'
<?php
return fn ($query) => $query->sum(DB::raw("(plan_data->>'amount')::bigint"));
PHP);
        File::put($this->directory.'/Clean.php', <<<'PHP'
<?php
return fn ($query) => $query->sumJson('plan_data->amount');
PHP);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_reports_findings(): void
    {
        $this->artisan('db-portable:scan', ['paths' => [$this->directory], '--target' => ['matrixone']])
            ->expectsOutputToContain('pg-json-operator')
            ->expectsOutputToContain('Widget.php:2')
            ->assertSuccessful();

        $this->artisan('db-portable:scan', ['paths' => [$this->directory], '--fail' => true])->assertFailed();
    }

    public function test_json_output_and_clean_code(): void
    {
        $this->artisan('db-portable:scan', ['paths' => [$this->directory.'/Clean.php']])
            ->expectsOutputToContain('No database-specific SQL found.')
            ->assertSuccessful();

        $this->artisan('db-portable:scan', ['paths' => [$this->directory], '--json' => true])
            ->expectsOutputToContain('"rule": "pg-cast"')
            ->assertSuccessful();
    }

    public function test_unknown_targets_are_rejected(): void
    {
        $this->artisan('db-portable:scan', ['--target' => ['oracle']])->assertFailed();
    }
}
