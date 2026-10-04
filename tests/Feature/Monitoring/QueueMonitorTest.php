<?php

namespace Tests\Feature\Monitoring;

use App\Enums\QueueWorkerStatus;
use App\Jobs\QueueHeartbeat;
use App\Models\User;
use App\Services\QueueMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QueueMonitorTest extends TestCase
{
    use RefreshDatabase;

    private function monitor(): QueueMonitor
    {
        return app(QueueMonitor::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function job(array $attributes = []): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
            ...$attributes,
        ]);
    }

    public function test_the_scheduler_queues_a_heartbeat_that_a_worker_records()
    {
        Queue::fake();
        $this->travelTo('2026-10-04 12:07:00');

        $this->artisan('schedule:run')->assertSuccessful();

        Queue::assertPushed(QueueHeartbeat::class, fn ($job) => $job->dispatchedAt === now()->getTimestamp());
    }

    public function test_a_recent_heartbeat_means_workers_run_and_measures_the_delay()
    {
        $this->travelTo('2026-10-04 12:00:00');
        $this->monitor()->beat(now()->subSeconds(4)->getTimestamp());

        $this->assertSame(QueueWorkerStatus::Running, $this->monitor()->workerStatus());
        $this->assertSame(4, $this->monitor()->delaySeconds());

        $this->travel(4)->minutes();
        $this->assertSame(QueueWorkerStatus::Stopped, $this->monitor()->workerStatus());
    }

    public function test_a_worker_busy_with_a_long_job_is_not_reported_as_stopped()
    {
        config(['queue.default' => 'database']);
        $this->monitor()->beat(now()->getTimestamp());
        $this->travel(10)->minutes();

        // A sync reserved 5 minutes ago: within retry_after, so a worker runs it.
        $this->job(['reserved_at' => now()->subMinutes(5)->getTimestamp(), 'attempts' => 1]);
        $this->assertSame(QueueWorkerStatus::Busy, $this->monitor()->workerStatus());

        // A reservation older than retry_after belongs to a worker that died.
        DB::table('jobs')->update(['reserved_at' => now()->subHour()->getTimestamp()]);
        $this->assertSame(QueueWorkerStatus::Stopped, $this->monitor()->workerStatus());
    }

    public function test_the_backlog_counts_jobs_ready_to_run()
    {
        config(['queue.default' => 'database']);
        $this->job(['available_at' => now()->subMinutes(3)->getTimestamp()]);
        $this->job();
        // Released until later (e.g. a rate limit wait): not waiting yet.
        $this->job(['available_at' => now()->addMinutes(30)->getTimestamp()]);

        $backlog = $this->monitor()->backlog();

        $this->assertSame(2, $backlog['pending']);
        $this->assertTrue($backlog['oldest_pending_at']->eq(now()->subMinutes(3)->startOfSecond()));
        $this->assertSame(0, $backlog['busy']);
    }

    public function test_the_dashboard_shows_the_queue_state()
    {
        $this->actingAs(User::factory()->create());
        $this->monitor()->beat(now()->getTimestamp());

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('queue.worker', 'running')
            ->where('queue.failed_jobs_24h', 0)
            ->has('queue.pending_jobs'));
    }

    public function test_recent_failures_count_the_last_24_hours()
    {
        foreach ([now()->subHours(2), now()->subDays(2)] as $failedAt) {
            DB::table('failed_jobs')->insert(['uuid' => fake()->uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'Boom', 'failed_at' => $failedAt]);
        }

        $this->assertSame(1, $this->monitor()->recentFailures());
    }
}
