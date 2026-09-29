<?php

namespace DbPortable\Tests\Fixtures\Mirror\Analytics;

use DbPortable\Mirror\MirrorModel;
use DbPortable\Tests\Fixtures\Mirror\Customer;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends MirrorModel
{
    use SoftDeletes;

    /**
     * An owner model: read in the owner database.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * A mirror model of the same mirror: read in the mirror.
     *
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
