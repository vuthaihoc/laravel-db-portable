<?php

namespace DbPortable\Create;

/**
 * The statements creating a database and its user: $server on a connection to
 * the server (a maintenance database on the PostgreSQL family), $database on
 * a connection to the new database (schema grants on the PostgreSQL family).
 */
final class Plan
{
    /**
     * @param  list<string>  $server
     * @param  list<string>  $database
     * @param  list<string>  $warnings  What the database cannot do, or what the user has to do.
     */
    public function __construct(
        public readonly array $server,
        public readonly array $database = [],
        public readonly array $warnings = [],
    ) {}

    /**
     * @return list<string>
     */
    public function statements(): array
    {
        return [...$this->server, ...$this->database];
    }
}
