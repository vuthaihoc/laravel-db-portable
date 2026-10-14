<?php

namespace DbPortable\Create;

use InvalidArgumentException;

/**
 * What db-portable:db:create creates: a database and, optionally, a user
 * limited to it.
 */
final class CreateOptions
{
    public const ALL = 'all';

    public const READWRITE = 'readwrite';

    public const READONLY = 'readonly';

    /**
     * @param  string|null  $user  The user to create (or grant, when it exists); null: the database only.
     * @param  string|null  $password  A new user's password; for an existing user, a new password (null: unchanged).
     * @param  string  $privileges  all (migrations included), readwrite (rows only) or readonly.
     * @param  string|null  $owner  PostgreSQL family: the database owner (default: the user with "all", else the admin).
     * @param  string  $userHost  MySQL and MariaDB: the host part of the account.
     * @param  string|null  $role  MatrixOne: the role holding the privileges (default: <database>_<privileges>).
     * @param  string|null  $schema  PostgreSQL family: the schema to grant on (default: public).
     * @param  int|null  $passwordDays  The password expires after this many days (MySQL: every N days; PostgreSQL family: N days from now).
     * @param  bool  $keepPublic  PostgreSQL family: keep PUBLIC's default access to the database and its public schema.
     */
    public function __construct(
        public readonly string $database,
        public readonly ?string $user = null,
        public readonly ?string $password = null,
        public readonly string $privileges = self::ALL,
        public readonly ?string $owner = null,
        public readonly string $userHost = '%',
        public readonly ?string $role = null,
        public readonly ?string $schema = null,
        public readonly ?string $charset = null,
        public readonly ?string $collation = null,
        public readonly bool $requireSsl = false,
        public readonly ?int $connectionLimit = null,
        public readonly ?int $passwordDays = null,
        public readonly bool $keepPublic = false,
    ) {
        foreach (['database' => $database, 'user' => $user, 'owner' => $owner, 'role' => $role, 'schema' => $schema] as $name => $value) {
            if ($value !== null && ($value === '' || strlen($value) > 63 || preg_match('/[\x00-\x1f]/', $value))) {
                throw new InvalidArgumentException("Invalid {$name} name [{$value}].");
            }
        }

        foreach (['charset' => $charset, 'collation' => $collation] as $name => $value) {
            if ($value !== null && ! preg_match('/^[A-Za-z0-9_.@-]+$/', $value)) {
                throw new InvalidArgumentException("Invalid {$name} [{$value}].");
            }
        }

        if (! in_array($privileges, [self::ALL, self::READWRITE, self::READONLY], true)) {
            throw new InvalidArgumentException("Invalid privileges [{$privileges}]: all, readwrite or readonly.");
        }

        if (($connectionLimit !== null && $connectionLimit < 1) || ($passwordDays !== null && $passwordDays < 1)) {
            throw new InvalidArgumentException('The connection limit and the password days must be positive.');
        }
    }

    public function withUser(?string $user, ?string $password): self
    {
        return new self(
            $this->database, $user, $password, $this->privileges, $this->owner, $this->userHost, $this->role, $this->schema,
            $this->charset, $this->collation, $this->requireSsl, $this->connectionLimit, $this->passwordDays, $this->keepPublic,
        );
    }
}
