<?php

namespace DbPortable\Tests\Conformance;

use DbPortable\Tests\Fixtures\Mirror\Analytics;
use DbPortable\Tests\Fixtures\Mirror\Customer;
use DbPortable\Tests\Fixtures\Mirror\MirrorTables;
use DbPortable\Tests\Fixtures\Mirror\Order;
use DbPortable\Tests\Fixtures\Mirror\OrderItem;
use DbPortable\Tests\Fixtures\Mirror\Summary;
use DbPortable\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The mirror:* commands over every owner × mirror pair: the mirror tables created
 * from the owner columns (the type mapping between the families), the backfill,
 * the comparison, the repair and the flush.
 */
class MirrorCommandsTest extends TestCase
{
    use MirrorTables;

    protected function tearDown(): void
    {
        $this->dropMirrorTables();

        parent::tearDown();
    }

    #[DataProvider('pairs')]
    public function test_the_mirror_commands(string $owner, string $mirror): void
    {
        $this->mirrorPair($owner, $mirror, mirrorTables: false);

        // Rows written before the mirror exists.
        config(['db-portable.mirrors.enabled' => false]);
        $customer = Customer::create(['name' => 'Ann', 'country' => 'VN']);
        $paid = Order::create([
            'customer_id' => $customer->id,
            'status' => 'paid',
            'total' => '10.50',
            'is_paid' => true,
            'meta' => ['tags' => ['a']],
            'shipped_at' => '2026-09-29 10:00:00',
        ]);
        $trashed = Order::create(['status' => 'packed', 'total' => 2]);
        $trashed->delete();
        $draft = Order::create(['status' => 'draft', 'total' => 3]);
        OrderItem::create(['order_id' => $paid->id, 'sku' => 'A-1', 'quantity' => 2]);
        config(['db-portable.mirrors.enabled' => true]);

        $this->artisan('db-portable:mirror:schema', ['--dry-run' => true])->expectsOutputToContain('create table')->assertSuccessful();
        $this->assertFalse(Schema::connection('mirror')->hasTable('mirror_orders'));

        $this->artisan('db-portable:mirror:sync')->assertSuccessful();

        // The mapped columns hold the owner's values.
        $copy = Analytics\Order::findOrFail($paid->id);
        $this->assertSame(
            ['paid', '10.50', true, ['tags' => ['a']], '2026-09-29 10:00:00'],
            [$copy->status, $copy->total, $copy->is_paid, $copy->meta, $copy->shipped_at?->format('Y-m-d H:i:s')],
        );
        $this->assertNotNull(Analytics\Order::withTrashed()->findOrFail($trashed->id)->deleted_at);
        $this->assertNull(Analytics\Order::withTrashed()->find($draft->id));
        $this->assertSame(2, Analytics\OrderItem::findOrFail(OrderItem::firstOrFail()->id)->quantity);
        $this->assertSame('VN', Summary\Order::findOrFail($paid->id)->customer_country);
        $this->assertTrue(Schema::connection('mirror')->hasIndex('mirror_orders', ['status']));

        $this->artisan('db-portable:mirror:schema', ['mirror' => 'analytics'])->expectsOutputToContain('up to date')->assertSuccessful();
        $this->artisan('db-portable:mirror:stats')->expectsOutputToContain('server: rows')->assertSuccessful();
        $this->artisan('db-portable:mirror:stats', ['mirror' => 'analytics', '--compare' => true, '--keys' => true])->assertSuccessful();

        // Writes that bypass Eloquent: the stats find them, mirror:data repairs them.
        DB::table('mirror_orders')->where('id', $paid->id)->update(['total' => 99]);
        $this->artisan('db-portable:mirror:stats', ['mirror' => 'analytics', '--model' => ['Order'], '--compare' => true])
            ->expectsOutputToContain('≠')
            ->assertFailed();
        DB::table('mirror_orders')->where('id', $trashed->id)->delete();
        $this->artisan('db-portable:mirror:stats', ['mirror' => 'analytics', '--model' => ['Order'], '--keys' => true])
            ->expectsOutputToContain((string) $trashed->id)
            ->assertFailed();

        $this->artisan('db-portable:mirror:data', ['mirror' => 'analytics', '--prune' => true])->assertSuccessful();
        $this->assertSame('99.00', Analytics\Order::findOrFail($paid->id)->total);
        $this->assertNull(Analytics\Order::withTrashed()->find($trashed->id));
        $this->artisan('db-portable:mirror:stats', ['mirror' => 'analytics', '--compare' => true, '--keys' => true])->assertSuccessful();

        // An owner migration adds a column: mirror:schema adds it to the mirror.
        Schema::connection($owner)->table('mirror_orders', fn (Blueprint $table) => $table->string('note', 40)->nullable());
        $this->artisan('db-portable:mirror:schema', ['mirror' => 'analytics', '--model' => ['Order']])->expectsOutputToContain('add to')->assertSuccessful();
        $this->assertTrue(Schema::connection('mirror')->hasColumn('mirror_orders', 'note'));

        $this->artisan('db-portable:mirror:flush', ['mirror' => 'analytics', '--force' => true])->assertSuccessful();
        $this->assertSame(0, Analytics\Order::withTrashed()->count());
        $this->assertSame(0, Analytics\OrderItem::count());
    }
}
