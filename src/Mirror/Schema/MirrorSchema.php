<?php

namespace DbPortable\Mirror\Schema;

use DbPortable\Audit\Auditor;
use DbPortable\Mirror\MirrorModel;
use DbPortable\Mirror\MirrorRegistry;
use DbPortable\Mirror\MirrorSync;
use DbPortable\Mirror\MirrorTable;
use DbPortable\Schema\Family;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use LogicException;
use Throwable;

/**
 * Plans the mirror tables of mirror:schema: the columns of the rows written to
 * the mirror, mapped from the owner columns to the mirror's database (or, for
 * columns fromOwner() computes, from a sample value), plus the mirror model's
 * mirrorSchema(). No foreign keys, no owner indexes.
 */
class MirrorSchema
{
    public function __construct(
        protected MirrorRegistry $registry,
        protected MirrorSync $sync,
    ) {}

    /**
     * @param  class-string<Model>  $owner
     */
    public function plan(string $mirror, string $owner): SchemaPlan
    {
        $declaration = $this->registry->mirrorsOf($owner)[$mirror]
            ?? throw new LogicException("{$owner} is not mirrored in [{$mirror}].");
        $this->registry->config($mirror);
        $mirrorModel = $declaration->model;
        $table = MirrorTable::of($mirrorModel);

        /** @var Model $ownerModel */
        $ownerModel = new $owner;
        /** @var Connection $ownerConnection */
        $ownerConnection = $ownerModel->getConnection();
        $ownerColumns = [];

        foreach ($ownerConnection->getSchemaBuilder()->getColumns($ownerModel->getTable()) as $column) {
            $ownerColumns[(string) $column['name']] = $column;
        }

        if ($ownerColumns === []) {
            throw new LogicException("The owner table {$ownerModel->getTable()} does not exist on [{$ownerConnection->getName()}].");
        }

        $mapper = new ColumnMapper($table->connection, Family::of($ownerConnection) ?? Family::POSTGRES);
        $casts = $ownerModel->getCasts();

        // The columns mirrorSchema() declares itself.
        $declared = new Blueprint($table->connection, $table->table);
        $mirrorModel::mirrorSchema($declared);
        $declaredColumns = array_map(fn ($column) => (string) $column->get('name'), $declared->getAddedColumns());

        [$names, $sample] = $this->mirroredColumns($mirror, $mirrorModel, $ownerModel, $table, array_keys($ownerColumns));
        $columns = [];
        $notes = [];

        foreach ($names as $name) {
            if (in_array($name, $declaredColumns, true)) {
                continue;
            }

            $ownerName = $name === $table->key ? $table->ownerKey : $name;
            $cast = $casts[$ownerName] ?? null;
            $column = isset($ownerColumns[$ownerName])
                ? $mapper->map($ownerColumns[$ownerName], is_string($cast) ? $cast : null, $name, $name === $table->key)
                : $mapper->infer($name, $sample[$name] ?? null, $name === $table->key);

            if ($column === null) {
                $notes[] = "{$name}: the owner type {$ownerColumns[$ownerName]['type']} has no equivalent, declare the column in mirrorSchema()";

                continue;
            }

            if ($column->note !== null) {
                $notes[] = "{$name}: {$column->note}";
            }

            $columns[] = $column;
        }

        if (! in_array($table->key, $names, true) && ! in_array($table->key, $declaredColumns, true)) {
            $notes[] = "the mirrored rows have no key column {$table->key}";
        }

        $schema = $table->connection->getSchemaBuilder();

        if (! $schema->hasTable($table->table)) {
            return new SchemaPlan($mirror, $owner, $mirrorModel, $table, true, $columns, $notes);
        }

        $existing = array_map(fn (array $column) => (string) $column['name'], $schema->getColumns($table->table));
        $indexes = array_map(fn (array $index) => (string) $index['name'], $schema->getIndexes($table->table));
        $only = array_values(array_diff($existing, $names, $declaredColumns));

        if ($only !== []) {
            $notes[] = 'only on the mirror (dropped on the owner, or no longer mirrored): '.implode(', ', $only);
        }

        return new SchemaPlan(
            $mirror,
            $owner,
            $mirrorModel,
            $table,
            false,
            array_values(array_filter($columns, fn (ColumnSpec $column) => ! in_array($column->name, $existing, true))),
            $notes,
            $existing,
            $indexes,
        );
    }

    /**
     * The owner values the mirror table would reject or cut (db-portable:audit's checks).
     *
     * @return list<string>
     */
    public function audit(SchemaPlan $plan): array
    {
        // XTDB columns take any value.
        if (Family::isXtdb($plan->table->connection)) {
            return [];
        }

        /** @var Model $owner */
        $owner = new ($plan->owner);
        $auditor = new Auditor((string) $owner->getConnection()->getName(), (string) $plan->table->connection->getName());

        return array_map(
            fn (array $problem) => "{$problem['column']}: {$problem['problem']} ({$problem['detail']})",
            $auditor->auditValues($owner->getTable(), $plan->table->table),
        );
    }

    /**
     * The columns of the rows written to the mirror, from the first owner row (the rows
     * fromOwner() or toMirrorArray() make), else from the owner table; and that sample row.
     *
     * @param  class-string<MirrorModel>  $mirrorModel
     * @param  list<string>  $ownerColumns
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    protected function mirroredColumns(string $mirror, string $mirrorModel, Model $owner, MirrorTable $table, array $ownerColumns): array
    {
        $sample = $mirrorModel::ownerQuery($owner->newQueryWithoutScopes())->orderBy($owner->getQualifiedKeyName())->first();

        try {
            $row = $this->sync->row($mirror, $mirrorModel, $sample ?? $owner->newInstance(), $table);
        } catch (Throwable) {
            $row = [];   // fromOwner() needs a real row
        }

        if ($row !== []) {
            return [array_map('strval', array_keys($row)), $row];
        }

        return [array_map(fn (string $column) => $column === $table->ownerKey ? $table->key : $column, $ownerColumns), []];
    }
}
