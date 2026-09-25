<?php

namespace DbPortable\Console;

use DbPortable\Copy\Copier;
use Illuminate\Console\Command;

class CopyCommand extends Command
{
    /** @var string */
    protected $signature = 'db-portable:copy
        {--from= : Source connection}
        {--to= : Target connection (migrated, ideally empty)}
        {--table=* : Only these tables}
        {--except=* : Skip these tables (default: migrations)}
        {--chunk=500 : Rows per batch}
        {--sample= : Copy only the N most recent rows of each table}
        {--resume : Continue after the highest primary key already in the target}
        {--dry-run : Count the rows without copying}
        {--force : Do not ask for confirmation}';

    /** @var string */
    protected $description = 'Copy rows between two database connections (e.g. CockroachDB to MatrixOne)';

    public function handle(): int
    {
        $from = $this->option('from');
        $to = $this->option('to');

        if (! is_string($from) || ! is_string($to) || $from === '' || $to === '' || $from === $to) {
            $this->components->error('--from and --to must name two different connections.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->option('force') && ! $this->confirm("Copy rows from [{$from}] into [{$to}]?")) {
            return self::FAILURE;
        }

        $except = array_values(array_filter((array) $this->option('except'), 'is_string')) ?: ['migrations'];
        $sample = $this->option('sample');
        $chunk = $this->option('chunk');

        $report = (new Copier($from, $to, is_numeric($chunk) ? max(1, (int) $chunk) : 500))->copy(
            tables: array_values(array_filter((array) $this->option('table'), 'is_string')),
            except: $except,
            sample: is_numeric($sample) ? (int) $sample : null,
            resume: (bool) $this->option('resume'),
            dryRun: $dryRun,
            progress: fn (string $table, int $rows, string $status) => $this->components->twoColumnDetail(
                $table,
                str_starts_with($status, 'failed') ? "<fg=red>{$status}</>" : "{$rows} <fg=gray>{$status}</>"
            ),
        );

        $failed = array_filter($report, fn ($entry) => str_starts_with($entry['status'], 'failed'));

        $this->newLine();
        $this->components->info(sprintf(
            '%d table(s), %d row(s) %s%s.',
            count($report),
            array_sum(array_column($report, 'rows')),
            $dryRun ? 'to copy' : 'copied',
            $failed ? ', '.count($failed).' failed' : ''
        ));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
