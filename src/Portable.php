<?php

namespace DbPortable;

use DbPortable\Dialects\Dialect;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Portable SQL expressions for raw query parts (selectRaw, groupBy, joins):
 *
 *     Portable::jsonNumber('plan_data->amount')          // default connection
 *     Portable::on('crdb')->text('flags->device')
 *     Portable::on($query)->asText('tags')              // a query, Eloquent builder or relation
 *     Portable::on($query)->dialect()->jsonBool('flags->sync')   // the SQL string
 */
final class Portable
{
    private function __construct(private readonly Dialect $dialect) {}

    /**
     * @param  Connection|Builder|EloquentBuilder<Model>|Relation<Model, Model, mixed>|string|null  $source  a connection (name), a query or a relation
     */
    public static function on(Connection|Builder|EloquentBuilder|Relation|string|null $source = null): self
    {
        $grammar = match (true) {
            $source instanceof Relation => $source->getQuery()->getQuery()->getGrammar(),
            $source instanceof EloquentBuilder => $source->getQuery()->getGrammar(),
            $source instanceof Builder => $source->getGrammar(),
            $source instanceof Connection => $source->query()->getGrammar(),
            default => DB::connection($source)->query()->getGrammar(),
        };

        return new self(Dialect::for($grammar));
    }

    /**
     * @return Expression<string>
     */
    public static function jsonText(string $path): Expression
    {
        return self::on()->text($path);
    }

    /**
     * @return Expression<string>
     */
    public static function jsonNumber(string $path): Expression
    {
        return self::on()->number($path);
    }

    /**
     * @return Expression<string>
     */
    public static function jsonBool(string $path): Expression
    {
        return self::on()->bool($path);
    }

    /**
     * @return Expression<string>
     */
    public static function castText(string $column): Expression
    {
        return self::on()->asText($column);
    }

    /**
     * @return Expression<string>
     */
    public function text(string $path): Expression
    {
        return new Expression($this->dialect->jsonText($path));
    }

    /**
     * @return Expression<string>
     */
    public function number(string $path): Expression
    {
        return new Expression($this->dialect->jsonNumber($path));
    }

    /**
     * @return Expression<string>
     */
    public function bool(string $path): Expression
    {
        return new Expression($this->dialect->jsonBool($path));
    }

    /**
     * @return Expression<string>
     */
    public function asText(string $column): Expression
    {
        return new Expression($this->dialect->castText($column));
    }

    public function dialect(): Dialect
    {
        return $this->dialect;
    }
}
