<?php

namespace DbPortable\Console;

use DbPortable\Search\SearchIndex;
use DbPortable\Search\SearchIndexPlanner;
use DbPortable\Support\ModelDiscovery;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;

class SearchIndexesCommand extends Command
{
    /** @var string */
    protected $signature = 'db-portable:search-indexes
        {models?* : Searchable model classes (default: the Searchable models of app/Models)}
        {--like : Also index the LIKE columns (trigram indexes, PostgreSQL family)}
        {--migration : Write a migration creating the missing indexes}
        {--path= : Directory of the migration (default: database/migrations)}';

    /** @var string */
    protected $description = 'Check (or create with a migration) the indexes Scout models need, from their #[SearchUsingFullText], #[SearchUsingPrefix] and #[SearchUsingFuzzy] attributes';

    public function handle(SearchIndexPlanner $planner, Filesystem $files): int
    {
        $models = array_values(array_filter((array) $this->argument('models'), 'is_string'));
        $models = $models === [] ? $this->discover() : $models;

        if ($models === []) {
            $this->components->warn('No searchable model found.');

            return self::SUCCESS;
        }

        $plans = [];

        foreach ($models as $class) {
            if (! is_subclass_of($class, Model::class)) {
                $this->components->error("[{$class}] is not an Eloquent model.");

                return self::FAILURE;
            }

            $plans = [...$plans, ...$planner->plan(new $class, (bool) $this->option('like'))];
        }

        $this->table(['Model', 'Kind', 'Columns', 'Status', 'Index / detail'], array_map(fn (SearchIndex $index) => [
            class_basename($index->model), $index->kind, implode(', ', $index->columns), $this->status($index->status), $index->detail,
        ], $plans));

        $needed = array_values(array_filter($plans, fn (SearchIndex $index) => $index->needsMigration()));

        if ($needed === []) {
            $this->components->info('Every search index exists.');

            return self::SUCCESS;
        }

        if (! $this->option('migration')) {
            $this->components->warn(count($needed).' index(es) missing or outdated: run with --migration to write a migration.');

            return self::FAILURE;
        }

        $path = $this->option('path');
        $directory = is_string($path) && $path !== '' ? $path : database_path('migrations');
        $file = $directory.'/'.date('Y_m_d_His').'_add_search_indexes.php';

        $files->ensureDirectoryExists($directory);
        $files->put($file, $this->migration($needed));
        $this->components->info("Migration written: {$file}");

        return self::SUCCESS;
    }

    /**
     * @param  list<SearchIndex>  $indexes
     */
    public function migration(array $indexes): string
    {
        $up = $down = [];

        foreach ($this->byTable($indexes) as [$connection, $table, $tableIndexes]) {
            $schema = $connection === null ? 'Schema::' : "Schema::connection('{$connection}')->";
            $upLines = $downLines = [];

            foreach ($tableIndexes as $index) {
                if ($index->replaces !== null) {
                    $upLines[] = "\$table->dropIndex('{$index->replaces}'); // outdated";
                    $downLines[] = "// {$index->replaces} is not recreated";
                }

                $upLines[] = $index->up;
                $downLines[] = $index->down;
            }

            $up[] = $this->tableBlock($schema, $table, $upLines);
            $down[] = $this->tableBlock($schema, $table, $downLines);
        }

        return <<<PHP
        <?php

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\Schema;

        // Search indexes of the Scout models (php artisan db-portable:search-indexes).
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

        PHP;
    }

    /**
     * @param  list<SearchIndex>  $indexes
     * @return list<array{0: string|null, 1: string, 2: list<SearchIndex>}>
     */
    private function byTable(array $indexes): array
    {
        $groups = [];

        foreach ($indexes as $index) {
            $key = ($index->connection ?? '').'|'.$index->table;
            $groups[$key] ??= [$index->connection, $index->table, []];
            $groups[$key][2][] = $index;
        }

        return array_values($groups);
    }

    /**
     * @param  list<string>  $lines
     */
    private function tableBlock(string $schema, string $table, array $lines): string
    {
        return "{$schema}table('{$table}', function (Blueprint \$table) {\n".$this->indent(implode("\n", $lines), 4)."\n});";
    }

    private function indent(string $code, int $spaces): string
    {
        return implode("\n", array_map(fn ($line) => $line === '' ? '' : str_repeat(' ', $spaces).$line, explode("\n", $code)));
    }

    private function status(string $status): string
    {
        return match ($status) {
            SearchIndex::OK => '<fg=green>ok</>',
            SearchIndex::MISSING => '<fg=red>missing</>',
            SearchIndex::OUTDATED => '<fg=yellow>outdated</>',
            default => '<fg=gray>skipped</>',
        };
    }

    /**
     * The models of app/Models using Scout's Searchable trait.
     *
     * @return list<class-string<Model>>
     */
    private function discover(): array
    {
        return array_values(array_filter(
            ModelDiscovery::appModels(),
            fn (string $class) => in_array('Laravel\Scout\Searchable', class_uses_recursive($class), true),
        ));
    }
}
