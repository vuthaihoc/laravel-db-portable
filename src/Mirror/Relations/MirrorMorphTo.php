<?php

namespace DbPortable\Mirror\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A morphTo of a mirror model: the related models keep their own connection
 * instead of the mirror's (see MirrorModel::newRelatedInstance()).
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphTo<TRelatedModel, TDeclaringModel>
 */
class MirrorMorphTo extends MorphTo
{
    /**
     * @param  string  $type
     * @return TRelatedModel
     */
    public function createModelByType($type)
    {
        $instance = parent::createModelByType($type);

        // MorphTo gave a model without a connection the mirror's: it gets its default one back.
        if ((new $instance)->getConnectionName() === null) {
            $instance->setConnection(null);
        }

        return $instance;
    }
}
