<?php

namespace DbPortable\Tests\Fixtures\Mirror;

use DbPortable\Mirror\Attributes\MirroredAs;
use DbPortable\Mirror\Mirrored;
use Illuminate\Database\Eloquent\Model;

#[MirroredAs('analytics', Analytics\OrderItem::class)]
class OrderItem extends Model
{
    use Mirrored;

    protected $table = 'mirror_order_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
