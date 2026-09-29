<?php

namespace DbPortable\Mirror;

use DbPortable\Mirror\Attributes\MirroredAs;
use DbPortable\Schema\Family;
use DbPortable\Schema\Unsupported;
use DbPortable\Support\ModelDiscovery;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The owner models and their mirrors: the #[MirroredAs] declarations, and the
 * mirrors' settings in config('db-portable.mirrors'). Owner models register
 * themselves when they boot; the others are found in app/Models when a mirror
 * model needs its owner, or a command every owner.
 *
 * @phpstan-type MirrorConfig array{enabled: bool, connection: string, queue_connection: string|null, queue: string|null, versions: 'latest'|'all', erase_on_force_delete: bool, encrypt: bool}
 */
class MirrorRegistry
{
    /** Keys of config('db-portable.mirrors') that are settings, not mirrors. */
    public const SETTINGS = ['enabled', 'models'];

    /** The rows' current state. */
    public const LATEST = 'latest';

    /** Every committed version (history mirrors: XTDB). */
    public const ALL = 'all';

    /** @var array<class-string<Model>, true> */
    protected array $owners = [];

    protected bool $discovered = false;

    /** @var array<class-string<Model>, array<string, MirroredAs>> */
    protected array $mirrors = [];

    /** @var array<class-string<MirrorModel>, array{0: MirroredAs, 1: class-string<Model>}>|null */
    protected ?array $models = null;

    /** @var array<string, true> mirrors checked against their connection */
    protected array $checked = [];

    /**
     * Register owner models (models using Mirrored) outside app/Models.
     *
     * @param  class-string<Model>  ...$owners
     */
    public function register(string ...$owners): void
    {
        foreach ($owners as $owner) {
            if (! isset($this->owners[$owner])) {
                $this->owners[$owner] = true;
                $this->models = null;
            }
        }
    }

    /**
     * A mirror's settings: config('db-portable.mirrors.<name>').
     *
     * @return MirrorConfig
     */
    public function config(string $mirror): array
    {
        $config = $this->configured($mirror);

        if (! is_array($config) || ! is_string($config['connection'] ?? null) || $config['connection'] === '') {
            throw new InvalidArgumentException("The mirror [{$mirror}] is not configured: add it, with its connection, to db-portable.mirrors.");
        }

        $config += ['enabled' => true, 'queue_connection' => null, 'queue' => null, 'versions' => self::LATEST, 'erase_on_force_delete' => false, 'encrypt' => false];

        if (! in_array($config['versions'], [self::LATEST, self::ALL], true)) {
            throw new InvalidArgumentException("The mirror [{$mirror}] sets versions to [{$config['versions']}]: use latest or all.");
        }

        $config['enabled'] = (bool) $config['enabled'];
        $config['erase_on_force_delete'] = (bool) $config['erase_on_force_delete'];
        $config['encrypt'] = (bool) $config['encrypt'];

        /** @var MirrorConfig */
        return $config;
    }

    /**
     * The settings of a mirror when an owner model's changes reach it in this environment, or
     * null: mirroring is off (mirrors.enabled), the owner model is off (mirrors.models), the
     * mirror is off, or it is not configured (with a warning, an exception in strict mode).
     *
     * @param  class-string<Model>  $owner
     * @return MirrorConfig|null
     */
    public function active(string $mirror, string $owner): ?array
    {
        if (! $this->enabled($owner)) {
            return null;
        }

        if ($this->configured($mirror) === null) {
            Unsupported::skip("The mirror [{$mirror}] of {$owner} is not configured in db-portable.mirrors: {$owner} is not mirrored there.");

            return null;
        }

        $config = $this->config($mirror);

        return $config['enabled'] ? $config : null;
    }

    /**
     * Whether an owner model is mirrored in this environment: mirrors.enabled, then
     * mirrors.models per owner model.
     *
     * @param  class-string<Model>  $owner
     */
    public function enabled(string $owner): bool
    {
        $models = config('db-portable.mirrors.models');

        return (bool) config('db-portable.mirrors.enabled', true)
            && (bool) (is_array($models) ? $models[$owner] ?? true : true);
    }

    /**
     * Check what only a mirror's connection tells: every version needs a history database.
     */
    public function check(string $mirror): void
    {
        $config = $this->config($mirror);

        if ($config['versions'] === self::LATEST || isset($this->checked[$mirror])) {
            return;
        }

        /** @var Connection $connection */
        $connection = DB::connection($config['connection']);

        if (! Family::isXtdb($connection)) {
            throw new InvalidArgumentException("The mirror [{$mirror}] keeps every version (versions: all), which only a history database (XTDB) holds: [{$config['connection']}] keeps one row per key, use versions: latest.");
        }

        $this->checked[$mirror] = true;
    }

    /**
     * The mirrors of an owner model, by name.
     *
     * @param  class-string<Model>|Model  $owner
     * @return array<string, MirroredAs>
     */
    public function mirrorsOf(string|Model $owner): array
    {
        $class = is_string($owner) ? $owner : $owner::class;

        if (! isset($this->mirrors[$class])) {
            $model = is_string($owner) ? new $owner : $owner;

            if (! method_exists($model, 'mirroredAs')) {
                throw new LogicException("[{$class}] is not mirrored: use DbPortable\\Mirror\\Mirrored and #[MirroredAs] on it.");
            }

            $mirrors = [];

            foreach ($model->mirroredAs() as $declaration) {
                if (! $declaration instanceof MirroredAs) {
                    throw new InvalidArgumentException("[{$class}]::mirroredAs() must return MirroredAs declarations.");
                }

                if (! is_subclass_of($declaration->model, MirrorModel::class)) {
                    throw new InvalidArgumentException("[{$class}] is mirrored in [{$declaration->mirror}] as [{$declaration->model}], which does not extend ".MirrorModel::class.'.');
                }

                if (isset($mirrors[$declaration->mirror])) {
                    throw new InvalidArgumentException("[{$class}] is mirrored twice in [{$declaration->mirror}].");
                }

                $mirrors[$declaration->mirror] = $declaration;
            }

            $this->mirrors[$class] = $mirrors;
        }

        return $this->mirrors[$class];
    }

    /**
     * The declaration mirroring into a mirror model, and its owner model.
     *
     * @param  class-string<MirrorModel>  $mirrorModel
     * @return array{0: MirroredAs, 1: class-string<Model>}
     */
    public function ownerOf(string $mirrorModel): array
    {
        $this->models ??= $this->map();

        if (! isset($this->models[$mirrorModel]) && ! $this->discovered) {
            $this->discover();
            $this->models = $this->map();
        }

        return $this->models[$mirrorModel] ?? throw new LogicException(
            "No owner model is mirrored as [{$mirrorModel}]: add #[MirroredAs('<mirror>', \\{$mirrorModel}::class)] to its owner model. "
            .'Owner models are found in app/Models; register the others with '.static::class.'::register().'
        );
    }

    /**
     * Every owner model: the registered ones and those of app/Models.
     *
     * @return list<class-string<Model>>
     */
    public function owners(): array
    {
        if (! $this->discovered) {
            $this->discover();
        }

        $owners = array_keys($this->owners);
        sort($owners);

        return $owners;
    }

    /**
     * The names of the mirrors of every owner model.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];

        foreach ($this->owners() as $owner) {
            array_push($names, ...array_keys($this->mirrorsOf($owner)));
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function configured(string $mirror): ?array
    {
        $mirrors = config('db-portable.mirrors');
        $config = in_array($mirror, self::SETTINGS, true) || ! is_array($mirrors) ? null : $mirrors[$mirror] ?? null;

        return is_array($config) ? $config : null;
    }

    protected function discover(): void
    {
        $this->discovered = true;

        foreach (ModelDiscovery::appModels() as $class) {
            if (in_array(Mirrored::class, class_uses_recursive($class), true)) {
                $this->register($class);
            }
        }
    }

    /**
     * @return array<class-string<MirrorModel>, array{0: MirroredAs, 1: class-string<Model>}>
     */
    protected function map(): array
    {
        $map = [];

        foreach (array_keys($this->owners) as $owner) {
            foreach ($this->mirrorsOf($owner) as $declaration) {
                [$mapped, $mappedOwner] = $map[$declaration->model] ?? [$declaration, $owner];

                if ($mappedOwner !== $owner || $mapped->mirror !== $declaration->mirror) {
                    throw new LogicException("[{$declaration->model}] mirrors both {$mappedOwner} in [{$mapped->mirror}] and {$owner} in [{$declaration->mirror}]: a mirror model mirrors one owner model in one mirror.");
                }

                $map[$declaration->model] = [$declaration, $owner];
            }
        }

        return $map;
    }
}
