<?php

namespace DbPortable\Contracts;

use DateTimeInterface;

/**
 * A query builder that reads past or slightly stale data (CockroachDB, MatrixOne).
 * db-portable's macros of the same names cover the other databases.
 */
interface HistoricalReads
{
    /**
     * Accept data a few seconds old when the database can serve it with less contention
     * (a follower read on CockroachDB); a no-op where reads do not contend with writes.
     *
     * @return $this
     */
    public function readStale(): static;

    /**
     * Read the data as it was at a point in the past: a DateTimeInterface, a timestamp,
     * or a relative duration such as "-10s", "-5m", "-1h".
     *
     * @return $this
     */
    public function asOfTime(DateTimeInterface|string $time): static;

    /**
     * Back to current data: undo readStale() and asOfTime().
     *
     * @return $this
     */
    public function readCurrent(): static;
}
