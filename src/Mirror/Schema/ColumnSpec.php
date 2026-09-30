<?php

namespace DbPortable\Mirror\Schema;

use Illuminate\Database\Schema\Blueprint;

/**
 * A mirror table column as a Blueprint call: $table->{method}(name, ...arguments),
 * nullable, or the primary key.
 */
final class ColumnSpec
{
    /** @var list<int|string> the Blueprint method's arguments after the column name */
    public readonly array $arguments;

    /**
     * @param  array<int|string>  $arguments  the Blueprint method's arguments after the column name
     * @param  string|null  $note  how the type was chosen, when it is a guess
     */
    public function __construct(
        public readonly string $name,
        public readonly string $method,
        array $arguments = [],
        public readonly bool $primary = false,
        public readonly ?string $note = null,
    ) {
        $this->arguments = array_values($arguments);
    }

    public function apply(Blueprint $table): void
    {
        $column = $table->{$this->method}($this->name, ...$this->arguments);

        $this->primary ? $column->primary() : $column->nullable();
    }

    /**
     * The Blueprint call, for a migration.
     */
    public function php(): string
    {
        $arguments = implode(', ', array_map(fn (int|string $argument) => var_export($argument, true), [$this->name, ...$this->arguments]));

        return "\$table->{$this->method}({$arguments})".($this->primary ? '->primary()' : '->nullable()').';';
    }
}
