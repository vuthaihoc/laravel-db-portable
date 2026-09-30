<?php

namespace DbPortable\Tests\Fixtures\Mirror;

use DbPortable\Mirror\Attributes\MirroredAs;
use DbPortable\Mirror\Mirrored;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An owner mirrored into XTDB: every version (history), and the current state (ledger).
 */
#[MirroredAs('history', History\Invoice::class)]
#[MirroredAs('ledger', Ledger\Invoice::class)]
class Invoice extends Model
{
    use Mirrored;
    use SoftDeletes;

    protected $table = 'mirror_invoices';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid' => 'boolean'];
    }
}
