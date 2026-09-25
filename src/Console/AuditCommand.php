<?php

namespace DbPortable\Console;

use DbPortable\Audit\Auditor;
use Illuminate\Console\Command;

class AuditCommand extends Command
{
    /** @var string */
    protected $signature = 'db-portable:audit
        {--from= : Source connection (the data)}
        {--to= : Target connection (the schema, already migrated)}
        {--table=* : Only these tables}
        {--json : Print the problems as JSON}';

    /** @var string */
    protected $description = 'Check that the data of one connection fits the schema of another (missing tables/columns, integer ranges, string lengths)';

    public function handle(): int
    {
        $from = $this->option('from');
        $to = $this->option('to');

        if (! is_string($from) || ! is_string($to) || $from === '' || $to === '') {
            $this->components->error('Both --from and --to connections are required.');

            return self::FAILURE;
        }

        $tables = array_values(array_filter((array) $this->option('table'), 'is_string'));
        $json = (bool) $this->option('json');

        $problems = (new Auditor($from, $to))->audit($tables, $json ? null : fn (string $table) => $this->line("  <fg=gray>{$table}</>"));

        if ($json) {
            $this->line((string) json_encode($problems, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $problems === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($problems === []) {
            $this->components->info("The data of [{$from}] fits the schema of [{$to}].");

            return self::SUCCESS;
        }

        $this->table(['Table', 'Column', 'Problem', 'Detail'], array_map(fn ($problem) => array_values($problem), $problems));
        $this->components->warn(count($problems).' problem(s): fix the target schema (or the data) before copying.');

        return self::FAILURE;
    }
}
