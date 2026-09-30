<?php

namespace DbPortable\Mirror;

use DateTimeInterface;
use DbPortable\Mirror\Attributes\MirroredAs;
use DbPortable\Mirror\Jobs\MirrorKeys;
use DbPortable\Mirror\Jobs\MirrorVersions;
use DbPortable\Support\ValueMapper;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Throwable;

/**
 * Queues the changes of owner models for their mirrors, and applies them: the rows'
 * current state (versions "latest", MirrorKeys jobs), or every version (versions "all",
 * history mirrors, MirrorVersions jobs).
 *
 * @phpstan-import-type MirrorConfig from MirrorRegistry
 * @phpstan-import-type Version from XtdbWriter
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

        foreach ($this->registry->mirrorsOf($model) as $mirror => $declaration) {
            $config = $this->registry->active($mirror, $model::class);

            // A new row that is not mirrored has nothing to remove from the mirror.
            if ($config === null || ($event === 'created' && ! $this->shouldMirror($model, $mirror))) {
                continue;
            }

            if ($config['versions'] === MirrorRegistry::ALL) {
                // The version as committed, read now, in the owner's transaction.
                $version = $event === 'forceDeleted' && $config['erase_on_force_delete']
                    ? ['key' => $model->getKey(), 'row' => null, 'at' => $this->now(), 'erase' => true]
                    : $this->versions($mirror, $declaration, $model::class, [$model->getKey()])[0];

                $this->dispatch(new MirrorVersions($mirror, $model::class, [$version]), $mirror, $config);

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
                    $job = match (true) {
                        $config['versions'] === MirrorRegistry::LATEST => new MirrorKeys($mirror, $model::class, $keys, $remove ? MirrorKeys::REMOVE : MirrorKeys::SYNC),
                        $remove => new MirrorVersions($mirror, $model::class, array_map(fn ($key) => ['key' => $key, 'row' => null, 'at' => $this->now()], $keys)),
                        default => new MirrorVersions($mirror, $model::class, $this->versions($mirror, $this->declaration($mirror, $model::class), $model::class, $keys)),
                    };

                    $this->dispatch($job, $mirror, $config);
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
     * Apply a MirrorVersions job: the versions of owner rows, to a history mirror.
     *
     * @param  class-string<Model>  $owner
     * @param  list<Version>  $versions
     */
    public function applyVersions(string $mirror, string $owner, array $versions): void
    {
        $declaration = $this->declaration($mirror, $owner);

        // Turned off since the job was queued.
        if ($this->registry->active($mirror, $owner) === null) {
            return;
        }

        $this->registry->check($mirror);
        $this->writer->versions(MirrorTable::of($declaration->model), $versions);
    }

    /**
     * Write the owner rows (those changed since $since) to a mirror in key order, as the
     * jobs do: the rows shouldMirror() leaves out are deleted from the mirror. A history
     * mirror gets each row as a version valid from its validTime column.
     *
     * @param  class-string<Model>  $owner
     * @param  (callable(int, int): void)|null  $progress  called after each chunk with the rows written and deleted so far
     * @return array{written: int, deleted: int}
     */
    public function backfill(string $mirror, string $owner, ?DateTimeInterface $since = null, int $chunk = 500, ?callable $progress = null): array
    {
        $declaration = $this->declaration($mirror, $owner);
        $mirrorModel = $declaration->model;
        $history = $this->registry->config($mirror)['versions'] === MirrorRegistry::ALL;
        $this->registry->check($mirror);
        $table = MirrorTable::of($mirrorModel);
        /** @var Model $model */
        $model = new $owner;
        $written = $deleted = 0;

        $this->ownerQuery($mirrorModel, $owner, $since)->chunkById(
            $chunk,
            function (Collection $models) use ($mirror, $declaration, $mirrorModel, $table, $history, $progress, &$written, &$deleted) {
                if ($history) {
                    $versions = array_values(array_map(fn (Model $owned) => $this->versionOf($mirror, $declaration, $owned, $owned->getKey()), $models->all()));
                    $this->writer->versions($table, $versions);
                    $written += count(array_filter($versions, fn (array $version) => $version['row'] !== null));
                    $deleted += count(array_filter($versions, fn (array $version) => $version['row'] === null));

                    if ($progress !== null) {
                        $progress($written, $deleted);
                    }

                    return;
                }

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
     * The versions of owner rows a history mirror gets, read now.
     *
     * @param  class-string<Model>  $owner
     * @param  list<int|string>  $keys
     * @return list<Version>
     */
    protected function versions(string $mirror, MirroredAs $declaration, string $owner, array $keys): array
    {
        $rows = $this->ownerRows($declaration->model, $owner, $keys)->keyBy(fn (Model $row) => (string) $row->getKey());

        return array_map(fn (int|string $key) => $this->versionOf($mirror, $declaration, $rows->get((string) $key), $key), $keys);
    }

    /**
     * The row valid from its validTime column, or the end of its validity when it is gone
     * or no longer mirrored.
     *
     * @return Version
     */
    protected function versionOf(string $mirror, MirroredAs $declaration, ?Model $row, int|string $key): array
    {
        if ($row === null || ! $this->shouldMirror($row, $mirror)) {
            return ['key' => $key, 'row' => null, 'at' => $row === null ? $this->now() : $this->validTime($row, $declaration)];
        }

        $table = MirrorTable::of($declaration->model);

        return [
            'key' => $key,
            'row' => (new ValueMapper($table->connection))->row($this->row($mirror, $declaration->model, $row, $table)),
            'at' => $this->validTime($row, $declaration),
        ];
    }

    protected function validTime(Model $row, MirroredAs $declaration): string
    {
        $value = $row->getAttributes()[$declaration->validTime] ?? null;

        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s.u'),
            is_string($value) && $value !== '' => $value,
            default => $this->now(),
        };
    }

    protected function now(): string
    {
        return now()->format('Y-m-d H:i:s.u');
    }

    /**
     * @param  MirrorConfig  $config
     */
    protected function dispatch(MirrorKeys|MirrorVersions $job, string $mirror, array $config): void
    {
        $this->registry->check($mirror);

        $job->onConnection($config['queue_connection'])->onQueue($config['queue']);
        $job->shouldBeEncrypted = $config['encrypt'];

        $lock = $job instanceof ShouldBeUnique ? new UniqueLock(app(Cache::class)) : null;

        // A waiting job for the same rows already brings their next state.
        if ($lock !== null && ! $lock->acquire($job)) {
            return;
        }

        try {
            app(Dispatcher::class)->dispatch($job);
        } catch (Throwable $e) {
            $lock?->release($job);

            throw $e;
        }
    }
}
