<?php

namespace DbPortable\Mirror\Schema;

use DbPortable\Mirror\MirrorModel;
use DbPortable\Mirror\MirrorTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;

/**
 * What mirror:schema does to one mirror table: create it, or add the columns
 * and the mirrorSchema() parts it lacks.
 */
final class SchemaPlan
{
    /**
     * @param  class-string<Model>  $owner
     * @param  class-string<MirrorModel>  $mirrorModel
     * @param  list<ColumnSpec>  $columns  the columns to create, or to add to the existing table
     * @param  list<string>  $notes  guessed types, columns without equivalent, columns only on the mirror
     * @param  list<string>  $existingColumns  the existing table's columns
     * @param  list<string>  $existingIndexes  the existing table's indexes
     */
    public function __construct(
        public readonly string $mirror,
        public readonly string $owner,
        public readonly string $mirrorModel,
        public readonly MirrorTable $table,
        public readonly bool $create,
        public readonly array $columns,
        public readonly array $notes = [],
        public readonly array $existingColumns = [],
        public readonly array $existingIndexes = [],
    ) {}

    public function changes(): bool
    {
        return $this->create || $this->columns !== [] || $this->mirrorSchemaSql() !== [];
    }

    /**
     * The blueprint of the changes.
     */
    public function blueprint(): Blueprint
    {
        $blueprint = new Blueprint($this->table->connection, $this->table->table);

        if ($this->create) {
            $blueprint->create();
        }

        foreach ($this->columns as $column) {
            $column->apply($blueprint);
        }

        $this->addMirrorSchema($blueprint);

        return $blueprint;
    }

    /**
     * @return list<string>
     */
    public function sql(): array
    {
        return array_values(array_map('strval', $this->blueprint()->toSql()));
    }

    public function apply(): void
    {
        $this->blueprint()->build();
    }

    /**
     * The SQL of the mirrorSchema() parts an existing table lacks (on creation, they are part of it).
     *
     * @return list<string>
     */
    public function mirrorSchemaSql(): array
    {
        if ($this->create) {
            return [];
        }

        $blueprint = new Blueprint($this->table->connection, $this->table->table);
        $this->addMirrorSchema($blueprint);

        return array_values(array_map('strval', $blueprint->toSql()));
    }

    /**
     * The migration code of the changes.
     *
     * @return array{up: string, down: string}
     */
    public function migration(): array
    {
        $connection = var_export($this->table->connection->getName(), true);
        $table = var_export($this->table->table, true);
        $schema = "Schema::connection({$connection})";
        $lines = array_map(fn (ColumnSpec $column) => $column->php(), $this->columns);

        if ($this->create) {
            $lines[] = '\\'.$this->mirrorModel.'::mirrorSchema($table);';

            return [
                'up' => "{$schema}->create({$table}, function (Blueprint \$table) {\n".$this->indent($lines)."\n});",
                'down' => "{$schema}->dropIfExists({$table});",
            ];
        }

        $up = $down = [];

        if ($lines !== []) {
            $names = implode(', ', array_map(fn (ColumnSpec $column) => var_export($column->name, true), $this->columns));
            $up[] = "{$schema}->table({$table}, function (Blueprint \$table) {\n".$this->indent($lines)."\n});";
            $down[] = "{$schema}->table({$table}, function (Blueprint \$table) {\n    \$table->dropColumn([{$names}]);\n});";
        }

        foreach ($this->mirrorSchemaSql() as $statement) {
            $up[] = "DB::connection({$connection})->statement(".var_export($statement, true).');';
        }

        if ($this->mirrorSchemaSql() !== []) {
            $down[] = '// The mirrorSchema() changes of '.$this->table->table.' are not reverted.';
        }

        return ['up' => implode("\n\n", $up), 'down' => implode("\n\n", $down)];
    }

    /**
     * The mirror model's mirrorSchema(), without what an existing table has.
     */
    private function addMirrorSchema(Blueprint $blueprint): void
    {
        $this->mirrorModel::mirrorSchema($blueprint);

        foreach ($blueprint->getAddedColumns() as $column) {
            if (in_array($column->get('name'), $this->existingColumns, true)) {
                $blueprint->removeColumn($column->get('name'));
            }
        }

        $existing = $this->existingIndexes;

        (function () use ($existing) {
            /** @var Blueprint $this */
            $this->commands = array_values(array_filter(
                $this->commands,
                fn ($command) => ! in_array($command->get('index'), $existing, true),
            ));
        })->call($blueprint);
    }

    /**
     * @param  list<string>  $lines
     */
    private function indent(array $lines): string
    {
        return implode("\n", array_map(fn (string $line) => '    '.$line, $lines));
    }
}
