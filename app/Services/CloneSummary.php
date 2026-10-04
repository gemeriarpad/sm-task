<?php

namespace App\Services;

/**
 * What the cloning step of a sync did.
 */
final readonly class CloneSummary
{
    public function __construct(
        /** Repositories cloned or updated. */
        public int $cloned = 0,
        /** Repositories whose clone or update failed. */
        public int $failed = 0,
        /** Repositories left queued for the next sync because the run ran out of time. */
        public int $deferred = 0,
    ) {}
}
