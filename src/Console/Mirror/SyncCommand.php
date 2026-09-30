<?php

namespace DbPortable\Console\Mirror;

class SyncCommand extends MirrorCommand
{
    /** @var string */
    protected $signature = 'db-portable:mirror:sync
        {mirror? : The mirror (default: every mirror)}
        {--model=* : Owner models, by class or class basename (default: every owner model)}
        {--force : Include the mirrors turned off in this environment}';

    /** @var string */
    protected $description = 'Bring mirrors up to date: db-portable:mirror:schema, then mirror:data, then mirror:stats';

    public function handle(): int
    {
        $arguments = array_filter([
            'mirror' => $this->argument('mirror'),
            '--model' => $this->option('model'),
            '--force' => $this->option('force'),
        ]);

        foreach (['schema', 'data'] as $step) {
            $this->components->info("mirror:{$step}");

            if ($this->call("db-portable:mirror:{$step}", $arguments) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        $this->components->info('mirror:stats');

        return $this->call('db-portable:mirror:stats', array_diff_key($arguments, ['--force' => true]));
    }
}
