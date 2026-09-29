<?php

namespace DbPortable\Tests\Fixtures\Mirror;

use DbPortable\Mirror\Attributes\MirroredAs;
use DbPortable\Mirror\Mirrored;
use Illuminate\Database\Eloquent\Model;

/**
 * Declares its mirror in mirroredAs() instead of an attribute, in a mirror the tests do not configure.
 */
class Draft extends Model
{
    use Mirrored;

    protected $table = 'mirror_drafts';

    public function mirroredAs(): array
    {
        return [new MirroredAs('archive', Analytics\Draft::class)];
    }
}
