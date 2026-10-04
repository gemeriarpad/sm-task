<?php

namespace App\Services;

use App\Enums\QueueWorkerStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Whether the queue workers are alive, how far behind the queue is and how many
 * jobs failed recently. Feeds the dashboard and the /health endpoint.
 */
class QueueMonitor
{
    private const HEARTBEAT_KEY = 'queue:heartbeat';

    /**
     * The heartbeat job is queued every minute, so an older one means no worker
     * picked it up.
     */
    private const HEARTBEAT_TTL = 180;

    /**
     * Record that a worker ran the heartbeat job queued at $dispatchedAt.
     */
    public function beat(int $dispatchedAt): void
    {
        $now = now()->getTimestamp();

        Cache::forever(self::HEARTBEAT_KEY, ['processed_at' => $now, 'delay' => max(0, $now - $dispatchedAt)]);
    }

    /**
     * When a worker last ran the heartbeat job, or null if none ever has.
     */
    public function lastHeartbeatAt(): ?Carbon
    {
        $heartbeat = $this->heartbeat();

        return $heartbeat ? Date::createFromTimestamp($heartbeat['processed_at']) : null;
    }

    /**
     * Seconds the last heartbeat job waited in the queue before a worker ran it.
     */
    public function delaySeconds(): ?int
    {
        return $this->heartbeat()['delay'] ?? null;
    }

    public function workerStatus(): QueueWorkerStatus
    {
        if ($this->lastHeartbeatAt()?->gt(now()->subSeconds(self::HEARTBEAT_TTL))) {
            return QueueWorkerStatus::Running;
        }

        // A single worker busy with a long sync can't run the heartbeat meanwhile.
        return ($this->backlog()['busy'] ?? 0) > 0 ? QueueWorkerStatus::Busy : QueueWorkerStatus::Stopped;
    }

    /**
     * Jobs waiting (ready to run now), when the oldest of them became ready, and jobs
     * a worker is running. The details need the database queue driver; other drivers
     * only report the queue size.
     *
     * @return array{pending: int, oldest_pending_at: ?Carbon, busy: ?int}
     */
    public function backlog(): array
    {
        $name = config('queue.default');
        $config = config("queue.connections.{$name}");

        if (($config['driver'] ?? null) !== 'database') {
            return ['pending' => Queue::connection($name)->size(), 'oldest_pending_at' => null, 'busy' => null];
        }

        $jobs = fn (): Builder => DB::connection($config['connection'] ?? null)->table($config['table'])->where('queue', $config['queue']);
        $now = now()->getTimestamp();
        $oldest = $jobs()->whereNull('reserved_at')->where('available_at', '<=', $now)->min('available_at');

        return [
            'pending' => $jobs()->whereNull('reserved_at')->where('available_at', '<=', $now)->count(),
            'oldest_pending_at' => $oldest !== null ? Date::createFromTimestamp((int) $oldest) : null,
            // Reservations older than retry_after are stale (the worker died); the
            // queue hands those jobs out again.
            'busy' => $jobs()->whereNotNull('reserved_at')->where('reserved_at', '>', $now - ($config['retry_after'] ?? 90))->count(),
        ];
    }

    /**
     * Jobs that failed for good in the last 24 hours.
     */
    public function recentFailures(): int
    {
        return DB::connection(config('queue.failed.database'))
            ->table(config('queue.failed.table'))
            ->where('failed_at', '>=', now()->subDay())
            ->count();
    }

    /**
     * @return array{worker: string, last_heartbeat_at: ?string, delay_seconds: ?int, pending_jobs: int, oldest_pending_job_at: ?string, busy_jobs: ?int, failed_jobs_24h: int}
     */
    public function toArray(): array
    {
        $backlog = $this->backlog();

        return [
            'worker' => $this->workerStatus()->value,
            'last_heartbeat_at' => $this->lastHeartbeatAt()?->toIso8601String(),
            'delay_seconds' => $this->delaySeconds(),
            'pending_jobs' => $backlog['pending'],
            'oldest_pending_job_at' => $backlog['oldest_pending_at']?->toIso8601String(),
            'busy_jobs' => $backlog['busy'],
            'failed_jobs_24h' => $this->recentFailures(),
        ];
    }

    /**
     * @return array{processed_at: int, delay: int}|null
     */
    private function heartbeat(): ?array
    {
        $heartbeat = Cache::get(self::HEARTBEAT_KEY);

        return is_array($heartbeat) ? $heartbeat : null;
    }
}
