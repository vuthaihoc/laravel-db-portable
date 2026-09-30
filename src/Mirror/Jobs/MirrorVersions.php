<?php

namespace DbPortable\Mirror\Jobs;

use DbPortable\Mirror\MirrorSync;
use DbPortable\Mirror\XtdbWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * Brings versions of owner rows to a history mirror (versions "all"): each row as it
 * was committed, valid from its version time, or the end of its validity. Queued after
 * the owner's transaction commits; every job counts, so none is unique.
 *
 * @phpstan-import-type Version from XtdbWriter
 */
final class MirrorVersions implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public bool $shouldBeEncrypted = false;

    /**
     * @param  class-string<Model>  $owner
     * @param  list<Version>  $versions
     */
    public function __construct(
        public string $mirror,
        public string $owner,
        public array $versions,
    ) {}

    public function handle(MirrorSync $sync): void
    {
        $sync->applyVersions($this->mirror, $this->owner, $this->versions);
    }

    public function displayName(): string
    {
        return self::class." ({$this->owner} → {$this->mirror})";
    }
}
