<?php

namespace DbPortable\Schema;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use WeakMap;

/**
 * What to do when a database cannot express a schema feature: log a warning
 * and skip it, or throw when config('db-portable.strict') is true.
 *
 * A warning is logged once per application instance (process, or request under
 * Octane): a fallback taken by every search query would otherwise flood the log.
 */
final class Unsupported
{
    /** @var WeakMap<object, array<string, true>>|null */
    private static ?WeakMap $logged = null;

    public static function skip(string $message): void
    {
        if (function_exists('config') && config('db-portable.strict', false)) {
            throw new RuntimeException('[db-portable] '.$message);
        }

        if (! function_exists('app') || ! app()->bound('log')) {
            return;
        }

        $app = app();
        self::$logged ??= new WeakMap;
        $logged = self::$logged[$app] ?? [];

        if (isset($logged[$message])) {
            return;
        }

        self::$logged[$app] = $logged + [$message => true];
        Log::warning('[db-portable] '.$message.' Skipped.');
    }
}
