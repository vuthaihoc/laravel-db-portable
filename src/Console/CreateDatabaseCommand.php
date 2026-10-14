<?php

namespace DbPortable\Console;

use DbPortable\Create\CreateOptions;
use DbPortable\Create\Creator;
use DbPortable\Create\Plan;
use DbPortable\Schema\Family;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Creates a connection's database and a user limited to it, as an admin: the
 * connection's own user, else --admin-user, else a user asked for while the
 * server refuses the previous one.
 */
class CreateDatabaseCommand extends Command
{
    /** @var string */
    protected $signature = 'db-portable:db:create
        {connection? : The connection whose database to create (default: the default connection, unless --driver is given)}
        {--driver= : Without a connection: the driver of the server (pgsql, crdb, mysql, mariadb, matrixone, sqlite); with one: replaces its driver}
        {--o|option=* : A connection setting as key=value, repeatable (host, port, username, password, sslmode...); replaces the connection\'s}
        {--database= : The database name (default: the connection\'s)}
        {--admin-user= : A user allowed to create databases and users (default: the connection\'s user; asked when refused)}
        {--admin-password= : The admin\'s password (asked when --admin-user is given without it)}
        {--user= : The user to create or grant (default: the connection\'s user, when it is not the admin)}
        {--password= : The user\'s password (asked for another user than the connection\'s; empty: generated)}
        {--no-user : Create the database only}
        {--privileges= : all (default: migrations included), readwrite (rows only) or readonly}
        {--owner= : PostgreSQL family: the database owner (default: the user with "all")}
        {--user-host=% : MySQL, MariaDB: the host part of the account}
        {--role= : MatrixOne: the role holding the privileges (default: <database>_<privileges>)}
        {--schema= : PostgreSQL family: the schema to grant on (default: public)}
        {--charset= : The character set (MySQL default: the connection\'s)}
        {--collation= : The collation (MySQL default: the connection\'s)}
        {--require-ssl : MySQL, MariaDB: the user must connect over SSL}
        {--connection-limit= : The user\'s maximum number of connections}
        {--password-days= : The user\'s password expires after this many days}
        {--public : PostgreSQL family: keep PUBLIC\'s access to the new database}
        {--maintenance-database= : PostgreSQL family: the database the admin connects to (default: postgres, defaultdb)}
        {--pretend : Print the statements without running them}
        {--force : Do not ask for confirmation}';

    /** @var string */
    protected $description = 'Create a connection\'s database, and a user limited to it';

    /** @var list<string> */
    protected $aliases = ['db:create'];

    /** @var string */
    protected $help = <<<'HELP'
        Creates the database of a connection and, optionally, a user limited to it. Runs as --admin-user,
        else as the connection's user; while the server refuses it, asks for an admin user and password.

        <comment>Examples:</comment>

          <info>php artisan db:create</info>
              The default connection's database, for its user (an admin is asked for when needed).

          <info>php artisan db:create pgsql --user=app --privileges=readwrite</info>
              The pgsql connection's database, with a user allowed to read and write rows.

          <info>php artisan db:create mysql -o host=10.0.0.5 -o port=3307 --database=shop</info>
              The mysql connection's settings, another server and database.

          <info>php artisan db:create --driver=crdb -o host=1.1.1.1 -o port=9876 --database=shop --admin-user=root</info>
              No connection: a CockroachDB server given by its settings.

          <info>php artisan db:create matrixone --admin-user=root --user=reporting --privileges=readonly --no-interaction</info>
              Without questions: the reporting user's password is generated and printed once.

          <info>php artisan db:create pgsql --user=app --require-ssl --connection-limit=20 --password-days=90 --pretend</info>
              Print the statements (passwords masked) without running them.
        HELP;

    /** Admins tried before giving up. */
    private const ATTEMPTS = 3;

    private bool $generated = false;

    public function handle(): int
    {
        $creator = null;

        try {
            $creator = new Creator($this->connectionName(), $this->settings());
            $database = $this->string('database') ?? $creator->database()
                ?? ($this->interactive() ? $this->string(null, $this->ask('Database name')) : null);

            if ($creator->driver() === Family::SQLITE) {
                return $this->sqlite($creator);
            }

            if ($database === null) {
                throw new InvalidArgumentException('No database name: pass --database.');
            }

            if (! $this->connectAdmin($creator)) {
                return self::FAILURE;
            }

            $options = $this->create($creator, $this->createOptions($creator, $database));

            return $options === null ? self::FAILURE : $this->summary($creator, $options);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $creator?->disconnect();
        }
    }

    private function sqlite(Creator $creator): int
    {
        $path = (string) $creator->database();

        foreach (['user', 'password', 'admin-user', 'privileges', 'owner', 'role', 'schema'] as $option) {
            if ($this->string($option) !== null) {
                $this->components->warn("SQLite has no users or privileges: --{$option} is ignored.");
            }
        }

        if ($this->option('pretend')) {
            $this->components->info("The SQLite file [{$path}] would be created.");
        } else {
            $this->components->info($creator->createSqliteFile()
                ? "Created the SQLite database [{$path}]."
                : "Nothing to create: [{$path}] exists or is in memory.");
        }

        return self::SUCCESS;
    }

    /**
     * Connect as --admin-user, else as the connection's user; ask for another user
     * while the server refuses the previous one.
     */
    private function connectAdmin(Creator $creator): bool
    {
        $user = $this->string('admin-user');
        $password = $this->string('admin-password');

        if ($user !== null && $password === null && $this->interactive()) {
            $password = $this->secret("Password of [{$user}]") ?? '';
        }

        if ($user === null) {
            [$user, $password] = [$creator->username(), $creator->password()];
        }

        for ($attempt = 1; ; $attempt++) {
            if ($user === null) {
                [$user, $password] = $this->askAdmin($creator, null);
            }

            try {
                $creator->connectAs($user, $password, $this->string('maintenance-database'));

                return true;
            } catch (Throwable $e) {
                if (! Creator::deniedAccess($e) || ! $this->interactive() || $attempt >= self::ATTEMPTS) {
                    $this->components->error("[{$user}] cannot connect: ".$e->getMessage());

                    if (Creator::deniedAccess($e)) {
                        $this->line('  Pass a user allowed to create databases and users with --admin-user and --admin-password.');
                    }

                    return false;
                }

                $this->components->warn("[{$user}] cannot connect: ".$e->getMessage());
                [$user, $password] = $this->askAdmin($creator, $user);
            }
        }
    }

    /**
     * @return array{string, string}
     */
    private function askAdmin(Creator $creator, ?string $refused): array
    {
        $default = $creator->driver() === Family::POSTGRES ? 'postgres' : 'root';

        do {
            $user = $this->string(null, $this->ask('Admin user (allowed to create databases and users)', $refused === $default ? null : $default));
        } while ($user === null);

        return [$user, $this->secret("Password of [{$user}]") ?? ''];
    }

    private function createOptions(Creator $creator, string $database): CreateOptions
    {
        $admin = $creator->facts(new CreateOptions($database))->currentUser;
        $user = null;

        if (! $this->option('no-user')) {
            $user = $this->string('user');

            if ($user === null) {
                $default = $creator->username() !== $admin ? $creator->username() : null;
                $user = $this->interactive()
                    ? $this->string(null, $this->ask("User for [{$database}] (empty: none, the admin [{$admin}] only)", $default))
                    : $default;
            }
        }

        $user = $user === $admin ? null : $user;
        $privileges = $this->string('privileges');

        if ($privileges === null && $user !== null && $this->interactive()) {
            $choice = $this->choice("Privileges of [{$user}] on [{$database}]", [CreateOptions::ALL, CreateOptions::READWRITE, CreateOptions::READONLY], 0);
            $privileges = is_string($choice) ? $choice : null;
        }

        $mysql = $creator->driver() === Family::MYSQL;
        $options = new CreateOptions(
            database: $database,
            privileges: $privileges ?? CreateOptions::ALL,
            owner: $this->string('owner'),
            userHost: $this->string('user-host') ?? '%',
            role: $this->string('role'),
            schema: $this->string('schema'),
            charset: $this->string('charset') ?? ($mysql ? $creator->setting('charset') : null),
            collation: $this->string('collation') ?? ($mysql ? $creator->setting('collation') : null),
            requireSsl: (bool) $this->option('require-ssl'),
            connectionLimit: $this->integer('connection-limit'),
            passwordDays: $this->integer('password-days'),
            keepPublic: (bool) $this->option('public'),
        );

        return $user === null ? $options : $options->withUser($user, $this->password($creator, $options->withUser($user, null)));
    }

    /**
     * The user's password: --password (empty: generated), none for an existing user (unchanged),
     * the connection's for the connection's user, else asked (empty: generated).
     */
    private function password(Creator $creator, CreateOptions $options): ?string
    {
        $password = $this->option('password');

        if (is_string($password)) {
            return $password === '' ? $this->generate() : $password;
        }

        if ($creator->facts($options)->userExists) {
            $this->components->info("The user [{$options->user}] exists: its password is unchanged.");

            return null;
        }

        if ($options->user === $creator->username() && ($creator->password() ?? '') !== '') {
            return $creator->password();
        }

        $password = $this->interactive() ? ($this->secret("Password for [{$options->user}] (empty: generate one)") ?? '') : '';

        return $password === '' ? $this->generate() : $password;
    }

    /**
     * Show the plan, confirm and run it; while the server refuses a statement, ask
     * for another admin and plan again.
     *
     * @return CreateOptions|null The options run, null when not run.
     */
    private function create(Creator $creator, CreateOptions $options): ?CreateOptions
    {
        for ($attempt = 1; ; $attempt++) {
            $plan = $this->show($creator, $options);

            if ($attempt === 1 && ($this->option('pretend')
                || (! $this->option('force') && $this->interactive() && ! $this->confirm('Run these statements?', true)))) {
                return $this->option('pretend') ? $options : null;
            }

            try {
                $creator->run($plan, $options->database);

                return $options;
            } catch (Throwable $e) {
                if ($creator->driver() === Family::CRDB && Creator::refusesPasswords($e) && $options->password !== null) {
                    $this->components->warn('CockroachDB runs in insecure mode: the user gets no password.');
                    $options = $options->withUser($options->user, null);

                    continue;
                }

                if (! Creator::deniedAccess($e) || ! $this->interactive() || $attempt >= self::ATTEMPTS) {
                    $this->components->error($e->getMessage());

                    return null;
                }

                $refused = $creator->credentials()[0] ?? null;
                $this->components->warn("[{$refused}] is not allowed: ".$e->getMessage());
                [$user, $password] = $this->askAdmin($creator, $refused);

                try {
                    $creator->connectAs($user, $password, $this->string('maintenance-database'));
                } catch (Throwable $e) {
                    $this->components->error("[{$user}] cannot connect: ".$e->getMessage());

                    return null;
                }

                // The connection's user, which could not create the database, gets its privileges on it.
                if ($options->user === null && ! $this->option('no-user') && $refused === $creator->username() && $refused !== $user) {
                    $options = $options->withUser($refused, null);
                }
            }
        }
    }

    private function show(Creator $creator, CreateOptions $options): Plan
    {
        $facts = $creator->facts($options);
        $planner = $creator->planner($options, $facts);
        $shown = $planner->plan(mask: true);

        $this->components->info(sprintf('As [%s]%s:', $facts->currentUser, $facts->databaseExists ? ", [{$options->database}] exists" : ''));

        foreach ($shown->server as $statement) {
            $this->line("  {$statement};");
        }

        if ($shown->database !== []) {
            $this->line("  <fg=gray>-- in [{$options->database}]</>");

            foreach ($shown->database as $statement) {
                $this->line("  {$statement};");
            }
        }

        foreach ($shown->warnings as $warning) {
            $this->components->warn($warning);
        }

        return $planner->plan();
    }

    private function summary(Creator $creator, CreateOptions $options): int
    {
        if ($this->option('pretend')) {
            return self::SUCCESS;
        }

        $creator->forgetConnection();
        $this->components->info($options->user === null
            ? "The database [{$options->database}] is ready."
            : "The database [{$options->database}] is ready for [{$options->user}] ({$options->privileges}).");

        if ($this->generated && $options->password !== null) {
            $this->line('  The generated password, shown once:');
            $this->newLine();
            $this->line("  DB_DATABASE={$options->database}");
            $this->line("  DB_USERNAME={$options->user}");
            $this->line("  DB_PASSWORD={$options->password}");
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function generate(): string
    {
        $this->generated = true;

        return Str::password(32, symbols: false);
    }

    private function interactive(): bool
    {
        return $this->input->isInteractive();
    }

    /**
     * The connection argument, else the default connection unless --driver names a server.
     */
    private function connectionName(): ?string
    {
        $name = $this->argument('connection');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return $this->string('driver') === null ? (string) config('database.default') : null;
    }

    /**
     * The settings given by --driver and -o key=value.
     *
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        $settings = [];

        foreach ((array) $this->option('option') as $option) {
            if (! is_string($option) || ! preg_match('/^\s*([A-Za-z0-9_]+)\s*=(.*)$/s', $option, $match)) {
                throw new InvalidArgumentException('-o takes key=value: ['.(is_string($option) ? $option : '').'].');
            }

            $settings[$match[1]] = $match[2];
        }

        if (($driver = $this->string('driver')) !== null) {
            $settings['driver'] = $driver;
        }

        return $settings;
    }

    /**
     * The trimmed, non-empty value of $option, or of $value when $option is null.
     */
    private function string(?string $option, mixed $value = null): ?string
    {
        $value = $option === null ? $value : $this->option($option);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function integer(string $option): ?int
    {
        $value = $this->option($option);
        $value = is_int($value) ? (string) $value : $this->string($option);

        if ($value !== null && ! ctype_digit($value)) {
            throw new InvalidArgumentException("--{$option} must be a positive integer.");
        }

        return $value === null ? null : (int) $value;
    }
}
