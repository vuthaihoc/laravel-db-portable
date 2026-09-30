<?php

namespace DbPortable\Mirror;

use DbPortable\Mirror\Attributes\MirroredAs;
use Illuminate\Database\Eloquent\Model;
use ReflectionAttribute;
use ReflectionClass;

/**
 * For owner models: every change made through Eloquent reaches the mirrors
 * named by #[MirroredAs] through the queue, after the transaction commits.
 *
 * Writes that bypass Eloquent events are queued with
 * Model::where(...)->mirrorable(), or removed with unmirrorable().
 *
 * @mixin Model
 */
trait Mirrored
{
    public static function bootMirrored(): void
    {
        // Not observe(): it makes an instance, which Laravel 13 forbids while the model boots.
        foreach (['created', 'updated', 'deleted', 'forceDeleted'] as $event) {
            static::registerModelEvent($event, fn (Model $model) => app(MirrorObserver::class)->{$event}($model));
        }

        app(MirrorRegistry::class)->register(static::class);
    }

    /**
     * The mirrors of the model: its #[MirroredAs] attributes by default. Override it when a
     * declaration depends on configuration (attributes take constants only).
     *
     * @return list<MirroredAs>
     */
    public function mirroredAs(): array
    {
        return array_map(
            fn (ReflectionAttribute $attribute) => $attribute->newInstance(),
            (new ReflectionClass($this))->getAttributes(MirroredAs::class),
        );
    }

    /**
     * The row written to a mirror: the raw attributes by default. A mirror model's
     * fromOwner() takes precedence.
     *
     * @return array<string, mixed>
     */
    public function toMirrorArray(string $mirror): array
    {
        return $this->getAttributes();
    }

    /**
     * Whether this row belongs in a mirror; a row that stops belonging is removed from it.
     */
    public function shouldMirror(string $mirror): bool
    {
        return true;
    }

    /**
     * Run $callback without queueing the changes of this model class.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public static function withoutMirroring(callable $callback): mixed
    {
        $paused = MirrorObserver::pause(static::class);

        try {
            return $callback();
        } finally {
            if (! $paused) {
                MirrorObserver::resume(static::class);
            }
        }
    }
}
