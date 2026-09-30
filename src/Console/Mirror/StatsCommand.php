<?php

namespace DbPortable\Console\Mirror;

use DbPortable\Mirror\MirrorRegistry;
use DbPortable\Mirror\MirrorStats;
use InvalidArgumentException;
use Throwable;

class StatsCommand extends MirrorCommand
{
    /** @var string */
    protected $signature = 'db-portable:mirror:stats
        {mirror? : The mirror (default: every mirror)}
        {--model=* : Owner models, by class or class basename (default: every owner model)}
        {--compare : Also compare exact aggregates of the columns both tables have (scans both)}
        {--keys : Also compare the keys, by key range}
        {--json : Print the report as JSON}';

    /** @var string */
    protected $description = 'Show how owner tables and their mirror tables compare: server statistics of both sides, and on request exact aggregates and keys';

    public function handle(MirrorRegistry $registry, MirrorStats $stats): int
    {
        try {
            $pairs = $this->pairs($registry);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $reports = [];
        $failed = false;

        foreach ($pairs as $pair) {
            if ($registry->state($pair['mirror'], $pair['owner']) === 'unconfigured') {
                $this->runs($registry, $pair);

                continue;
            }

            try {
                $report = $stats->report($pair['mirror'], $pair['owner'], (bool) $this->option('compare'), (bool) $this->option('keys'));
            } catch (Throwable $e) {
                $this->components->error($this->label($pair).": {$e->getMessage()}");
                $failed = true;

                continue;
            }

            $reports[] = $report;
            $failed = $failed || $report['differs'];

            if (! $this->option('json')) {
                $this->newLine();
                $this->line('<options=bold>'.$this->label($pair).'</>'.($report['state'] === 'off' ? ' <fg=yellow>(turned off)</>' : ''));
                $this->table(['', "owner ({$report['owner_connection']})", "mirror ({$report['mirror_connection']})", ''], $report['rows']);

                foreach ($report['notes'] as $note) {
                    $this->components->warn($note);
                }
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
