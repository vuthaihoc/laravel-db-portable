<?php

namespace DbPortable\Mirror\Attributes;

use Attribute;
use DbPortable\Mirror\MirrorModel;
use DbPortable\Mirror\MirrorRegistry;
use InvalidArgumentException;

/**
 * Mirrors an owner model: names the mirror and the mirror model that receives
 * the rows there. Repeat it per mirror:
 *
 *     #[MirroredAs('analytics', Analytics\Order::class)]
 *     #[MirroredAs('history', History\Order::class)]
 *
 * How each mirror runs in an environment (connection, queue, versions, on or
 * off) is configured by name in config('db-portable.mirrors').
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class MirroredAs
{
    /**
     * @param  string  $mirror  the mirror's name: its settings are config('db-portable.mirrors.<name>'), and shouldMirror() / toMirrorArray() receive it
     * @param  class-string<MirrorModel>  $model  the mirror model
     * @param  string  $validTime  history mirrors: the column each version is valid from
     */
    public function __construct(
        public string $mirror,
        public string $model,
        public string $validTime = 'updated_at',
    ) {
        if (in_array($mirror, MirrorRegistry::SETTINGS, true)) {
            throw new InvalidArgumentException("[{$mirror}] cannot name a mirror: db-portable.mirrors.{$mirror} is a setting.");
        }
    }
}
