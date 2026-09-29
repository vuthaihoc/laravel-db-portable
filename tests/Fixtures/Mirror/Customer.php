<?php

namespace DbPortable\Tests\Fixtures\Mirror;

use Illuminate\Database\Eloquent\Model;

/**
 * An owner model that is not mirrored: mirror models read it in the owner database.
 */
class Customer extends Model
{
    protected $table = 'mirror_customers';

    protected $guarded = [];

    public $timestamps = false;
}
