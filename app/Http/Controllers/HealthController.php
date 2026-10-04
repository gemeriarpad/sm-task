<?php

namespace App\Http\Controllers;

use App\Enums\QueueWorkerStatus;
use App\Services\QueueMonitor;
use App\Services\SyncSchedule;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GET /health for uptime monitors: 200 while the database, the scheduler and the
 * queue workers work, 503 otherwise. Public, like Laravel's /up, so it only returns
 * statuses, timestamps and counts, never error messages.
 */
class HealthController extends Controller
{
    public function __invoke(SyncSchedule $schedule, QueueMonitor $queue): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => ['ok' => DB::select('select 1') !== []]),
            'scheduler' => $this->check(fn () => [
                'ok' => $schedule->schedulerRunning(),
                'last_heartbeat_at' => $schedule->lastHeartbeatAt()?->toIso8601String(),
            ]),
            'queue' => $this->check(function () use ($queue) {
                $state = $queue->toArray();
                unset($state['failed_jobs_24h']);

                return ['ok' => $state['worker'] !== QueueWorkerStatus::Stopped->value, ...$state];
            }),
        ];

        $healthy = collect($checks)->every(fn (array $check) => $check['ok']);

        // Informational: a failure an hour ago shouldn't keep the monitor red all day.
        // Failed jobs are alerted on (the "alerts" log channel) and listed on the
        // Failed jobs page.
        $checks['failed_jobs'] = $this->check(fn () => ['last_24h' => $queue->recentFailures()]);

        return response()->json([
            'status' => $healthy ? 'ok' : 'unhealthy',
            'checks' => $checks,
            'checked_at' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    /**
     * Run a check; an exception means it failed (reported, but not shown publicly).
     *
     * @param  Closure(): array<string, mixed>  $check
     * @return array<string, mixed>
     */
    private function check(Closure $check): array
    {
        try {
            return $check();
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false];
        }
    }
}
