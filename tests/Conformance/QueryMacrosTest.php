<?php

namespace DbPortable\Tests\Conformance;

use DbPortable\Portable;
use DbPortable\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class PortableItem extends Model
{
    protected $table = 'portable_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }
}

/**
 * The same assertions on every database family.
 */
class QueryMacrosTest extends TestCase
{
    private string $connection;

    private function useConnection(string $connection): void
    {
        $this->requireConnection($connection);
        $this->connection = $connection;

        $schema = Schema::connection($connection);
        $schema->dropIfExists('portable_items');
        $schema->create('portable_items', function (Blueprint $table) {
            $table->bigInteger('id')->primary();
            $table->string('name');
            $table->json('meta')->nullable();
            $table->integer('score')->nullable();
            $table->timestamps();
        });

        $this->table()->insert([
            ['id' => 1, 'name' => 'one', 'score' => 5, 'meta' => json_encode(['amount' => 199000, 'ratio' => 0.85, 'sync' => true, 'profile_index' => 10, 'device' => 'ios'])],
            ['id' => 2, 'name' => 'two', 'score' => null, 'meta' => json_encode(['amount' => '5000', 'ratio' => 0.2, 'sync' => false, 'profile_index' => 2, 'device' => 'android'])],
            ['id' => 3, 'name' => 'three', 'score' => 7, 'meta' => json_encode(new \stdClass)],
            ['id' => 4, 'name' => 'four', 'score' => null, 'meta' => null],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            Schema::connection($this->connection)->dropIfExists('portable_items');
        }

        parent::tearDown();
    }

    private function table(): Builder
    {
        return DB::connection($this->connection)->table('portable_items');
    }

    /**
     * @return list<int>
     */
    private function ids(Builder $query): array
    {
        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    #[DataProvider('connections')]
    public function test_where_json_number(string $connection): void
    {
        $this->useConnection($connection);

        $this->assertSame([1], $this->ids($this->table()->whereJsonNumber('meta->ratio', '>=', 0.8)));
        $this->assertSame([2], $this->ids($this->table()->whereJsonNumber('meta->ratio', '<', 0.5)));
        $this->assertSame([1, 2], $this->ids($this->table()->whereJsonNumber('meta->amount', '>', 1000)->orderBy('id')));
        $this->assertSame([1, 2], $this->ids($this->table()->whereJsonNumber('meta->ratio', '>', 0.8)->orWhereJsonNumber('meta->amount', '=', 5000)->orderBy('id')));
    }

    #[DataProvider('connections')]
    public function test_order_by_json_number(string $connection): void
    {
        $this->useConnection($connection);

        // As text, "10" would sort before "2".
        $this->assertSame([2, 1], $this->ids($this->table()->whereIn('id', [1, 2])->orderByJsonNumber('meta->profile_index')));
        $this->assertSame([1, 2, 3, 4], $this->ids($this->table()->orderByJsonNumber('meta->profile_index', 'desc', nullsLast: true)->orderBy('id')));
        $this->assertSame([2, 1, 3, 4], $this->ids($this->table()->orderByJsonNumber('meta->profile_index', 'asc', nullsLast: true)->orderBy('id')));
    }

    #[DataProvider('connections')]
    public function test_order_by_nulls_last(string $connection): void
    {
        $this->useConnection($connection);

        $this->assertSame([3, 1, 2, 4], $this->ids($this->table()->orderByNullsLast('score', 'desc')->orderBy('id')));
        $this->assertSame([1, 3, 2, 4], $this->ids($this->table()->orderByNullsLast('score')->orderBy('id')));
    }

    #[DataProvider('connections')]
    public function test_json_aggregates(string $connection): void
    {
        $this->useConnection($connection);

        $this->assertEquals(204000, $this->table()->sumJson('meta->amount'));
        $this->assertEquals(102000, $this->table()->avgJson('meta->amount'));
        $this->assertEquals(5000, $this->table()->minJson('meta->amount'));
        $this->assertEquals(199000, $this->table()->maxJson('meta->amount'));
        $this->assertEquals(0, $this->table()->where('id', 0)->sumJson('meta->amount'));
        $this->assertNull($this->table()->where('id', 0)->maxJson('meta->amount'));
    }

    #[DataProvider('connections')]
    public function test_increment_json(string $connection): void
    {
        $this->useConnection($connection);

        $this->table()->where('id', 1)->incrementJson('meta->amount', 5);
        $this->table()->where('id', 2)->decrementJson('meta->amount', 1000);
        $this->table()->where('id', 3)->update(['meta' => '[]']);
        $this->table()->whereIn('id', [3, 4])->incrementJson('meta->views');
        $this->table()->where('id', 1)->incrementJson('meta->ratio', 0.5);

        $meta = $this->table()->orderBy('id')->pluck('meta')->map(fn ($meta) => json_decode((string) $meta, true))->all();

        $this->assertEquals(199005, $meta[0]['amount']);
        $this->assertEqualsWithDelta(1.35, $meta[0]['ratio'], 0.0001);
        $this->assertSame('ios', $meta[0]['device']);
        $this->assertEquals(4000, $meta[1]['amount']);
        $this->assertEquals(['views' => 1], $meta[2]);
        $this->assertEquals(['views' => 1], $meta[3]);
    }

    #[DataProvider('connections')]
    public function test_laravel_json_methods_that_are_already_portable(string $connection): void
    {
        $this->useConnection($connection);

        $this->assertSame([1], $this->ids($this->table()->where('meta->device', 'ios')));
        $this->assertSame([1], $this->ids($this->table()->where('meta->sync', true)));
        $this->assertSame([2], $this->ids($this->table()->where('meta->sync', false)));
        $this->assertSame([2], $this->ids($this->table()->whereLike('meta', '%android%')));
        $this->assertSame([1, 3], $this->ids($this->table()->whereNotLike('meta', '%android%')->orderBy('id')));
    }

    #[DataProvider('connections')]
    public function test_portable_expressions(string $connection): void
    {
        $this->useConnection($connection);

        $portable = Portable::on($connection);

        $row = $this->table()
            ->selectRaw('sum('.$portable->number('meta->amount')->getValue(DB::connection($connection)->getQueryGrammar()).') as total')
            ->first();
        $this->assertEquals(204000, $row?->total);

        $devices = $this->table()->select($portable->text('meta->device'))->whereNotNull('meta')->orderBy('id')->get()
            ->map(fn ($row) => array_values((array) $row)[0])->all();
        $this->assertSame(['ios', 'android', null], $devices);

        $synced = $this->table()->whereRaw($portable->bool('meta->sync')->getValue(DB::connection($connection)->getQueryGrammar()))->pluck('id')->all();
        $this->assertEquals([1], $synced);
    }

    #[DataProvider('connections')]
    public function test_eloquent(string $connection): void
    {
        $this->useConnection($connection);

        $model = (new PortableItem)->setConnection($connection);

        $this->assertEquals(204000, $model->newQuery()->sumJson('meta->amount'));
        $this->assertSame([1], $model->newQuery()->whereJsonNumber('meta->ratio', '>=', 0.8)->pluck('id')->map(fn ($id) => (int) $id)->all());

        $model->newQuery()->whereKey(1)->incrementJson('meta->amount', 1);
        $item = $model->newQuery()->find(1);

        $this->assertEquals(199001, $item?->meta['amount']);
        $this->assertNotNull($item?->updated_at);
    }
}
