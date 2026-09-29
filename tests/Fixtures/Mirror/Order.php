<?php

namespace DbPortable\Tests\Fixtures\Mirror;

use DbPortable\Mirror\Attributes\MirroredAs;
use DbPortable\Mirror\Mirrored;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[MirroredAs('analytics', Analytics\Order::class)]
#[MirroredAs('summary', Summary\Order::class)]
class Order extends Model
{
    use Mirrored;
    use SoftDeletes;

    protected $table = 'mirror_orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'is_paid' => 'boolean',
            'meta' => 'array',
            'shipped_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function shouldMirror(string $mirror): bool
    {
        return $this->status !== 'draft';
    }
}
