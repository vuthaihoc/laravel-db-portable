<?php

namespace DbPortable\Console;

use DbPortable\Scan\Finding;
use DbPortable\Scan\Scanner;
use Illuminate\Console\Command;

class ScanCommand extends Command
{
    /** @var string */
    protected $signature = 'db-portable:scan
        {paths?* : Files or directories (default: app, database, routes)}
        {--target=* : Only report SQL breaking on these families: mysql, matrixone, pgsql, sqlite}
        {--json : Print the findings as JSON}
        {--fail : Exit with an error code when something is found}';

    /** @var string */
    protected $description = 'Find database-specific SQL (PostgreSQL/CockroachDB or MySQL/MatrixOne syntax) in the application code';

    public function handle(): int
    {
        $paths = (array) $this->argument('paths') ?: array_filter([app_path(), database_path(), base_path('routes')], 'is_dir');
        $targets = array_values(array_filter((array) $this->option('target'), 'is_string'));

        foreach ($targets as $target) {
            if (! in_array($target, ['mysql', 'matrixone', 'pgsql', 'sqlite'], true)) {
                $this->components->error("Unknown target [{$target}]: use mysql, matrixone, pgsql or sqlite.");

                return self::FAILURE;
            }
        }

        $findings = (new Scanner)->scan(array_values($paths), $targets, base_path());

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(fn (Finding $finding) => $finding->toArray(), $findings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $findings !== [] && $this->option('fail') ? self::FAILURE : self::SUCCESS;
        }

        if ($findings === []) {
            $this->components->info('No database-specific SQL found.');

            return self::SUCCESS;
        }

        foreach (collect($findings)->groupBy(fn (Finding $finding) => $finding->rule->id) as $rule => $group) {
            /** @var Finding $first */
            $first = $group->first();
            $this->newLine();
            $this->components->twoColumnDetail(
                "<fg=yellow>{$rule}</> breaks on ".implode(', ', $first->rule->breaksOn),
                $group->count().' place(s)'
            );
            $this->line('  <fg=gray>Use: '.$first->rule->suggestion.'</>');

            foreach ($group as $finding) {
                $this->line("  {$finding->file}:{$finding->line}  <fg=gray>{$finding->snippet}</>");
            }
        }

        $this->newLine();
        $this->components->warn(count($findings).' database-specific construct(s) found.');

        return $this->option('fail') ? self::FAILURE : self::SUCCESS;
    }
}
