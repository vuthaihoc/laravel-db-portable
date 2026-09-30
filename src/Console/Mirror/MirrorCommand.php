<?php

namespace DbPortable\Console\Mirror;

use DbPortable\Mirror\MirrorRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The mirror:* commands work on owner model × mirror pairs, chosen by the
 * {mirror?} argument and the --model option.
 */
abstract class MirrorCommand extends Command
{
    /**
     * @return list<array{mirror: string, owner: class-string<Model>}>
     */
    protected function pairs(MirrorRegistry $registry): array
    {
        $mirror = $this->hasArgument('mirror') ? $this->argument('mirror') : null;
        $mirror = is_string($mirror) && $mirror !== '' ? $mirror : null;
        $owners = $registry->owners();
        $selected = [];

        foreach (array_filter((array) $this->option('model'), 'is_string') as $name) {
            $matches = array_values(array_filter($owners, fn (string $owner) => $owner === ltrim($name, '\\') || class_basename($owner) === $name));

            if (count($matches) !== 1) {
                throw new InvalidArgumentException($matches === []
                    ? "[{$name}] is not an owner model (a model using DbPortable\\Mirror\\Mirrored)."
                    : "[{$name}] names several owner models: use its class name.");
            }

            $selected[] = $matches[0];
        }

        $pairs = [];

        foreach ($selected ?: $owners as $owner) {
            foreach (array_keys($registry->mirrorsOf($owner)) as $name) {
                if ($mirror === null || $mirror === $name) {
                    $pairs[] = ['mirror' => $name, 'owner' => $owner];
                }
            }
        }

        if ($pairs === []) {
            throw new InvalidArgumentException($mirror === null ? 'No owner model is mirrored.' : "No owner model is mirrored in [{$mirror}].");
        }

        return $pairs;
    }

    /**
     * Whether a pair runs: its mirror is configured and, unless $force, turned on.
     *
     * @param  array{mirror: string, owner: class-string<Model>}  $pair
     */
    protected function runs(MirrorRegistry $registry, array $pair, bool $force = false): bool
    {
        $state = $registry->state($pair['mirror'], $pair['owner']);

        if ($state === 'unconfigured') {
            $this->components->warn($this->label($pair).': the mirror is not configured in db-portable.mirrors, skipped.');

            return false;
        }

        if ($state === 'off' && ! $force) {
            $this->components->warn($this->label($pair).': turned off in this environment, skipped (--force includes it).');

            return false;
        }

        return true;
    }

    /**
     * @param  array{mirror: string, owner: class-string<Model>}  $pair
     */
    protected function label(array $pair): string
    {
        return class_basename($pair['owner']).' → '.$pair['mirror'];
    }
}
