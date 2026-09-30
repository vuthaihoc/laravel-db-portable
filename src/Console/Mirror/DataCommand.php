<?php

namespace DbPortable\Console\Mirror;

use DbPortable\Mirror\MirrorRegistry;
use DbPortable\Mirror\MirrorSync;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

class DataCommand extends MirrorCommand
{
    /** @var string */
    protected $signature = 'db-portable:mirror:data
        {mirror? : The mirror (default: every mirror)}
        {--model=* : Owner models, by class or class basename (default: every owner model)}
        {--since= : Only the rows changed since this time ("-1 hour", "2026-09-01")}
        {--chunk=500 : Rows per batch}
        {--queue : Queue the batches for the queue workers instead of writing them}
        {--prune : Also delete the mirror rows whose owner row is gone}
        {--dry-run : Count the rows instead of writing them}
        {--force : Include the mirrors turned off in this environment}';

    /** @var string */
    protected $description = 'Write the owner rows to their mirrors (backfill or catch up), with the value conversions and version guard of the queue';

    public function handle(MirrorRegistry $registry, MirrorSync $sync): int
    {
        try {
            $pairs = $this->pairs($registry);
            $since = $this->since();
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $failed = false;

        foreach ($pairs as $pair) {
            if (! $this->runs($registry, $pair, (bool) $this->option('force'))) {
                continue;
            }

            [$mirror, $owner] = [$pair['mirror'], $pair['owner']];
            $label = $this->label($pair);

            try {
                $query = $sync->ownerQuery($registry->mirrorsOf($owner)[$mirror]->model, $owner, $since);

                if ($this->option('dry-run')) {
                    $this->components->twoColumnDetail($label, number_format($query->count()).' rows to write');

                    continue;
                }

                if ($this->option('queue')) {
                    if ($registry->state($mirror, $owner) !== 'on') {
                        $this->components->warn("{$label}: the jobs of a mirror turned off do nothing: run without --queue.");

                        continue;
                    }

                    $rows = $query->count();
                    $sync->queue($query, false, $chunk, $mirror);
                    $result = number_format($rows).' rows queued in jobs of '.number_format($chunk);
                } else {
                    $written = $sync->backfill($mirror, $owner, $since, $chunk);
                    $result = number_format($written['written']).' written, '.number_format($written['deleted']).' deleted';
                }

                if ($this->option('prune')) {
                    $result .= ', '.number_format($sync->prune($mirror, $owner)).' pruned';
                }

                $this->components->twoColumnDetail($label, $result);
            } catch (Throwable $e) {
                $this->components->error("{$label}: {$e->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function since(): ?Carbon
    {
        $since = $this->option('since');

        if (! is_string($since) || $since === '') {
            return null;
        }

        try {
            return Carbon::parse($since);
        } catch (Throwable) {
            throw new InvalidArgumentException("--since={$since} is not a time.");
        }
    }
}
