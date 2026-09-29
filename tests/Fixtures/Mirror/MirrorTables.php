<?php

namespace DbPortable\Tests\Fixtures\Mirror;

use DbPortable\Mirror\MirrorRegistry;
use DbPortable\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The fixture tables of an owner × mirror pair. The owner is the default connection; the
 * fixtures' mirrors (analytics, summary) use the "mirror" connection, a copy of the mirror
 * connection's configuration.
 *
 * @mixin TestCase
 */
trait MirrorTables
{
    /** @var list<string> the owner and mirror connections with fixture tables */
    private array $mirrorConnections = [];

    private function mirrorPair(string $owner, string $mirror): void
    {
        $this->requireConnection($owner);
        $this->requireConnection($mirror);

        DB::setDefaultConnection($owner);
        config([
            'database.connections.mirror' => config("database.connections.{$mirror}"),
            'db-portable.mirrors.analytics' => ['connection' => 'mirror', 'queue' => 'mirrors'],
            'db-portable.mirrors.summary' => ['connection' => 'mirror'],
        ]);
        $this->app->make(MirrorRegistry::class)->register(Order::class, OrderItem::class);

        $this->mirrorConnections = [$owner, 'mirror'];
        $this->dropMirrorTables();

        Schema::connection($owner)->create('mirror_customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('country', 2)->nullable();
        });
        Schema::connection($owner)->create('mirror_orders', function (Blueprint $table) {
            $table->id();
            $this->orderColumns($table);
        });
        Schema::connection($owner)->create('mirror_order_items', function (Blueprint $table) {
            $table->id();
            $this->itemColumns($table);
        });

        // The mirror tables: the owner's columns, keys copied from the owner.
        Schema::connection('mirror')->create('mirror_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $this->orderColumns($table);
        });
        Schema::connection('mirror')->create('mirror_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $this->itemColumns($table);
        });
        Schema::connection('mirror')->create('mirror_order_summaries', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('status', 20);
            $table->decimal('total', 10, 2);
            $table->string('customer_country', 2)->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    private function dropMirrorTables(): void
    {
        [$owner, $mirror] = $this->mirrorConnections + [null, null];

        foreach ($owner === null ? [] : ['mirror_customers', 'mirror_orders', 'mirror_order_items'] as $table) {
            Schema::connection($owner)->dropIfExists($table);
        }

        foreach ($mirror === null ? [] : ['mirror_orders', 'mirror_order_items', 'mirror_order_summaries'] as $table) {
            Schema::connection($mirror)->dropIfExists($table);
        }
    }

    private function orderColumns(Blueprint $table): void
    {
        $table->unsignedBigInteger('customer_id')->nullable();
        $table->string('status', 20)->default('new');
        $table->decimal('total', 10, 2)->default(0);
        $table->boolean('is_paid')->default(false);
        $table->json('meta')->nullable();
        $table->timestamp('shipped_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    private function itemColumns(Blueprint $table): void
    {
        $table->unsignedBigInteger('order_id');
        $table->string('sku', 20);
        $table->integer('quantity');
        $table->timestamps();
    }
}
