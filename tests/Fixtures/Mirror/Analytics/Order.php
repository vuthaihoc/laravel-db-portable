<?php

namespace DbPortable\Tests\Fixtures\Mirror\Analytics;

use DbPortable\Mirror\MirrorModel;
use DbPortable\Tests\Fixtures\Mirror\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;

class Order extends MirrorModel
{
    use SoftDeletes;

    /**
     * The owner rows of this mirror: shouldMirror() leaves the drafts out.
     */
    public static function ownerQuery(Builder $query): Builder
    {
        return $query->where('status', '!=', 'draft');
    }

    public static function mirrorSchema(Blueprint $table): void
    {
        $table->index('status');
    }

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
