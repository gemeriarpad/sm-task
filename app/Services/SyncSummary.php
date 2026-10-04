<?php

namespace App\Services;

/**
 * What the repository list step of a sync did.
 */
final readonly class SyncSummary
{
    public function __construct(
        /** Repositories GitHub returned within the target's scope. */
        public int $repositories = 0,
        /** API pages requested. */
        public int $pages = 0,
        /** Pages GitHub answered with 304 Not Modified (unchanged since the last sync). */
        public int $notModifiedPages = 0,
    ) {}
}
