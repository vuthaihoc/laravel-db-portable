<?php

namespace DbPortable\Mirror;

use DateTimeInterface;
use DbPortable\Mirror\Attributes\MirroredAs;
use DbPortable\Mirror\Jobs\MirrorKeys;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Throwable;

/**
 * Queues the changes of owner models for their mirrors, and applies them.
 *
 * @phpstan-import-type MirrorConfig from MirrorRegistry
 */
class MirrorSync
{
    public function __construct(
        protected MirrorRegistry $registry,
        protected MirrorWriter $writer,
    ) {}

    /**
     * Queue a change of an owner model for each of its mirrors (MirrorObserver).
     *
     * @param  'created'|'updated'|'deleted'|'forceDeleted'  $event
     */
    public function changed(Model $model, string $event): void
    {
        if (! $this->registry->enabled($model::class)) {
            return;
        }

        foreach (array_keys($this->registry->mirrorsOf($model)) as $mirror) {
            $config = $this->registry->active($mirror, $model::class);

            // A new row that is not mirrored has nothing to remove from the mirror.
            if ($config === null || ($event === 'created' && ! $this->shouldMirror($model, $mirror))) {
                continue;
            }

            $action = $event === 'forceDeleted' && $config['erase_on_force_delete'] ? MirrorKeys::ERASE : MirrorKeys::SYNC;

            $this->dispatch(new MirrorKeys($mirror, $model::class, [$model->getKey()], $action), $mirror, $config);
        }
    }

    /**
     * Queue the rows of an owner query for their mirrors (mirrorable()), or their removal
     * from the mirrors (unmirrorable()), in jobs of $chunk keys.
     *
     * @param  Builder<Model>  $query
     * @param  string|null  $only  one of the model's mirrors (default: every mirror)
     */
    public function queue(Builder $query, bool $remove = false, ?int $chunk = null, ?string $only = null): void
    {
        $model = $query->getModel();
        $configs = [];

        foreach (array_keys($this->registry->mirrorsOf($model)) as $mirror) {
            if (($only === null || $mirror === $only) && ($config = $this->registry->active($mirror, $model::class)) !== null) {
                $configs[$mirror] = $config;
            }
        }

        if ($configs === []) {
            return;
        }

        $key = $model->getKeyName();

        $query->clone()->setEagerLoads([])->reorder()->select($model->qualifyColumn($key))->chunkById(
            $chunk ?? 500,
            function (Collection $models) use ($configs, $model, $remove) {
                /** @var list<int|string> $keys */
                $keys = array_values($models->modelKeys());

                foreach ($configs as $mirror => $config) {
                    $this->dispatch(new MirrorKeys($mirror, $model::class, $keys, $remove ? MirrorKeys::REMOVE : MirrorKeys::SYNC), $mirror, $config);
                }
            },
            $model->qualifyColumn($key),
            $key,
        );
    }

    /**
     * Apply a MirrorKeys job: write the owner rows as they are now, delete the rows gone
     * from the owner or not mirrored (shouldMirror()), or remove the rows.
     *
     * @param  class-string<Model>  $owner
     * @param  list<int|string>  $keys
     */
    public function sync(string $mirror, string $owner, array $keys, string $action = MirrorKeys::SYNC): void
    {
        $declaration = $this->declaration($mirror, $owner);

        // Turned off since the job was queued.
        if ($this->registry->active($mirror, $owner) === null) {
            return;
        }

        $this->registry->check($mirror);
        $mirrorModel = $declaration->model;
        $table = MirrorTable::of($mirrorModel);

        if ($action !== MirrorKeys::SYNC) {
            $this->writer->delete($table, $keys);

            return;
        }

        $rows = [];
        $found = [];
        $gone = [];

        foreach ($this->ownerRows($mirrorModel, $owner, $keys) as $model) {
            $found[(string) $model->getKey()] = true;

            if ($this->shouldMirror($model, $mirror)) {
                $rows[] = $this->row($mirror, $mirrorModel, $model, $table);
            } else {
                $gone[] = $model->getKey();
            }
        }

        foreach ($keys as $key) {
            if (! isset($found[(string) $key])) {
                $gone[] = $key;
            }
        }

        $this->writer->upsert($table, $rows);
        $this->writer->delete($table, $gone);
    }

    /**
     * The row written to a mirror for an owner model: the mirror model's fromOwner(), or
     * the owner's toMirrorArray(), with the owner's key renamed to the mirror table's key.
     *
     * @param  class-string<MirrorModel>  $mirrorModel
     * @return array<string, mixed>
     */
    public function row(string $mirror, string $mirrorModel, Model $model, MirrorTable $table): array
    {
        if (method_exists($mirrorModel, 'fromOwner')) {
            $row = $mirrorModel::fromOwner($model);
        } elseif (method_exists($model, 'toMirrorArray')) {
            $row = $model->toMirrorArray($mirror);
        } else {
            $row = $model->getAttributes();
        }

        /** @var array<string, mixed> $row */
        if ($table->key !== $table->ownerKey && ! array_key_exists($table->key, $row) && array_key_exists($table->ownerKey, $row)) {
            $row[$table->key] = $row[$table->ownerKey];
            unset($row[$table->ownerKey]);
        }

        return $row;
    }

    /**
     * Write the owner rows (those changed since $since) to a mirror in key order, as the
     * jobs do: the rows shouldMirror() leaves out are deleted from the mirror.
     *
     * @param  class-string<Model>  $owner
     * @param  (callable(int, int): void)|null  $progress  called after each chunk with the rows written and deleted so far
     * @return array{written: int, deleted: int}
     */
    public function backfill(string $mirror, string $owner, ?DateTimeInterface $since = null, int $chunk = 500, ?callable $progress = null): array
    {
        $mirrorModel = $this->declaration($mirror, $owner)->model;
        $this->registry->check($mirror);
        $table = MirrorTable::of($mirrorModel);
        /** @var Model $model */
        $model = new $owner;
        $written = $deleted = 0;

        $this->ownerQuery($mirrorModel, $owner, $since)->chunkById(
            $chunk,
            function (Collection $models) use ($mirror, $mirrorModel, $table, $progress, &$written, &$deleted) {
                $rows = $gone = [];

                foreach ($models as $owned) {
                    if ($this->shouldMirror($owned, $mirror)) {
                        $rows[] = $this->row($mirror, $mirrorModel, $owned, $table);
                    } else {
                        $gone[] = $owned->getKey();
                    }
                }

                $written += $this->writer->upsert($table, $rows);
                $deleted += $this->writer->delete($table, $gone);

                if ($progress !== null) {
                    $progress($written, $deleted);
                }
            },
            $model->getQualifiedKeyName(),
            $model->getKeyName(),
        );

        return ['written' => $written, 'deleted' => $deleted];
    }

    /**
     * Delete the mirror rows whose owner row is gone (or that ownerQuery() leaves out).
     *
     * @param  class-string<Model>  $owner
     * @return int the rows deleted
     */
    public function prune(string $mirror, string $owner, int $chunk = 1000): int
    {
        $mirrorModel = $this->declaration($mirror, $owner)->model;
        $table = MirrorTable::of($mirrorModel);
        /** @var Model $model */
        $model = new $owner;
        $pruned = 0;

        $table->connection->table($table->table)->select($table->key)->chunkById(
            $chunk,
            function ($rows) use ($mirrorModel, $owner, $model, $table, &$pruned) {
                /** @var list<int|string> $keys */
                $keys = $rows->pluck($table->key)->all();
                $kept = array_flip($this->ownerQuery($mirrorModel, $owner)->whereKey($keys)->pluck($model->getKeyName())->map(fn ($key) => (string) $key)->all());

                $pruned += $this->writer->delete($table, array_values(array_filter($keys, fn ($key) => ! isset($kept[(string) $key]))));
            },
            $table->key,
        );

        return $pruned;
    }

    /**
     * Empty a mirror table.
     *
     * @param  class-string<Model>  $owner
     */
    public function flush(string $mirror, string $owner): void
    {
        $this->writer->flush(MirrorTable::of($this->declaration($mirror, $owner)->model));
    }

    /**
     * The owner rows a mirror holds, through the mirror model's ownerQuery(): global scopes
     * off (soft-deleted rows included), changed since $since.
     *
     * @param  class-string<MirrorModel>  $mirrorModel
     * @param  class-string<Model>  $owner
     * @return Builder<Model>
     */
    public function ownerQuery(string $mirrorModel, string $owner, ?DateTimeInterface $since = null): Builder
    {
        /** @var Model $model */
        $model = new $owner;
        $query = $mirrorModel::ownerQuery($model->newQueryWithoutScopes());

        if ($since !== null) {
            $column = $model->usesTimestamps() ? $model->getUpdatedAtColumn() : null;

            if ($column === null) {
                throw new LogicException("{$owner} has no updated_at column to select the rows changed since a time.");
            }

            $query->where($model->qualifyColumn($column), '>=', $since);
        }

        return $query;
    }

    /**
     * The owner rows of $keys.
     *
     * @param  class-string<MirrorModel>  $mirrorModel
     * @param  class-string<Model>  $owner
     * @param  list<int|string>  $keys
     * @return Collection<int, Model>
     */
    protected function ownerRows(string $mirrorModel, string $owner, array $keys): Collection
    {
        return $this->ownerQuery($mirrorModel, $owner)->whereKey($keys)->get();
    }

    /**
     * @param  class-string<Model>  $owner
     */
    protected function declaration(string $mirror, string $owner): MirroredAs
    {
        return $this->registry->mirrorsOf($owner)[$mirror]
            ?? throw new LogicException("{$owner} is not mirrored in [{$mirror}].");
    }

    protected function shouldMirror(Model $model, string $mirror): bool
    {
        return ! method_exists($model, 'shouldMirror') || $model->shouldMirror($mirror);
    }

    /**
     * @param  MirrorConfig  $config
     */
    protected function dispatch(MirrorKeys $job, string $mirror, array $config): void
    {
        $this->registry->check($mirror);

        $job->onConnection($config['queue_connection'])->onQueue($config['queue']);
        $job->shouldBeEncrypted = $config['encrypt'];

        $lock = new UniqueLock(app(Cache::class));

        // A waiting job for the same rows already brings their next state.
        if (! $lock->acquire($job)) {
            return;
        }

        try {
            app(Dispatcher::class)->dispatch($job);
        } catch (Throwable $e) {
            $lock->release($job);

            throw $e;
        }
    }
}
