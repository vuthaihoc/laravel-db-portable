<?php

namespace DbPortable\Mirror\Schema;

use BackedEnum;
use DateTimeInterface;
use DbPortable\Schema\Family;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Connection;
use JsonSerializable;
use stdClass;

/**
 * Maps an owner column (Schema::getColumns()) to the Blueprint column of a mirror
 * table on another database. Every column but the key is nullable: the owner
 * enforces the constraints, and rows are reshaped (fromOwner()).
 *
 * - The owner model's cast completes the type when the column type does not
 *   tell it: booleans stored as tinyint (MatrixOne), JSON stored as text or
 *   decimals without precision (SQLite).
 * - Timestamps become datetime on the MySQL family (no 2038 limit, no time
 *   zone conversion), and keep their time zone on the PostgreSQL family.
 * - JSON becomes jsonb on the PostgreSQL family.
 */
final class ColumnMapper
{
    /** MySQL rows hold at most 65,535 bytes: longer strings become text. */
    private const MYSQL_MAX_VARCHAR = 16000;

    /** MatrixOne decimals hold at most 38 digits. */
    private const MATRIXONE_MAX_PRECISION = 38;

    private string $family;

    private bool $matrixOne;

    private bool $mysql;

    public function __construct(Connection $mirror, private string $ownerFamily)
    {
        $this->family = Family::of($mirror) ?? Family::POSTGRES;
        $this->matrixOne = Family::isMatrixOne($mirror);
        $this->mysql = $this->family === Family::MYSQL && ! $this->matrixOne;
    }

    /**
     * @param  array{name: string, type_name: string, type: string}  $column  an owner column
     * @param  string|null  $cast  the owner model's cast of the column
     * @param  string|null  $name  the mirror column's name (default: the owner column's)
     * @return ColumnSpec|null null when the type has no equivalent
     */
    public function map(array $column, ?string $cast, ?string $name = null, bool $primary = false): ?ColumnSpec
    {
        $name ??= $column['name'];
        $typeName = strtolower($column['type_name']);
        $type = strtolower($column['type']);
        [$castType, $castArgument] = array_pad(explode(':', strtolower((string) $cast), 2), 2, null);
        preg_match('/\((\d+)(?:\s*,\s*(\d+))?\)/', $type, $parameters);
        $length = isset($parameters[1]) ? (int) $parameters[1] : null;
        $scale = isset($parameters[2]) ? (int) $parameters[2] : null;
        $unsigned = str_contains($type, 'unsigned');
        $spec = fn (string $method, array $arguments = [], ?string $note = null) => new ColumnSpec($name, $method, $arguments, $primary, $note);

        // Encrypted casts store ciphertext.
        if ($castType !== null && str_starts_with($castType, 'encrypted')) {
            return $spec('text');
        }

        if (in_array($typeName, ['bool', 'boolean'], true) || $type === 'tinyint(1)' || in_array($castType, ['bool', 'boolean'], true)) {
            return $spec('boolean');
        }

        if (in_array($typeName, ['json', 'jsonb'], true) || in_array($castType, ['array', 'json', 'object', 'collection'], true)) {
            return $spec($this->family === Family::POSTGRES ? 'jsonb' : 'json');
        }

        if ($typeName === 'year') {
            return $spec('year');
        }

        $integer = match ($typeName) {
            'tinyint' => 'tinyInteger',
            'smallint', 'int2' => 'smallInteger',
            'mediumint' => 'mediumInteger',
            'int', 'int4' => 'integer',
            'bigint', 'int8' => 'bigInteger',
            // SQLite integers have 64 bits, whatever the migration declared.
            'integer' => $this->ownerFamily === Family::SQLITE ? 'bigInteger' : 'integer',
            default => null,
        };

        if ($integer !== null) {
            return $spec($unsigned ? 'unsigned'.ucfirst($integer) : $integer);
        }

        if (in_array($typeName, ['decimal', 'numeric', 'number'], true)) {
            $places = $scale ?? ($castType === 'decimal' && is_numeric($castArgument) ? (int) $castArgument : ($length !== null ? 0 : 10));
            $total = $length ?? self::MATRIXONE_MAX_PRECISION;
            $note = $length === null ? "decimal({$total}, {$places}): the owner column has no precision" : null;

            if ($this->matrixOne && $total > self::MATRIXONE_MAX_PRECISION) {
                [$total, $note] = [self::MATRIXONE_MAX_PRECISION, "decimal({$total}, {$places}) exceeds MatrixOne's 38 digits"];
            }

            return $spec('decimal', [$total, min($places, $total)], $note);
        }

        if (in_array($typeName, ['float', 'float4', 'float8', 'real', 'double', 'double precision'], true)) {
            return $spec('double');
        }

        if (in_array($typeName, ['varchar', 'character varying', 'nvarchar', 'varchar2'], true)) {
            if ($length === null) {
                // An unbounded varchar; SQLite does not keep the declared length.
                return $this->ownerFamily === Family::SQLITE ? $spec('string', [255]) : $spec('text');
            }

            return $this->family === Family::MYSQL && $length > self::MYSQL_MAX_VARCHAR ? $spec('text') : $spec('string', [$length]);
        }

        if (in_array($typeName, ['char', 'bpchar', 'character', 'nchar'], true)) {
            return $length !== null && $length > 255 ? $spec('string', [$length]) : $spec('char', [$length ?? 255]);
        }

        if (in_array($typeName, ['text', 'tinytext', 'clob', 'citext', 'string'], true)) {
            // MySQL text holds 64 KB; the other databases' text has no such limit.
            return $this->mysql && $this->ownerFamily !== Family::MYSQL ? $spec('longText') : $spec('text');
        }

        if ($typeName === 'mediumtext') {
            return $spec('mediumText');
        }

        if ($typeName === 'longtext') {
            return $spec('longText');
        }

        if (in_array($typeName, ['enum', 'set'], true)) {
            return $spec('string', [255]);
        }

        if ($typeName === 'uuid') {
            return $spec('uuid');
        }

        if ($typeName === 'inet') {
            return $spec('ipAddress');
        }

        if ($typeName === 'cidr') {
            return $spec('string', [43]);
        }

        if ($typeName === 'macaddr') {
            return $spec('macAddress');
        }

        if ($typeName === 'date') {
            return $spec('date');
        }

        if (in_array($typeName, ['time', 'timetz'], true)) {
            return $spec('time', [$length ?? 0]);
        }

        if (in_array($typeName, ['timestamp', 'timestamptz', 'datetime', 'datetime2'], true)) {
            return $this->timestamp($spec, $typeName === 'timestamptz', $length ?? 0);
        }

        if (in_array($typeName, ['blob', 'bytea', 'binary', 'varbinary', 'tinyblob', 'mediumblob', 'longblob'], true)) {
            return $spec('binary');
        }

        // A type without equivalent: the cast may still tell what the values are.
        return match ($castType) {
            'int', 'integer' => $spec('bigInteger'),
            'real', 'float', 'double' => $spec('double'),
            'decimal' => $spec('decimal', [self::MATRIXONE_MAX_PRECISION, is_numeric($castArgument) ? (int) $castArgument : 10]),
            'string' => $spec('text'),
            'date', 'immutable_date' => $spec('date'),
            'datetime', 'immutable_datetime', 'timestamp' => $this->timestamp($spec, false, 0),
            default => null,
        };
    }

    /**
     * A column for values that are not an owner column (fromOwner() computes them), from a sample value.
     */
    public function infer(string $name, mixed $value, bool $primary = false): ColumnSpec
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        $spec = fn (string $method, array $arguments = []) => new ColumnSpec($name, $method, $arguments, $primary, 'not an owner column: the type comes from a sample value');

        return match (true) {
            is_bool($value) => $spec('boolean'),
            is_int($value) => $spec('bigInteger'),
            is_float($value) => $spec('double'),
            $value instanceof DateTimeInterface => $this->timestamp($spec, false, $value->format('u') === '000000' ? 0 : 6),
            is_array($value), $value instanceof stdClass, $value instanceof JsonSerializable, $value instanceof Arrayable => $spec($this->family === Family::POSTGRES ? 'jsonb' : 'json'),
            is_string($value) && mb_strlen($value) > 255 => $spec('text'),
            default => $spec('string', [255]),
        };
    }

    /**
     * @param  callable(string, list<int|string>=, string|null=): ColumnSpec  $spec
     */
    private function timestamp(callable $spec, bool $withTimeZone, int $precision): ColumnSpec
    {
        return match ($this->family) {
            Family::POSTGRES => $spec($withTimeZone ? 'timestampTz' : 'timestamp', [$precision]),
            default => $spec('dateTime', [$precision]),
        };
    }
}
