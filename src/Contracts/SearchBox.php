<?php

namespace DbPortable\Contracts;

/**
 * A query builder that compiles the search box helpers itself (CockroachDB, MatrixOne).
 * db-portable's macros of the same names cover the other databases.
 */
interface SearchBox
{
    /**
     * Values starting with the search, ignoring case; % and _ are matched literally.
     *
     * @return $this
     */
    public function whereStartsWith(string $column, string $value, bool $unaccent = false, string $boolean = 'and'): static;

    /**
     * Values containing the search, ignoring case.
     *
     * @return $this
     */
    public function whereContains(string $column, string $value, bool $unaccent = false, string $boolean = 'and'): static;

    /**
     * Autocomplete: matching values, the ones starting with the search first.
     *
     * @return $this
     */
    public function suggest(string $column, string $value, bool $unaccent = false): static;

    /**
     * The full-text relevance of the columns as a column.
     *
     * @param  string|string[]  $columns
     * @param  array<string, mixed>  $options
     * @return $this
     */
    public function selectFullTextRelevance(string|array $columns, string $value, string $as = 'relevance', array $options = []): static;

    /**
     * @param  string|string[]  $columns
     * @param  array<string, mixed>  $options
     * @return $this
     */
    public function orderByFullTextRelevance(string|array $columns, string $value, array $options = [], string $direction = 'desc'): static;

    /**
     * Full-text matches, the most relevant first.
     *
     * @param  string|string[]  $columns
     * @param  array<string, mixed>  $options
     * @return $this
     */
    public function searchFullText(string|array $columns, string $value, array $options = []): static;
}
