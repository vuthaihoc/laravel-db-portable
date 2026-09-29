<?php

namespace DbPortable\Mirror;

use LogicException;

/**
 * Thrown when a mirror model is written: mirrors follow their owner and only the mirror writer changes them.
 */
final class MirrorIsReadOnly extends LogicException
{
    public static function write(string $model, string $operation): self
    {
        return new self("[{$model}] is a mirror model: {$operation} is not allowed, write the owner model instead.");
    }
}
