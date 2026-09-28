<?php

namespace DbPortable\Search;

/**
 * An index a searchable model needs, and whether the table has it.
 */
final class SearchIndex
{
    public const OK = 'ok';

    public const MISSING = 'missing';

    public const OUTDATED = 'outdated';

    public const SKIPPED = 'skipped';

    /**
     * @param  list<string>  $columns
     * @param  string  $up  Blueprint code creating the index
     * @param  string  $down  Blueprint code dropping it
     * @param  string|null  $replaces  an existing index to drop first (outdated)
     */
    public function __construct(
        public readonly string $model,
        public readonly ?string $connection,
        public readonly string $table,
        public readonly string $kind,
        public readonly array $columns,
        public readonly string $status,
        public readonly string $detail = '',
        public readonly string $up = '',
        public readonly string $down = '',
        public readonly ?string $replaces = null,
    ) {}

    public function needsMigration(): bool
    {
        return in_array($this->status, [self::MISSING, self::OUTDATED], true);
    }
}
