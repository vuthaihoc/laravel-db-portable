<?php

namespace DbPortable\Support;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use JsonSerializable;
use Stringable;
use UnitEnum;

/**
 * Converts values read from one database, or taken from a model, to values
 * the target connection accepts: JSON for arrays and objects, booleans as the
 * target stores them, instants without an offset in UTC for the MySQL family.
 */
final class ValueMapper
{
    private bool $postgres;

    private bool $mysql;

    public function __construct(Connection $target)
    {
        $grammar = $target->getQueryGrammar();
        $this->postgres = $grammar instanceof PostgresGrammar;
        $this->mysql = $grammar instanceof MySqlGrammar;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $types  target column type names (lower case) by column
     * @return array<string, mixed>
     */
    public function row(array $row, array $types = []): array
    {
        foreach ($row as $column => $value) {
            $row[$column] = $this->value($value, $types[$column] ?? '');
        }

        return $row;
    }

    public function value(mixed $value, string $type = ''): mixed
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof UnitEnum) {
            $value = $value->name;
        } elseif ($value instanceof DateTimeInterface) {
            // As Eloquent stores dates: the time in its own time zone, without an offset.
            return $value->format($value->format('u') === '000000' ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u');
        } elseif ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif ($value instanceof Stringable && ! $value instanceof JsonSerializable) {
            $value = (string) $value;
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (in_array($type, ['bool', 'boolean'], true) && $value !== null) {
            return $this->postgres ? (bool) $value : (int) (bool) $value;
        }

        if (is_bool($value)) {
            return $this->postgres ? $value : (int) $value;
        }

        if ($this->mysql && is_string($value) && $this->hasTimeZoneOffset($value)) {
            // MySQL-family datetime columns take no offset: store the instant in UTC.
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        }

        return $value;
    }

    private function hasTimeZoneOffset(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?([+-]\d{2}(:?\d{2})?|Z)$/', $value);
    }
}
