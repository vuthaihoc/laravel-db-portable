<?php

namespace DbPortable\Mirror;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * The Eloquent builder of mirror models: reads only, and relation subqueries
 * (whereHas(), withCount()...) only on relations read in the same database.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class MirrorBuilder extends Builder
{
    /** Query builder methods (and driver methods) that write, reached through __call(). */
    private const WRITES = '/^(insert|update|upsert|delete|truncate|increment|decrement|touch|forcedelete|restore|erase|patch|fillandinsert)/i';

    public function update(array $values)
    {
        throw MirrorIsReadOnly::write($this->model::class, 'update()');
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int|string, mixed>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw MirrorIsReadOnly::write($this->model::class, 'upsert()');
    }

    /**
     * @param  array<int, string>|string|null  $column
     */
    public function touch($column = null)
    {
        throw MirrorIsReadOnly::write($this->model::class, 'touch()');
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw MirrorIsReadOnly::write($this->model::class, 'increment()');
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw MirrorIsReadOnly::write($this->model::class, 'decrement()');
    }

    public function delete()
    {
        throw MirrorIsReadOnly::write($this->model::class, 'delete()');
    }

    public function forceDelete()
    {
        throw MirrorIsReadOnly::write($this->model::class, 'forceDelete()');
    }

    public function fillAndInsert(array $values)
    {
        throw MirrorIsReadOnly::write($this->model::class, 'fillAndInsert()');
    }

    public function fillAndInsertOrIgnore(array $values)
    {
        throw MirrorIsReadOnly::write($this->model::class, 'fillAndInsertOrIgnore()');
    }

    public function fillAndInsertGetId(array $values)
    {
        throw MirrorIsReadOnly::write($this->model::class, 'fillAndInsertGetId()');
    }

    public function has($relation, $operator = '>=', $count = 1, $boolean = 'and', ?Closure $callback = null)
    {
        if (is_string($relation)) {
            $this->ensureSameDatabase(explode('.', $relation)[0], 'whereHas()');
        }

        return parent::has($relation, $operator, $count, $boolean, $callback);
    }

    public function withAggregate($relations, $column, $function = null)
    {
        foreach (is_array($relations) ? $relations : [$relations] as $name => $constraints) {
            $name = is_string($name) ? $name : $constraints;

            if (is_string($name)) {
                $this->ensureSameDatabase(explode(' ', explode('.', $name)[0])[0], 'withCount() and the other relation aggregates');
            }
        }

        return parent::withAggregate($relations, $column, $function);
    }

    /**
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     */
    public function __call($method, $parameters)
    {
        if (preg_match(self::WRITES, $method)) {
            throw MirrorIsReadOnly::write($this->model::class, $method.'()');
        }

        return parent::__call($method, $parameters);
    }

    /**
     * A relation subquery runs in the mirror database: its model must be read there too.
     */
    protected function ensureSameDatabase(string $relation, string $operation): void
    {
        $query = $this->getRelationWithoutConstraints($relation);

        if ($query instanceof MorphTo) {
            return;
        }

        $related = $query->getRelated();
        $database = $related->getConnection()->getName();
        $mirror = $this->model->getConnection()->getName();

        if ($database !== $mirror) {
            throw new LogicException(sprintf(
                '%s cannot use the relation [%s] of %s: %s is read in [%s], not in the mirror [%s]. Copy the fields you need with fromOwner(), or mirror %s too.',
                $operation,
                $relation,
                $this->model::class,
                $related::class,
                $database,
                $mirror,
                $related::class,
            ));
        }
    }
}
