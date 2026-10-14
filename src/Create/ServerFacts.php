<?php

namespace DbPortable\Create;

/**
 * What the server already has, read by Creator as the admin before planning.
 */
final class ServerFacts
{
    /**
     * @param  string  $currentUser  The admin's user name (without the MySQL host part).
     * @param  bool  $superuser  PostgreSQL: the admin is a superuser (else it must be granted the owner role to create a database for it).
     */
    public function __construct(
        public readonly string $currentUser,
        public readonly bool $databaseExists = false,
        public readonly bool $userExists = false,
        public readonly bool $superuser = true,
    ) {}
}
