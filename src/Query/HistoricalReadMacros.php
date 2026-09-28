<?php

namespace DbPortable\Query;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use DbPortable\Schema\Unsupported;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Historical and stale reads named by intent; the drivers compile them:
 * CockroachDB (vuthaihoc/cockroachdb-laravel) with AS OF SYSTEM TIME and
 * follower reads, MatrixOne (vuthaihoc/laravel-matrixone) with
 * {as of timestamp}. Other databases have no time travel.
 */
final class HistoricalReadMacros
{
    public static function register(): void
    {
        // Data a few seconds old is fine (dashboards, reports).
        Builder::macro('readStale', function () {
            /** @var Builder $this */
            if (method_exists($this, 'followerRead')) {
                return $this->followerRead();
            }

            // Elsewhere reads do not contend with writes that way (MatrixOne's
            // MVCC), or Laravel already reads from the "read" connection.
            return $this;
        });

        // Read the data as it was at a point in the past: asOfTime('-10s'), asOfTime(now()->subHour()).
        Builder::macro('asOfTime', function (DateTimeInterface|string $time) {
            /** @var Builder $this */
            if (method_exists($this, 'asOfSystemTime')) {
                return $this->asOfSystemTime($time);
            }

            if (method_exists($this, 'asOfTimestamp')) {
                /** @var Connection $connection */
                $connection = $this->getConnection();

                return $this->asOfTimestamp(HistoricalReadMacros::moment($time, $connection));
            }

            /** @var Connection $connection */
            $connection = $this->getConnection();
            Unsupported::skip("asOfTime() on {$connection->getDriverName()}: the database has no historical reads, so current data is read.");

            return $this;
        });

        // Back to current data.
        Builder::macro('readCurrent', function () {
            /** @var Builder $this */
            if (method_exists($this, 'withoutHistoricalRead')) {
                return $this->withoutHistoricalRead();
            }

            if (property_exists($this, 'timeTravel')) {
                $this->timeTravel = null;
            }

            return $this;
        });
    }

    /**
     * A point in time in the connection's session time zone, from a
     * DateTimeInterface or a relative duration such as "-10s", "-5m", "-1h".
     */
    public static function moment(DateTimeInterface|string $time, Connection $connection): string
    {
        $zone = new DateTimeZone((string) ($connection->getConfig('timezone') ?: 'UTC'));

        if ($time instanceof DateTimeInterface) {
            return CarbonImmutable::instance($time)->setTimezone($zone)->format('Y-m-d H:i:s.u');
        }

        if (preg_match('/^-(\d+)(s|m|h)$/', $time, $matches)) {
            $seconds = (int) $matches[1] * ['s' => 1, 'm' => 60, 'h' => 3600][$matches[2]];

            return CarbonImmutable::now($zone)->subSeconds($seconds)->format('Y-m-d H:i:s.u');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $time)) {
            return CarbonImmutable::parse($time)->setTimezone($zone)->format('Y-m-d H:i:s.u');
        }

        throw new InvalidArgumentException("Invalid time [{$time}]: use a DateTimeInterface, a timestamp or a duration such as '-10s'.");
    }
}
