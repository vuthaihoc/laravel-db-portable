<?php

namespace DbPortable\Tests\Unit;

use DbPortable\Mirror\Jobs\MirrorKeys;
use DbPortable\Mirror\Jobs\MirrorVersions;
use DbPortable\Mirror\MirrorRegistry;
use DbPortable\Tests\Fixtures\Mirror\Invoice;
use DbPortable\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * The versions a history mirror (versions "all") gets, as queued: no XTDB server needed
 * (tests/Conformance/XtdbMirrorTest writes them to XTDB).
 */
class MirrorVersionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'db-portable.mirrors.history' => ['connection' => 'xtdb', 'versions' => 'all', 'erase_on_force_delete' => true],
            'db-portable.mirrors.ledger' => ['connection' => 'xtdb'],
        ]);
        $this->app->make(MirrorRegistry::class)->register(Invoice::class);

        Schema::create('mirror_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number');
            $table->decimal('amount', 10, 2);
            $table->boolean('paid')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_each_change_queues_its_version(): void
    {
        Queue::fake();

        Carbon::setTestNow('2026-01-01 10:00:00');
        $invoice = Invoice::create(['number' => 'A-1', 'amount' => '10.50']);
        Carbon::setTestNow('2026-01-02 10:00:00');
        $invoice->update(['paid' => true]);
        Carbon::setTestNow('2026-01-03 10:00:00');
        $invoice->delete();
        $invoice->forceDelete();

        $versions = $this->versions('history');

        // Read back in the owner's transaction: the database default of "paid" is there;
        // the key is XTDB's _id.
        $this->assertSame(['_id', 'amount', 'created_at', 'deleted_at', 'number', 'paid', 'updated_at'], $this->keys($versions[0]['row']));
        $this->assertSame([$invoice->id, 0, '2026-01-01 10:00:00'], [$versions[0]['row']['_id'], $versions[0]['row']['paid'], $versions[0]['at']]);
        $this->assertSame([1, '2026-01-02 10:00:00'], [$versions[1]['row']['paid'] ?? null, $versions[1]['at']]);
        // A soft delete is a version; the force delete erases the history.
        $this->assertSame(['2026-01-03 10:00:00', '2026-01-03 10:00:00'], [$versions[2]['row']['deleted_at'] ?? null, $versions[2]['at']]);
        $this->assertSame(['key' => $invoice->id, 'row' => null, 'erase' => true], array_diff_key($versions[3], ['at' => true]));

        // The ledger keeps the current state, with MirrorKeys jobs.
        Queue::assertPushed(MirrorKeys::class, fn (MirrorKeys $job) => $job->mirror === 'ledger' && $job->keys === [$invoice->id]);
    }

    public function test_mirrorable_and_unmirrorable_queue_versions(): void
    {
        config(['db-portable.mirrors.enabled' => false]);
        Carbon::setTestNow('2026-01-01 10:00:00');
        $first = Invoice::create(['number' => 'A-1', 'amount' => '1']);
        Invoice::create(['number' => 'B-2', 'amount' => '2']);
        config(['db-portable.mirrors.enabled' => true]);
        Queue::fake();

        Invoice::query()->mirrorable();
        $versions = $this->versions('history');
        $this->assertSame([['A-1', '2026-01-01 10:00:00'], ['B-2', '2026-01-01 10:00:00']], array_map(fn (array $version) => [$version['row']['number'] ?? null, $version['at']], $versions));

        Carbon::setTestNow('2026-02-01 10:00:00');
        Invoice::whereKey($first->id)->unmirrorable();
        $this->assertSame(['key' => $first->id, 'row' => null, 'at' => '2026-02-01 10:00:00.000000'], $this->versions('history')[2]);
    }

    /**
     * @return list<array{key: int|string, row: array<string, mixed>|null, at: string, erase?: bool}>
     */
    private function versions(string $mirror): array
    {
        /** @var Collection<int, MirrorVersions> $jobs */
        $jobs = Queue::pushed(MirrorVersions::class, fn (MirrorVersions $job) => $job->mirror === $mirror);

        return $jobs->flatMap(fn (MirrorVersions $job) => $job->versions)->values()->all();
    }

    /**
     * @param  array<string, mixed>|null  $row
     * @return list<string>
     */
    private function keys(?array $row): array
    {
        $keys = array_keys($row ?? []);
        sort($keys);

        return $keys;
    }
}
