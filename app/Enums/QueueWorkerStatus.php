<?php

namespace App\Enums;

/**
 * Whether queue workers are processing jobs, see App\Services\QueueMonitor.
 */
enum QueueWorkerStatus: string
{
    /** A worker processed the heartbeat job recently. */
    case Running = 'running';
    /** No recent heartbeat, but a worker is busy with a job (e.g. a long sync). */
    case Busy = 'busy';
    /** No recent heartbeat and no job in progress: no worker is running. */
    case Stopped = 'stopped';
}
