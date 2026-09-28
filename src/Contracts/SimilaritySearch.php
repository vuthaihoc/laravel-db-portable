<?php

namespace DbPortable\Contracts;

/**
 * A query builder with typo tolerant (trigram) search (CockroachDB). Elsewhere db-portable's
 * macros use pg_trgm on PostgreSQL, or fall back to whereContains() with a warning.
 */
interface SimilaritySearch
{
    /**
     * @return $this
     */
    public function whereSimilar(string $column, string $value, ?float $threshold = null, bool $unaccent = false, string $boolean = 'and'): static;

    /**
     * @return $this
     */
    public function selectSimilarity(string $column, string $value, string $as = 'similarity', bool $unaccent = false): static;

    /**
     * @return $this
     */
    public function orderBySimilarity(string $column, string $value, string $direction = 'desc', bool $unaccent = false): static;
}
