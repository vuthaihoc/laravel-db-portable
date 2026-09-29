<?php

namespace LaravelXtdb;

use Illuminate\Database\PostgresConnection;

/**
 * Stands in for vuthaihoc/laravel-xtdb2's connection, which is not a development
 * dependency (XTDB 2.2 is not released yet).
 */
class XtdbConnection extends PostgresConnection {}
