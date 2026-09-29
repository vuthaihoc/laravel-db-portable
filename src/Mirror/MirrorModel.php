<?php

namespace DbPortable\Mirror;

use DbPortable\Mirror\Relations\MirrorMorphTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;

use function Illuminate\Support\enum_value;

/**
 * A read-only model of a mirror table, on the mirror's connection.
 *
 * - Its owner model names it with #[MirroredAs]; its connection is its
 *   mirror's (config('db-portable.mirrors')); its table, key and casts
 *   default to the owner's.
 * - Relations to mirror models are read in the mirror, relations to other
 *   models (owner models) in their own database.
 * - Saving, updating or deleting throws MirrorIsReadOnly.
 * - Optional hooks: fromOwner() (the row written for an owner model),
 *   ownerQuery() (how owner rows are loaded) and mirrorSchema().
 */
abstract class MirrorModel extends Model
{
    protected static string $builder = MirrorBuilder::class;

    /** Empty: the owner's key column. */
    protected $primaryKey = '';

    public $incrementing = false;

    /** Never saved: a write fails with MirrorIsReadOnly, not with a mass assignment error. */
    protected $guarded = [];

    /** @var array<class-string<MirrorModel>, array<string, mixed>> */
    private static array $ownerCasts = [];

    /** @var array<class-string<MirrorModel>, string> */
    private static array $ownerKeyTypes = [];

    /**
     * The mirror this model belongs to.
     */
    public static function mirrorName(): string
    {
        return app(MirrorRegistry::class)->ownerOf(static::class)[0]->mirror;
    }

    /**
     * @return class-string<Model>
     */
    public static function ownerClass(): string
    {
        return app(MirrorRegistry::class)->ownerOf(static::class)[1];
    }

    /**
     * The query loading the owner rows to mirror: add the eager loads fromOwner() needs.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function ownerQuery(Builder $query): Builder
    {
        return $query;
    }

    /**
     * Mirror-only schema (indexes the owner does not need), added by db-portable:mirror:schema.
     */
    public static function mirrorSchema(Blueprint $table): void {}

    /**
     * The owner row of this mirror row, read in the owner database.
     *
     * @return BelongsTo<Model, $this>
     */
    public function ownerModel(): BelongsTo
    {
        $owner = static::ownerClass();

        return $this->belongsTo($owner, $this->getKeyName(), (new $owner)->getKeyName(), 'ownerModel');
    }

    /**
     * The connection of the model's mirror (config('db-portable.mirrors.<name>.connection')),
     * unless the model sets $connection.
     */
    public function getConnectionName()
    {
        return enum_value($this->connection) ?? app(MirrorRegistry::class)->config(static::mirrorName())['connection'];
    }

    public function getTable()
    {
        return $this->table ??= (new (static::ownerClass()))->getTable();
    }

    public function getKeyName()
    {
        if ($this->primaryKey === '') {
            $this->primaryKey = (new (static::ownerClass()))->getKeyName();
        }

        return $this->primaryKey;
    }

    public function getKeyType()
    {
        return self::$ownerKeyTypes[static::class] ??= (new (static::ownerClass()))->getKeyType();
    }

    public function getCasts()
    {
        return array_merge($this->ownerCasts(), parent::getCasts());
    }

    /**
     * The owner model's casts, which this model reads its columns with. Override to drop them.
     *
     * @return array<string, mixed>
     */
    protected function ownerCasts(): array
    {
        return self::$ownerCasts[static::class] ??= (new (static::ownerClass()))->getCasts();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = [])
    {
        throw MirrorIsReadOnly::write(static::class, 'save()');
    }

    public function delete()
    {
        throw MirrorIsReadOnly::write(static::class, 'delete()');
    }

    /**
     * Laravel gives a related model without a connection its parent's: an owner model related
     * to a mirror model would be read in the mirror. Related models keep their own connection:
     * the mirror's for mirror models, the default (owner) one for the others.
     *
     * @template TRelatedModel of Model
     *
     * @param  class-string<TRelatedModel>  $class
     * @return TRelatedModel
     */
    protected function newRelatedInstance($class)
    {
        return new $class;
    }

    /**
     * @template TRelatedModel of Model
     * @template TDeclaringModel of Model
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     * @param  string  $foreignKey
     * @param  string  $ownerKey
     * @param  string  $type
     * @param  string  $relation
     * @return MirrorMorphTo<TRelatedModel, TDeclaringModel>
     */
    protected function newMorphTo(Builder $query, Model $parent, $foreignKey, $ownerKey, $type, $relation)
    {
        return new MirrorMorphTo($query, $parent, $foreignKey, $ownerKey, $type, $relation);
    }
}
