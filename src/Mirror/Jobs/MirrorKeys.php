<?php

namespace DbPortable\Mirror\Jobs;

use DbPortable\Mirror\MirrorSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Brings owner rows to a mirror: writes their state as it is when the job
 * runs (or deletes them when they are gone), or removes them from the mirror.
 *
 * Queued after the owner's transaction commits. Unique until it runs: while a
 * job for a row is waiting, later changes of the row need no other job.
 */
final class MirrorKeys implements ShouldBeUniqueUntilProcessing, ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    /** Write the rows' current state; delete those gone from the owner or not mirrored. */
    public const SYNC = 'sync';

    /** Delete the rows from the mirror, whatever the owner holds. */
    public const REMOVE = 'remove';

    /** Delete the rows and their history (history mirrors). */
    public const ERASE = 'erase';

    /** Seconds a waiting job keeps its unique lock, should the job be lost. */
    public int $uniqueFor = 3600;

    public bool $shouldBeEncrypted = false;

    /**
     * @param  class-string<Model>  $owner
     * @param  list<int|string>  $keys
     */
    public function __construct(
        public string $mirror,
        public string $owner,
        public array $keys,
        public string $action = self::SYNC,
    ) {}

    public function uniqueId(): string
    {
        return $this->mirror.'|'.$this->owner.'|'.$this->action.'|'.md5((string) json_encode($this->keys));
    }

    public function handle(MirrorSync $sync): void
    {
        if (count($this->keys) !== 1) {
            $sync->sync($this->mirror, $this->owner, $this->keys, $this->action);

            return;
        }

        // Two jobs of one row never run at once: the one that read an older state could write last.
        Cache::lock('db-portable-mirror:'.md5($this->mirror.'|'.$this->owner.'|'.$this->keys[0]), 120)
            ->block(60, fn () => $sync->sync($this->mirror, $this->owner, $this->keys, $this->action));
    }

    public function displayName(): string
    {
        return self::class." ({$this->owner} → {$this->mirror})";
    }
}
