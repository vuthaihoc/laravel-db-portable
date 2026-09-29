<?php

namespace DbPortable\Mirror;

use Illuminate\Database\Eloquent\Model;

/**
 * Queues the changes of owner models (see Mirrored). A restore is an update;
 * a soft delete keeps the row, with its deleted_at, in the mirrors.
 */
class MirrorObserver
{
    /** @var array<class-string<Model>, true> */
    protected static array $paused = [];

    public function created(Model $model): void
    {
        $this->changed($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->changed($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        // A force delete is handled by forceDeleted, which follows.
        if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
            return;
        }

        $this->changed($model, 'deleted');
    }

    public function forceDeleted(Model $model): void
    {
        $this->changed($model, 'forceDeleted');
    }

    /**
     * @param  class-string<Model>  $class
     * @return bool whether the class was already paused
     */
    public static function pause(string $class): bool
    {
        $paused = isset(static::$paused[$class]);
        static::$paused[$class] = true;

        return $paused;
    }

    /**
     * @param  class-string<Model>  $class
     */
    public static function resume(string $class): void
    {
        unset(static::$paused[$class]);
    }

    /**
     * @param  'created'|'updated'|'deleted'|'forceDeleted'  $event
     */
    protected function changed(Model $model, string $event): void
    {
        if (! isset(static::$paused[$model::class])) {
            app(MirrorSync::class)->changed($model, $event);
        }
    }
}
