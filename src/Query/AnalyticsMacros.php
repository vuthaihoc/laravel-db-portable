<?php

namespace DbPortable\Query;

use Closure;
use DbPortable\Schema\Family;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use RuntimeException;

/**
 * Conditional aggregates and ROLLUP subtotals that run on every database family.
 */
final class AnalyticsMacros
{
    public static function register(): void
    {
        // selectAggregateWhere('sum', 'total', fn ($q) => $q->where('status', 'paid'), 'paid_total')
        Builder::macro('selectAggregateWhere', function (string $function, string $column, Closure $where, string $as) {
            /** @var Builder $this */
            $function = strtolower($function);

            if (! in_array($function, ['count', 'sum', 'avg', 'min', 'max'], true)) {
                throw new InvalidArgumentException("Unsupported aggregate [{$function}].");
            }

            [$condition, $bindings] = AnalyticsMacros::condition($this, $where);
            $grammar = $this->getGrammar();
            $value = $column === '*' ? '1' : $grammar->wrap($column);

            // CASE without ELSE yields NULL, which every aggregate ignores.
            return $this->selectRaw(
                sprintf('%s(case when %s then %s end) as %s', $function, $condition, $value, $grammar->wrap($as)),
                $bindings,
            );
        });

        // selectCountWhere('paid_orders', fn ($q) => $q->where('status', 'paid'))
        Builder::macro('selectCountWhere', function (string $as, Closure $where) {
            /** @var Builder $this */
            return $this->selectAggregateWhere('count', '*', $where, $as);
        });

        // selectSumWhere('paid_total', 'total', fn ($q) => $q->where('status', 'paid'))
        Builder::macro('selectSumWhere', function (string $as, string $column, Closure $where) {
            /** @var Builder $this */
            return $this->selectAggregateWhere('sum', $column, $where, $as);
        });

        // Subtotals and a grand total for the groupBy() columns, as GROUP BY ... WITH ROLLUP.
        // Call it last: it may turn the query into a UNION ALL.
        Builder::macro('rollup', function () {
            /** @var Builder $this */
            return AnalyticsMacros::rollup($this);
        });
    }

    /**
     * Compile a closure of where clauses into a SQL condition and its bindings.
     *
     * @return array{string, list<mixed>}
     */
    public static function condition(Builder $query, Closure $where): array
    {
        $nested = $query->forNestedWhere();
        $where($nested);

        $sql = $query->getGrammar()->compileWheres($nested);

        if ($sql === '') {
            throw new InvalidArgumentException('The condition closure added no where clause.');
        }

        return [(string) preg_replace('/^where /', '', $sql), $nested->getBindings()];
    }

    public static function rollup(Builder $query): Builder
    {
        $groups = $query->groups ?? [];

        if ($groups === []) {
            throw new InvalidArgumentException('rollup() needs groupBy() columns.');
        }

        foreach ($groups as $group) {
            if (! is_string($group)) {
                throw new InvalidArgumentException('rollup() only supports column names in groupBy().');
            }
        }

        /** @var list<string> $groups */
        /** @var Connection $connection */
        $connection = $query->getConnection();
        $grammar = $query->getGrammar();
        $columns = $grammar->columnize($groups);

        $family = Family::of($connection);

        if ($family === Family::MYSQL) {
            $query->groups = [new Expression($columns.' with rollup')];

            return $query;
        }

        if ($family === Family::POSTGRES && ! Family::isCockroachDb($connection)) {
            $query->groups = [new Expression('rollup ('.$columns.')')];

            return $query;
        }

        return self::unionRollup($query, $groups);
    }

    /**
     * CockroachDB and SQLite have no ROLLUP: one query per grouping level.
     *
     * @param  list<string>  $groups
     */
    private static function unionRollup(Builder $query, array $groups): Builder
    {
        if ($query->havings || $query->limit || $query->offset || $query->unions) {
            throw new RuntimeException('rollup() cannot emulate HAVING, LIMIT, OFFSET or unions on this database.');
        }

        $orders = $query->orders;
        $base = $query->clone()->reorder();
        $result = null;

        for ($level = count($groups); $level >= 0; $level--) {
            $kept = array_slice($groups, 0, $level);
            $branch = $base->clone();
            $branch->groups = $kept === [] ? null : $kept;
            $branch->columns = array_map(
                fn ($column) => is_string($column) && in_array($column, $groups, true) && ! in_array($column, $kept, true)
                    ? new Expression('null as '.$query->getGrammar()->wrap($column))
                    : $column,
                $query->columns ?? ['*'],
            );

            $result = $result === null ? $branch : $result->unionAll($branch);
        }

        /** @var Builder $result */
        $result->unionOrders = $orders ?? [];

        return $result;
    }
}
