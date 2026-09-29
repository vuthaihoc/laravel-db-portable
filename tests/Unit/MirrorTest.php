<?php

namespace DbPortable\Tests\Unit;

use DbPortable\Mirror\Attributes\MirroredAs;
use DbPortable\Mirror\Jobs\MirrorKeys;
use DbPortable\Mirror\MirrorIsReadOnly;
use DbPortable\Mirror\MirrorModel;
use DbPortable\Mirror\MirrorRegistry;
use DbPortable\Mirror\MirrorSync;
use DbPortable\Mirror\MirrorTable;
use DbPortable\Mirror\Relations\MirrorMorphTo;
use DbPortable\Tests\Fixtures\Mirror\Analytics;
use DbPortable\Tests\Fixtures\Mirror\Customer;
use DbPortable\Tests\Fixtures\Mirror\Draft;
use DbPortable\Tests\Fixtures\Mirror\MirrorTables;
use DbPortable\Tests\Fixtures\Mirror\Order;
use DbPortable\Tests\Fixtures\Mirror\OrderItem;
use DbPortable\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Mirror behaviour that does not depend on the databases, on SQLite → SQLite
 * (tests/Conformance/MirrorTest covers every pair).
 */
class MirrorTest extends TestCase
{
    use MirrorTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mirrorPair('sqlite', 'sqlite_mirror');
    }

    public function test_mirror_models_are_read_only(): void
    {
        $order = Order::create(['status' => 'paid', 'total' => 5]);
        $copy = Analytics\Order::findOrFail($order->id);

        $writes = [
            'save()' => fn () => $copy->save(),
            'update()' => fn () => $copy->update(['status' => 'x']),
            'delete()' => fn () => $copy->delete(),
            'forceDelete()' => fn () => $copy->forceDelete(),
            'increment()' => fn () => $copy->increment('total'),
            'create()' => fn () => Analytics\Order::create(['id' => 99, 'status' => 'x']),
            'destroy()' => fn () => Analytics\Order::destroy($order->id),
            'query update()' => fn () => Analytics\Order::query()->update(['status' => 'x']),
            'query delete()' => fn () => Analytics\Order::whereKey($order->id)->delete(),
            'insert()' => fn () => Analytics\Order::query()->insert(['id' => 99, 'status' => 'x']),
            'upsert()' => fn () => Analytics\Order::query()->upsert([['id' => 99, 'status' => 'x']], ['id']),
            'updateOrInsert()' => fn () => Analytics\Order::query()->updateOrInsert(['id' => 99], ['status' => 'x']),
            'incrementEach()' => fn () => Analytics\Order::query()->incrementEach(['total' => 1]),
            'incrementJson()' => fn () => Analytics\Order::query()->incrementJson('meta->views'),
            'truncate()' => fn () => Analytics\Order::truncate(),
            'restore()' => fn () => Analytics\Order::query()->restore(),
            'relation create()' => fn () => $copy->items()->create(['id' => 99, 'sku' => 'x', 'quantity' => 1]),
            'relation update()' => fn () => $copy->items()->update(['quantity' => 2]),
        ];

        foreach ($writes as $write => $callback) {
            try {
                $callback();
                $this->fail("{$write} wrote a mirror model.");
            } catch (MirrorIsReadOnly) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, Analytics\Order::count());
        $this->assertSame('paid', Analytics\Order::findOrFail($order->id)->status);
    }

    public function test_a_rolled_back_change_never_reaches_the_mirror(): void
    {
        try {
            DB::transaction(function () {
                Order::create(['id' => 7, 'status' => 'paid']);

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Analytics\Order::count());

        // The rollback released the job's unique lock: the row is mirrored when written again.
        Order::create(['id' => 7, 'status' => 'paid']);
        $this->assertSame('paid', Analytics\Order::findOrFail(7)->status);
    }

    public function test_changes_waiting_in_the_queue_make_one_job_per_row_and_mirror(): void
    {
        Queue::fake();

        $order = Order::create(['status' => 'paid']);
        $order->update(['status' => 'shipped']);
        $order->update(['total' => 5]);

        Queue::assertPushedTimes(MirrorKeys::class, 2);
        Queue::assertPushedOn('mirrors', MirrorKeys::class, fn (MirrorKeys $job) => $job->mirror === 'analytics' && $job->keys === [$order->id]);
        Queue::assertPushed(MirrorKeys::class, fn (MirrorKeys $job) => $job->mirror === 'summary' && $job->queue === null);
    }

    public function test_mirroring_is_turned_off_in_config(): void
    {
        Queue::fake();

        config(['db-portable.mirrors.enabled' => false]);
        $order = Order::create(['status' => 'paid']);
        Order::query()->mirrorable();
        Queue::assertNothingPushed();

        // One mirror: the orders reach analytics only.
        config(['db-portable.mirrors.enabled' => true, 'db-portable.mirrors.summary.enabled' => false]);
        $order->update(['status' => 'shipped']);
        Queue::assertPushedTimes(MirrorKeys::class, 1);
        Queue::assertPushed(MirrorKeys::class, fn (MirrorKeys $job) => $job->mirror === 'analytics');

        // One owner model: the orders stay out, their items are mirrored.
        config(['db-portable.mirrors.models' => [Order::class => false]]);
        $order->update(['status' => 'packed']);
        OrderItem::create(['order_id' => $order->id, 'sku' => 'A', 'quantity' => 1]);
        Queue::assertPushedTimes(MirrorKeys::class, 2);
        Queue::assertPushed(MirrorKeys::class, fn (MirrorKeys $job) => $job->owner === OrderItem::class);
    }

    public function test_a_queued_job_does_nothing_once_mirroring_is_off(): void
    {
        $order = Order::withoutMirroring(fn () => Order::create(['status' => 'paid']));
        $job = new MirrorKeys('analytics', Order::class, [$order->id]);

        config(['db-portable.mirrors.models' => [Order::class => false]]);
        $this->app->call([$job, 'handle']);
        $this->assertSame(0, Analytics\Order::count());

        config(['db-portable.mirrors.models' => [Order::class => true]]);
        $this->app->call([$job, 'handle']);
        $this->assertSame(1, Analytics\Order::count());
    }

    public function test_declaration_and_configuration_errors(): void
    {
        $registry = $this->app->make(MirrorRegistry::class);

        $this->assertThrows(fn () => new MirroredAs('models', Analytics\Order::class), InvalidArgumentException::class, 'is a setting');

        config(['db-portable.mirrors.summary.versions' => 'every']);
        $this->assertThrows(fn () => $registry->config('summary'), InvalidArgumentException::class, 'use latest or all');
        config(['db-portable.mirrors.summary.versions' => MirrorRegistry::ALL]);
        $this->assertThrows(fn () => $registry->check('summary'), InvalidArgumentException::class, 'only a history database (XTDB) holds');
        config(['db-portable.mirrors.summary.versions' => MirrorRegistry::LATEST]);

        // A mirror this environment does not configure: its mirror models cannot be read, and
        // changes skip it with a warning (an exception in strict mode).
        $registry->register(Draft::class);
        $this->assertSame('archive', Analytics\Draft::mirrorName());
        $this->assertThrows(fn () => Analytics\Draft::query(), InvalidArgumentException::class, 'The mirror [archive] is not configured');
        $sync = $this->app->make(MirrorSync::class);
        $sync->changed(new Draft, 'updated');
        config(['db-portable.strict' => true]);
        $this->assertThrows(fn () => $sync->changed(new Draft, 'updated'), RuntimeException::class, 'is not configured in db-portable.mirrors');
        config(['db-portable.strict' => false]);

        $this->assertThrows(fn () => (new class extends MirrorModel {})->getTable(), LogicException::class, 'No owner model is mirrored as');
        $this->assertThrows(fn () => Customer::query()->mirrorable(), LogicException::class, 'is not mirrored');

        // A mirror on the owner's connection would write the owner's table.
        DB::setDefaultConnection('mirror');
        $this->assertThrows(fn () => MirrorTable::of(Analytics\Order::class), LogicException::class, 'would write the table of its owner');
    }

    public function test_morph_to_keeps_owner_models_on_their_connection(): void
    {
        $relation = new MirrorMorphTo(Analytics\Order::query(), new Analytics\Order, 'subject_id', 'id', 'subject_type', 'subject');

        $this->assertNull($relation->createModelByType(Customer::class)->getConnectionName());
        $this->assertSame('mirror', $relation->createModelByType(Analytics\OrderItem::class)->getConnectionName());
    }
}
