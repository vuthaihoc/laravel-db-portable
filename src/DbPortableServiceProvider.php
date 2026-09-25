<?php

namespace DbPortable;

use DbPortable\Console\AuditCommand;
use DbPortable\Console\CopyCommand;
use DbPortable\Console\ScanCommand;
use DbPortable\Dialects\Dialect;
use DbPortable\Schema\SchemaMacros;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class DbPortableServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        static::registerQueryMacros();
        SchemaMacros::register();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ScanCommand::class, AuditCommand::class, CopyCommand::class]);
        }
    }

    /**
     * Query builder macros, available on every connection (and on Eloquent
     * builders, which forward to them). Each compiles for the connection's
     * database family.
     */
    public static function registerQueryMacros(): void
    {
        $dialect = fn (Builder $query): Dialect => Dialect::for($query->getGrammar());

        $operator = function (string $operator): string {
            if (! in_array($operator, ['=', '<', '>', '<=', '>=', '<>', '!='], true)) {
                throw new InvalidArgumentException("Invalid comparison operator [{$operator}].");
            }

            return $operator;
        };

        // where('flags->ratio', '>=', 0.8) compares text on PostgreSQL; this compares numbers.
        Builder::macro('whereJsonNumber', function (string $path, string $op, int|float $value, string $boolean = 'and') use ($dialect, $operator) {
            /** @var Builder $this */
            return $this->whereRaw($dialect($this)->jsonNumber($path).' '.$operator($op).' ?', [$value], $boolean);
        });

        Builder::macro('orWhereJsonNumber', function (string $path, string $op, int|float $value) {
            /** @var Builder $this */
            return $this->whereJsonNumber($path, $op, $value, 'or');
        });

        Builder::macro('orderByJsonNumber', function (string $path, string $direction = 'asc', bool $nullsLast = false) use ($dialect) {
            /** @var Builder $this */
            $expression = $dialect($this)->jsonNumber($path);

            return $this->orderByRaw($nullsLast
                ? $dialect($this)->orderNullsLast($expression, $direction)
                : $expression.' '.(strtolower($direction) === 'desc' ? 'desc' : 'asc'));
        });

        Builder::macro('orderByNullsLast', function (ExpressionContract|string $column, string $direction = 'asc') use ($dialect) {
            /** @var Builder $this */
            $expression = $column instanceof ExpressionContract
                ? (string) $column->getValue($this->getGrammar())
                : $this->getGrammar()->wrap($column);

            return $this->orderByRaw($dialect($this)->orderNullsLast($expression, $direction));
        });

        foreach (['sum', 'avg', 'min', 'max'] as $function) {
            Builder::macro($function.'Json', function (string $path) use ($dialect, $function) {
                /** @var Builder $this */
                $result = $this->aggregate($function, [new Expression($dialect($this)->jsonNumber($path))]);

                return $function === 'sum' ? ($result ?? 0) + 0 : ($result === null ? null : $result + 0);
            });
        }

        // increment() does not accept JSON paths.
        Builder::macro('incrementJson', function (string $path, int|float $amount = 1, array $extra = []) use ($dialect) {
            /** @var Builder $this */
            [$column, $expression] = $dialect($this)->jsonIncrement($path, $amount);

            return $this->update([$column => new Expression($expression)] + $extra);
        });

        Builder::macro('decrementJson', function (string $path, int|float $amount = 1, array $extra = []) {
            /** @var Builder $this */
            return $this->incrementJson($path, -$amount, $extra);
        });
        // Eloquent only returns the result of its passthru methods (sum, avg...);
        // these return values instead of the builder.
        foreach (['sumJson', 'avgJson', 'minJson', 'maxJson'] as $method) {
            EloquentBuilder::macro($method, function (string $path) use ($method) {
                /** @var EloquentBuilder<Model> $this */
                return $this->toBase()->{$method}($path);
            });
        }

        EloquentBuilder::macro('incrementJson', function (string $path, int|float $amount = 1, array $extra = []) {
            /** @var EloquentBuilder<Model> $this */
            return $this->toBase()->incrementJson($path, $amount, $this->addUpdatedAtColumn($extra));
        });

        EloquentBuilder::macro('decrementJson', function (string $path, int|float $amount = 1, array $extra = []) {
            /** @var EloquentBuilder<Model> $this */
            return $this->incrementJson($path, -$amount, $extra);
        });
    }
}
