<?php

namespace DbPortable\Tests\Conformance;

use DbPortable\Mirror\MirrorRegistry;
use DbPortable\Mirror\MirrorSync;
use DbPortable\Mirror\MirrorTable;
use DbPortable\Mirror\MirrorWriter;
use DbPortable\Tests\Fixtures\Mirror\History;
use DbPortable\Tests\Fixtures\Mirror\Invoice;
use DbPortable\Tests\Fixtures\Mirror\Ledger;
use DbPortable\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Mirrors into XTDB (experimental: XTDB 2.2 is a pre-release, and XTDB is not a CI
 * server): every version on a history mirror, the current state on another.
 * The owner is SQLite.
 */
class XtdbMirrorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->requireConnection('xtdb');

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

        $this->eraseMirrors();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->eraseMirrors();

        parent::tearDown();
    }

    public function test_every_version_reaches_a_history_mirror(): void
    {
        $invoice = $this->invoiceWithThreeVersions();

        $this->assertSame([['10.50', false], ['10.50', true], ['12.00', true]], $this->history($invoice->id));
        $this->assertSame(['2026-01-01 10:00:00', '2026-01-02 10:00:00', '2026-01-03 10:00:00'], $this->validFrom($invoice->id));
        $this->assertTrue(History\Invoice::asOfValidTime('2026-01-02 12:00:00')->findOrFail($invoice->id)->paid);
        $this->assertSame('12.00', History\Invoice::findOrFail($invoice->id)->amount);

        // A version applied late (a retried job) only fills its own period.
        $this->app->make(MirrorSync::class)->applyVersions('history', Invoice::class, [[
            'key' => $invoice->id,
            'row' => ['_id' => $invoice->id, 'number' => 'A-1', 'amount' => 11.0, 'paid' => true, 'updated_at' => '2026-01-02 18:00:00'],
            'at' => '2026-01-02 18:00:00',
        ]]);

        $this->assertSame([['10.50', false], ['10.50', true], ['11.00', true], ['12.00', true]], $this->history($invoice->id));
        $this->assertSame('12.00', History\Invoice::findOrFail($invoice->id)->amount);
    }

    public function test_deletes_on_a_history_mirror(): void
    {
        $invoice = $this->invoiceWithThreeVersions();

        // A soft delete is a version.
        Carbon::setTestNow('2026-01-04 10:00:00');
        $invoice->delete();
        $this->assertNotNull(History\Invoice::findOrFail($invoice->id)->deleted_at);

        // A force delete erases the history (erase_on_force_delete).
        $invoice->forceDelete();
        $this->assertSame([], $this->history($invoice->id));

        // Without erase_on_force_delete, it ends the validity: the history stays.
        config(['db-portable.mirrors.history.erase_on_force_delete' => false]);
        $other = $this->invoiceWithThreeVersions('B-2');
        Carbon::setTestNow('2026-01-05 10:00:00');
        $other->forceDelete();

        $this->assertNull(History\Invoice::find($other->id));
        $this->assertCount(3, $this->history($other->id));
        $this->assertSame('12.00', History\Invoice::asOfValidTime('2026-01-04 10:00:00')->findOrFail($other->id)->amount);
    }

    public function test_the_current_state_on_xtdb(): void
    {
        $invoice = $this->invoiceWithThreeVersions();

        $this->assertSame(['12.00', true], [Ledger\Invoice::findOrFail($invoice->id)->amount, Ledger\Invoice::findOrFail($invoice->id)->paid]);

        // A write never replaces a newer version.
        $table = MirrorTable::of(Ledger\Invoice::class);
        $this->app->make(MirrorWriter::class)->upsert($table, [['_id' => $invoice->id, 'amount' => '1.00', 'updated_at' => '2000-01-01 00:00:00']]);
        $this->assertSame('12.00', Ledger\Invoice::findOrFail($invoice->id)->amount);

        $invoice->forceDelete();
        $this->assertNull(Ledger\Invoice::find($invoice->id));
    }

    public function test_the_mirror_commands_on_xtdb(): void
    {
        config(['db-portable.mirrors.enabled' => false]);
        $invoice = Invoice::create(['number' => 'A-1', 'amount' => '10.50', 'paid' => true]);
        Invoice::create(['number' => 'B-2', 'amount' => '3.00']);
        config(['db-portable.mirrors.enabled' => true]);

        $this->artisan('db-portable:mirror:sync')->assertSuccessful();
        $this->assertSame([['10.50', true]], $this->history($invoice->id));
        $this->assertSame(2, Ledger\Invoice::count());

        $this->artisan('db-portable:mirror:stats', ['--compare' => true, '--keys' => true])
            ->expectsOutputToContain('none kept (XTDB)')
            ->assertSuccessful();

        $this->artisan('db-portable:mirror:flush', ['mirror' => 'history', '--force' => true])->assertSuccessful();
        $this->assertSame([], $this->history($invoice->id));
    }

    private function invoiceWithThreeVersions(string $number = 'A-1'): Invoice
    {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $invoice = Invoice::create(['number' => $number, 'amount' => '10.50']);
        Carbon::setTestNow('2026-01-02 10:00:00');
        $invoice->update(['paid' => true]);
        Carbon::setTestNow('2026-01-03 10:00:00');
        $invoice->update(['amount' => '12.00']);

        return $invoice;
    }

    /**
     * @return list<array{0: string, 1: bool}> amount and paid of every version, oldest first
     */
    private function history(int $id): array
    {
        return History\Invoice::whereKey($id)->history()->get()
            ->map(fn (History\Invoice $version) => [$version->amount, $version->paid])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function validFrom(int $id): array
    {
        /** @var Collection<int, History\Invoice> $versions */
        $versions = History\Invoice::whereKey($id)->history()->get();

        return $versions->map(fn (History\Invoice $version) => Carbon::parse((string) $version->getAttribute('_valid_from'))->utc()->format('Y-m-d H:i:s'))->all();
    }

    private function eraseMirrors(): void
    {
        foreach (['mirror_invoices', 'mirror_invoice_ledger'] as $table) {
            try {
                DB::connection('xtdb')->statement("erase from {$table} where true");
            } catch (Throwable) {
                // not created yet
            }
        }
    }
}
