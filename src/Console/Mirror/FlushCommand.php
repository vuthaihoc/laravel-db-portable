<?php

namespace DbPortable\Console\Mirror;

use DbPortable\Mirror\MirrorRegistry;
use DbPortable\Mirror\MirrorSync;
use Illuminate\Console\ConfirmableTrait;
use InvalidArgumentException;
use Throwable;

class FlushCommand extends MirrorCommand
{
    use ConfirmableTrait;

    /** @var string */
    protected $signature = 'db-portable:mirror:flush
        {mirror : The mirror}
        {--model=* : Owner models, by class or class basename (default: every owner model)}
        {--force : Empty the tables in production without asking}';

    /** @var string */
    protected $description = 'Empty the tables of a mirror (mirror:data fills them again)';

    public function handle(MirrorRegistry $registry, MirrorSync $sync): int
    {
        try {
            $pairs = $this->pairs($registry);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $failed = false;

        foreach ($pairs as $pair) {
            if (! $this->runs($registry, $pair, true)) {
                continue;
            }

            try {
                $sync->flush($pair['mirror'], $pair['owner']);
                $this->components->twoColumnDetail($this->label($pair), '<fg=yellow>emptied</>');
            } catch (Throwable $e) {
                $this->components->error($this->label($pair).": {$e->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
