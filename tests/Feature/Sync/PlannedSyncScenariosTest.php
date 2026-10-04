<?php

namespace Tests\Feature\Sync;

use Tests\TestCase;

/**
 * Scenarios we would cover next. They need a real queue worker process or a
 * feature that is not built yet, so they are placeholders for now.
 */
class PlannedSyncScenariosTest extends TestCase
{
    public function test_a_job_exceeding_its_timeout_is_failed_by_a_real_worker()
    {
        $this->markTestIncomplete('Run `queue:work` in a subprocess against a GitHub fake that hangs and assert the target ends up failed with the timeout message (the timeout relies on pcntl signals, which a synchronous test cannot trigger).');
    }

    public function test_a_second_worker_releases_an_overlapping_job_for_the_same_target()
    {
        $this->markTestIncomplete('Hold the WithoutOverlapping lock for a target, process a second job for it and assert it is released back onto the queue instead of running.');
    }

    public function test_a_large_organization_is_synced_within_the_job_timeout()
    {
        $this->markTestIncomplete('Fake 60+ pages (6,000 repositories) and assert the sync finishes well below the 300s timeout and keeps memory flat thanks to page-by-page upserts.');
    }

    public function test_repositories_missing_longer_than_the_retention_period_are_pruned()
    {
        $this->markTestIncomplete('Planned: a scheduled prune command deletes repositories whose missing_at is older than the retention period, and keeps recently missing ones.');
    }
}
