<?php

namespace DbPortable\Mirror;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Where a mirror model's rows are written: the mirror connection and table,
 * the key column, and the version column that guards the writes.
 */
final class MirrorTable
{
    /**
     * @param  string  $key  the mirror table's key column
     * @param  string  $ownerKey  the owner's key column, renamed to $key in the written rows
     * @param  string|null  $version  the owner's updated_at column: a write never replaces a newer version
     */
    public function __construct(
        public readonly Connection $connection,
        public readonly string $table,
        public readonly string $key,
        public readonly string $ownerKey,
        public readonly ?string $version,
    ) {}

    /**
     * @param  class-string<MirrorModel>  $mirrorModel
     */
    public static function of(string $mirrorModel): self
    {
        $model = new $mirrorModel;
        $ownerClass = $mirrorModel::ownerClass();
        /** @var Model $owner */
        $owner = new $ownerClass;
        /** @var Connection $connection */
        $connection = $model->getConnection();

        if ($connection->getName() === $owner->getConnection()->getName() && $model->getTable() === $owner->getTable()) {
            throw new LogicException("[{$mirrorModel}] would write the table of its owner {$ownerClass} ({$connection->getName()}.{$owner->getTable()}): give the mirror its own connection or table.");
        }

        return new self(
            $connection,
            $model->getTable(),
            $model->getKeyName(),
            $owner->getKeyName(),
            $owner->usesTimestamps() && $owner->getUpdatedAtColumn() !== null ? $owner->getUpdatedAtColumn() : null,
        );
    }
}
