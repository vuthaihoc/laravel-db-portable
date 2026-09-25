<?php

namespace DbPortable\Schema;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * What to do when a database cannot express a schema feature: log a warning
 * and skip it, or throw when config('db-portable.strict') is true.
 */
final class Unsupported
{
    public static function skip(string $message): void
    {
        if (function_exists('config') && config('db-portable.strict', false)) {
            throw new RuntimeException('[db-portable] '.$message);
        }

        if (function_exists('app') && app()->bound('log')) {
            Log::warning('[db-portable] '.$message.' Skipped.');
        }
    }
}
