<?php

namespace DbPortable\Schema;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Database\Schema\Grammars\Grammar as SchemaGrammar;
use Illuminate\Database\Schema\IndexDefinition;
use Illuminate\Support\Fluent;
use InvalidArgumentException;

/**
 * Blueprint and schema builder macros for migrations that run on several
 * database families. Each emits the closest equivalent, or skips the feature
 * with a warning (an exception in strict mode) when the database has none.
 */
final class SchemaMacros
{
    public static function register(): void
    {
        // A GIN index on a whole JSON column (PostgreSQL: use jsonb()).
        Blueprint::macro('jsonIndex', function (string $column, ?string $name = null): Fluent {
            /** @var Blueprint $this */
            /** @var Connection $connection */
            $connection = (fn () => $this->connection)->call($this);

            if (Family::of($connection) === Family::POSTGRES) {
                return $this->index($column, $name, 'gin');
            }

            Unsupported::skip("jsonIndex('{$column}') on {$connection->getDriverName()}: there is no index on a whole JSON column; index a scalar column instead.");

            return new IndexDefinition;
        });

        // A json()/jsonb() column with a default value (arrays and scalars are encoded as JSON).
        Blueprint::macro('jsonWithDefault', function (string $column, mixed $default, bool $binary = false): ColumnDefinition {
            /** @var Blueprint $this */
            /** @var Connection $connection */
            $connection = (fn () => $this->connection)->call($this);
            $definition = $binary ? $this->jsonb($column) : $this->json($column);
            $json = json_encode($default, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            if (Family::isMatrixOne($connection)) {
                Unsupported::skip("jsonWithDefault('{$column}') on matrixone: JSON columns cannot have a default, so the column is nullable; set the default in the model's \$attributes.");

                // Without a default, inserts omitting the column would fail on NOT NULL.
                return $definition->nullable();
            }

            // MySQL 8.0.13+ and MariaDB take JSON defaults as parenthesized expressions.
            return $definition->default(Family::of($connection) === Family::MYSQL
                ? new Expression("('".str_replace(['\\', "'"], ['\\\\', "''"], $json)."')")
                : $json);
        });

        // An index with descending columns: descIndex('created_at'), descIndex(['score', 'id']),
        // descIndex(['created_at' => 'desc', 'user_id' => 'asc']). MatrixOne accepts the
        // syntax but builds an ascending index.
        Blueprint::macro('descIndex', function (array|string $columns, ?string $name = null): Fluent {
            /** @var Blueprint $this */
            $directions = [];

            foreach ((array) $columns as $key => $value) {
                [$column, $direction] = is_int($key) ? [$value, 'desc'] : [$key, strtolower((string) $value)];

                if (! in_array($direction, ['asc', 'desc'], true)) {
                    throw new InvalidArgumentException("Invalid index direction [{$direction}] for [{$column}].");
                }

                $directions[(string) $column] = $direction;
            }

            /** @var Grammar $grammar */
            $grammar = (fn () => $this->grammar)->call($this);
            $name ??= (fn () => $this->createIndexName('index', array_keys($directions)))->call($this).'_desc';

            $expression = implode(', ', array_map(
                fn (string $column, string $direction) => $grammar->wrap($column).' '.$direction,
                array_keys($directions),
                $directions,
            ));

            return $this->rawIndex($expression, $name);
        });

        // Indexes compiled per database by IndexCompiler (see compilePortableIndex below).
        $portableIndex = function (Blueprint $blueprint, string $kind, array $columns, string $suffix, ?string $name, array $attributes = []): Fluent {
            $name ??= (fn () => $this->createIndexName('index', $columns))->call($blueprint).$suffix;

            return (fn () => $this->addCommand('portableIndex', ['kind' => $kind, 'index' => $name, 'columns' => $columns] + $attributes))->call($blueprint);
        };

        // An index on a JSON key: jsonKeyIndex('meta->source').
        Blueprint::macro('jsonKeyIndex', function (string $path, ?string $name = null) use ($portableIndex): Fluent {
            /** @var Blueprint $this */
            $columns = [str_replace('->', '_', $path)];

            return $portableIndex($this, 'jsonKey', $columns, '', $name, ['path' => $path]);
        });

        // An index carrying extra columns: coveringIndex('video_id', ['title']).
        Blueprint::macro('coveringIndex', function (array|string $columns, array|string $include, ?string $name = null) use ($portableIndex): Fluent {
            /** @var Blueprint $this */
            return $portableIndex($this, 'covering', (array) $columns, '', $name, ['include' => (array) $include]);
        });

        // Fuzzy search on a text column: trigramIndex('word').
        Blueprint::macro('trigramIndex', function (string $column, ?string $name = null) use ($portableIndex): Fluent {
            /** @var Blueprint $this */
            return $portableIndex($this, 'trigram', [$column], '_trigram', $name);
        });

        // An index on some rows: partialIndex('email', 'deleted_at is null').
        Blueprint::macro('partialIndex', function (array|string $columns, string $where, ?string $name = null) use ($portableIndex): Fluent {
            /** @var Blueprint $this */
            return $portableIndex($this, 'partial', (array) $columns, '_partial', $name, ['where' => $where]);
        });

        SchemaGrammar::macro('compilePortableIndex', function (Blueprint $blueprint, Fluent $command): string|array|null {
            /** @var SchemaGrammar $this */
            /** @var Connection $connection */
            $connection = (fn () => $this->connection)->call($this);

            return (new IndexCompiler($this, $connection))->compile($blueprint, $command);
        });

        // Run only the callback matching the connection:
        // $table->forDriver(['pgsql' => fn (Blueprint $table) => ..., 'matrixone,sqlite' => ..., 'default' => ...])
        Blueprint::macro('forDriver', function (array $callbacks): Blueprint {
            /** @var Blueprint $this */
            /** @var Connection $connection */
            $connection = (fn () => $this->connection)->call($this);
            $callback = Family::pick($connection, $callbacks);

            if ($callback instanceof Closure) {
                $callback($this);
            }

            return $this;
        });

        // Schema::forDriver(['crdb' => fn () => DB::statement('...'), 'default' => fn () => null])
        SchemaBuilder::macro('forDriver', function (array $callbacks): mixed {
            /** @var SchemaBuilder $this */
            $connection = $this->getConnection();
            $callback = Family::pick($connection, $callbacks);

            return $callback instanceof Closure ? $callback($this) : null;
        });
    }
}
