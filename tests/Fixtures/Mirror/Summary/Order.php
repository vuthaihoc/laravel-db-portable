<?php

namespace DbPortable\Tests\Fixtures\Mirror\Summary;

use DbPortable\Mirror\MirrorModel;
use DbPortable\Tests\Fixtures\Mirror\Order as OwnerOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * A reshaped mirror: a few columns, plus a field of the customer.
 */
class Order extends MirrorModel
{
    protected $table = 'mirror_order_summaries';

    /**
     * @return array<string, mixed>
     */
    public static function fromOwner(OwnerOrder $order): array
    {
        return $order->only('id', 'customer_id', 'status', 'total', 'updated_at') + [
            'customer_country' => $order->customer?->country,
        ];
    }

    public static function ownerQuery(Builder $query): Builder
    {
        return $query->with('customer');
    }
}
