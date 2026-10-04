<?php

namespace App\Jobs;

use App\Services\QueueMonitor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued by the scheduler every minute. A worker running it proves the workers are
 * alive, and the time it waited in the queue is the queue's current delay.
 */
class QueueHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * While no worker runs, at most one heartbeat waits in the queue (per this many
     * seconds), instead of one per minute piling up.
     */
    public int $uniqueFor = 600;

    public function __construct(public readonly int $dispatchedAt) {}

    public function handle(QueueMonitor $monitor): void
    {
        $monitor->beat($this->dispatchedAt);
    }
}
