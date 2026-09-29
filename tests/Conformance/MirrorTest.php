<?php

namespace DbPortable\Tests\Conformance;

use DbPortable\Mirror\MirrorTable;
use DbPortable\Mirror\MirrorWriter;
use DbPortable\Tests\Fixtures\Mirror\Analytics;
use DbPortable\Tests\Fixtures\Mirror\Customer;
use DbPortable\Tests\Fixtures\Mirror\MirrorTables;
use DbPortable\Tests\Fixtures\Mirror\Order;
use DbPortable\Tests\Fixtures\Mirror\OrderItem;
use DbPortable\Tests\Fixtures\Mirror\Summary;
use DbPortable\Tests\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Mirrors over every owner × mirror pair of the conformance connections: the queue
 * engine (sync queue), the writer's upserts and version guard, and mirror models.
 */
class MirrorTest extends TestCase
{
    use MirrorTables;

    /**
     * @return array<string, array{string, string}>
     */
    public static function pairs(): array
    {
        $pairs = [];

        foreach (['sqlite', 'pgsql', 'mysql', 'crdb', 'matrixone'] as $owner) {
            foreach (['sqlite_mirror', 'pgsql', 'mysql', 'crdb', 'matrixone'] as $mirror) {
                if ($owner !== $mirror) {
                    $pairs["{$owner} → {$mirror}"] = [$owner, $mirror];
                }
            }
        }

        return $pairs;
    }

    protected function tearDown(): void
    {
        $this->dropMirrorTables();

        parent::tearDown();
    }

    #[DataProvider('pairs')]
    public function test_owner_changes_reach_the_mirror(string $owner, string $mirror): void
    {
        $this->mirrorPair($owner, $mirror);

        $customer = Customer::create(['name' => 'Ann', 'country' => 'VN']);
        $order = Order::create([
            'customer_id' => $customer->id,
            'status' => 'paid',
            'total' => '10.50',
            'is_paid' => true,
            'meta' => ['tags' => ['a', 'b']],
            'shipped_at' => '2026-09-29 10:00:00',
        ]);

        // Read back with the owner's casts.
        $copy = Analytics\Order::findOrFail($order->id);
        $this->assertSame('mirror', $copy->getConnection()->getName());
        $this->assertSame('paid', $copy->status);
        $this->assertSame('10.50', $copy->total);
        $this->assertTrue($copy->is_paid);
        $this->assertSame(['tags' => ['a', 'b']], $copy->meta);
        $this->assertSame('2026-09-29 10:00:00', $copy->shipped_at?->format('Y-m-d H:i:s'));
        $this->assertSame($order->updated_at?->format('Y-m-d H:i:s'), $copy->updated_at?->format('Y-m-d H:i:s'));

        $order->update(['status' => 'shipped', 'total' => '12.00', 'is_paid' => false]);
        $copy = Analytics\Order::findOrFail($order->id);
        $this->assertSame(['shipped', '12.00', false], [$copy->status, $copy->total, $copy->is_paid]);

        // A soft delete keeps the row with its deleted_at; a restore clears it.
        $order->delete();
        $this->assertNull(Analytics\Order::find($order->id));
        $this->assertNotNull(Analytics\Order::withTrashed()->findOrFail($order->id)->deleted_at);
        $order->restore();
        $this->assertNull(Analytics\Order::findOrFail($order->id)->deleted_at);

        // shouldMirror(): a draft is not mirrored, and leaves the mirror when it becomes one.
        $draft = Order::create(['status' => 'draft']);
        $this->assertNull(Analytics\Order::withTrashed()->find($draft->id));
        $draft->update(['status' => 'paid']);
        $this->assertNotNull(Analytics\Order::find($draft->id));
        $draft->update(['status' => 'draft']);
        $this->assertNull(Analytics\Order::withTrashed()->find($draft->id));

        $order->forceDelete();
        $this->assertNull(Analytics\Order::withTrashed()->find($order->id));

        // A model without soft deletes.
        $item = OrderItem::create(['order_id' => $draft->id, 'sku' => 'A-1', 'quantity' => 2]);
        $this->assertSame(2, Analytics\OrderItem::findOrFail($item->id)->quantity);
        $item->delete();
        $this->assertNull(Analytics\OrderItem::find($item->id));
    }

    #[DataProvider('pairs')]
    public function test_writes_that_bypass_eloquent_and_the_version_guard(string $owner, string $mirror): void
    {
        $this->mirrorPair($owner, $mirror);

        $orders = array_map(fn (int $total) => Order::create(['status' => 'new', 'total' => $total]), [1, 2, 3]);

        // A mass update fires no model event: mirrorable() queues the rows.
        Order::query()->update(['status' => 'packed']);
        $this->assertSame(3, Analytics\Order::where('status', 'new')->count());
        Order::query()->mirrorable(2);
        $this->assertSame(3, Analytics\Order::where('status', 'packed')->count());

        Order::whereKey($orders[0]->id)->unmirrorable();
        $this->assertSame(2, Analytics\Order::count());
        $this->assertNotNull(Order::find($orders[0]->id));

        Order::withoutMirroring(fn () => $orders[1]->update(['status' => 'hidden']));
        $this->assertSame('packed', Analytics\Order::findOrFail($orders[1]->id)->status);

        // A write never replaces a newer version; replaying one changes nothing.
        $table = MirrorTable::of(Analytics\Order::class);
        $writer = $this->app->make(MirrorWriter::class);
        $writer->upsert($table, [['id' => $orders[2]->id, 'status' => 'stale', 'updated_at' => '2000-01-01 00:00:00']]);
        $this->assertSame('packed', Analytics\Order::findOrFail($orders[2]->id)->status);
        $writer->upsert($table, [['id' => $orders[2]->id, 'status' => 'newer', 'updated_at' => '2037-01-01 00:00:00']]);
        $writer->upsert($table, [['id' => $orders[2]->id, 'status' => 'newer', 'updated_at' => '2037-01-01 00:00:00']]);
        $this->assertSame('newer', Analytics\Order::findOrFail($orders[2]->id)->status);
        $this->assertSame('3.00', Analytics\Order::findOrFail($orders[2]->id)->total);
    }

    #[DataProvider('pairs')]
    public function test_relations_read_where_their_model_lives(string $owner, string $mirror): void
    {
        $this->mirrorPair($owner, $mirror);

        $customer = Customer::create(['name' => 'Ann', 'country' => 'VN']);
        $order = Order::create(['customer_id' => $customer->id, 'status' => 'paid', 'total' => '30.00']);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'A', 'quantity' => 1]);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'B', 'quantity' => 3]);

        // Eager loading: the owner models from the owner database, the mirror models from the mirror.
        $copy = Analytics\Order::with('customer', 'items', 'ownerModel')->findOrFail($order->id);
        $this->assertSame('Ann', $copy->customer?->name);
        $this->assertSame($owner, $copy->customer->getConnection()->getName());
        $this->assertSame(['A', 'B'], $copy->items->pluck('sku')->sort()->values()->all());
        $this->assertSame('mirror', $copy->items->firstOrFail()->getConnection()->getName());
        $this->assertInstanceOf(Order::class, $copy->ownerModel);
        $this->assertSame($owner, $copy->ownerModel->getConnection()->getName());

        // Lazy loading.
        $this->assertSame($owner, Analytics\Order::findOrFail($order->id)->customer?->getConnection()->getName());

        // Relation subqueries run in the mirror, on mirror relations only.
        $this->assertSame(1, Analytics\Order::whereHas('items', fn ($query) => $query->where('quantity', '>', 2))->count());
        $this->assertSame(2, Analytics\Order::withCount('items')->findOrFail($order->id)->getAttribute('items_count'));

        try {
            Analytics\Order::whereHas('customer')->count();
            $this->fail('whereHas() on an owner relation should throw.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('fromOwner()', $e->getMessage());
        }

        // fromOwner() reshapes the row, ownerQuery() eager loads what it reads.
        $summary = Summary\Order::findOrFail($order->id);
        $this->assertSame(['paid', '30.00', 'VN'], [$summary->status, $summary->total, $summary->customer_country]);
    }
}
