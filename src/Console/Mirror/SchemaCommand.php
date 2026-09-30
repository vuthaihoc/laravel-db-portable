<?php

namespace DbPortable\Console\Mirror;

use DbPortable\Mirror\MirrorRegistry;
use DbPortable\Mirror\Schema\ColumnSpec;
use DbPortable\Mirror\Schema\MirrorSchema;
use DbPortable\Mirror\Schema\SchemaPlan;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class SchemaCommand extends MirrorCommand
{
    /** @var string */
    protected $signature = 'db-portable:mirror:schema
        {mirror? : The mirror (default: every mirror)}
        {--model=* : Owner models, by class or class basename (default: every owner model)}
        {--dry-run : Print the SQL instead of running it}
        {--migration : Write a migration instead of running the SQL}
        {--path= : Directory of the migration (default: database/migrations)}
        {--force : Include the mirrors turned off in this environment}';

    /** @var string */
    protected $description = "Create or complete the mirror tables: the mirrored columns mapped to the mirror's database, plus the mirror models' mirrorSchema()";

    public function handle(MirrorRegistry $registry, MirrorSchema $schema, Filesystem $files): int
    {
        try {
            $pairs = $this->pairs($registry);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $failed = false;
        $migrations = [];

        foreach ($pairs as $pair) {
            if (! $this->runs($registry, $pair, (bool) $this->option('force'))) {
                continue;
            }

            $label = $this->label($pair);

            try {
                $plan = $schema->plan($pair['mirror'], $pair['owner']);
            } catch (Throwable $e) {
                $this->components->error("{$label}: {$e->getMessage()}");
                $failed = true;

                continue;
            }

            $this->components->twoColumnDetail($label, $this->summary($plan));

            foreach ($plan->notes as $note) {
                $this->components->warn("{$label}: {$note}");
            }

            if (! $plan->changes()) {
                continue;
            }

            if ($this->option('dry-run')) {
                foreach ($plan->sql() as $statement) {
                    $this->line("  {$statement};");
                }

                continue;
            }

            if ($this->option('migration')) {
                $migrations[] = $plan;

                continue;
            }

            try {
                $plan->apply();
            } catch (Throwable $e) {
                $this->components->error("{$label}: {$e->getMessage()}");
                $failed = true;

                continue;
            }

            foreach ($schema->audit($plan) as $problem) {
                $this->components->warn("{$label}: {$problem}");
            }
        }

        if ($migrations !== []) {
            $this->components->info('Migration written: '.$this->writeMigration($files, $migrations));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function summary(SchemaPlan $plan): string
    {
        $table = $plan->table->connection->getName().'.'.$plan->table->table;

        if ($plan->create) {
            return "<fg=yellow>create {$table}</> (".count($plan->columns).' columns)';
        }

        $changes = array_map(fn (ColumnSpec $column) => $column->name, $plan->columns);

        if ($plan->mirrorSchemaSql() !== []) {
            $changes[] = 'mirrorSchema()';
        }

        return $changes === [] ? "<fg=green>{$table} up to date</>" : "<fg=yellow>add to {$table}</>: ".implode(', ', $changes);
    }

    /**
     * @param  list<SchemaPlan>  $plans
     */
    private function writeMigration(Filesystem $files, array $plans): string
    {
        $path = $this->option('path');
        $mirror = $this->argument('mirror');
        $directory = is_string($path) && $path !== '' ? $path : database_path('migrations');
        $file = $directory.'/'.date('Y_m_d_His').'_mirror_schema'.(is_string($mirror) && $mirror !== '' ? '_'.Str::snake($mirror) : '').'.php';
        $up = array_map(fn (SchemaPlan $plan) => $plan->migration()['up'], $plans);
        $down = array_reverse(array_map(fn (SchemaPlan $plan) => $plan->migration()['down'], $plans));

        $files->ensureDirectoryExists($directory);
        $files->put($file, <<<PHP
        <?php

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\DB;
        use Illuminate\\Support\\Facades\\Schema;

        // Mirror tables (php artisan db-portable:mirror:schema --migration).
        return new class extends Migration
        {
            public function up(): void
            {
        {$this->indent(implode("\n\n", $up), 8)}
            }

            public function down(): void
            {
        {$this->indent(implode("\n\n", $down), 8)}
            }
        };

        PHP);

        return $file;
    }

    private function indent(string $code, int $spaces): string
    {
        return implode("\n", array_map(fn (string $line) => $line === '' ? '' : str_repeat(' ', $spaces).$line, explode("\n", $code)));
    }
}
