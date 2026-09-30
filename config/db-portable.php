<?php

return [

    /*
    | Throw instead of logging a warning when a database cannot express a
    | query or schema feature, a mirror table lacks a mirrored column, or a
    | mirror is not configured.
    */
    'strict' => env('DB_PORTABLE_STRICT', false),

    /*
    | whereFullText() and $table->fullText() on SQLite, with FTS5 tables kept up
    | to date by triggers: Laravel's SQLite grammars are replaced by subclasses
    | on each SQLite connection (the driver stays Laravel's).
    */
    'sqlite_fulltext' => true,

    /*
    | Mirrors: owner models name them with #[MirroredAs('analytics', ...)];
    | here, each mirror's database and queue in this environment, and what is
    | turned off. Off: no job is queued, and jobs already queued do nothing
    | (mirrorable() catches up once turned on again).
    */
    'mirrors' => [

        // Every mirror.
        'enabled' => env('DB_PORTABLE_MIRRORS_ENABLED', true),

        // Each mirror, by its name in #[MirroredAs] ("enabled" and "models" are settings, not names):
        //
        // 'analytics' => [
        //     'enabled' => true,
        //     'connection' => env('DB_PORTABLE_ANALYTICS_CONNECTION', 'matrixone'),
        //     'queue_connection' => null,        // default: the default queue connection
        //     'queue' => 'mirrors',              // default: the default queue
        //     'versions' => 'latest',            // or all: every version, XTDB history mirrors
        //     'erase_on_force_delete' => false,  // history mirrors: a force delete erases the history
        //     'encrypt' => false,                // encrypt the queued jobs
        // ],

        // Owner models turned off, in all their mirrors: App\Models\Order::class => false,
        'models' => [],

    ],

];
