<?php

namespace Tests\Feature\Monitoring;

use App\Services\QueueMonitor;
use App\Services\SyncSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function beat(): void
    {
        app(SyncSchedule::class)->beat();
        app(QueueMonitor::class)->beat(now()->subSecond()->getTimestamp());
    }

    public function test_it_is_healthy_while_the_scheduler_and_workers_run()
    {
        $this->beat();

        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.scheduler.ok', true)
            ->assertJsonPath('checks.queue.worker', 'running')
            ->assertJsonPath('checks.queue.delay_seconds', 1)
            ->assertJsonPath('checks.failed_jobs.last_24h', 0);
    }

    public function test_it_is_unhealthy_when_the_scheduler_or_the_workers_stopped()
    {
        $this->beat();
        $this->travel(5)->minutes();

        $this->get('/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy')
            ->assertJsonPath('checks.scheduler.ok', false)
            ->assertJsonPath('checks.queue.ok', false)
            ->assertJsonPath('checks.queue.worker', 'stopped');
    }

    public function test_failed_jobs_are_reported_but_keep_it_healthy()
    {
        $this->beat();
        DB::table('failed_jobs')->insert(['uuid' => fake()->uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'Boom', 'failed_at' => now()]);

        $this->get('/health')->assertOk()->assertJsonPath('checks.failed_jobs.last_24h', 1);
    }

    public function test_it_is_public_and_starts_no_session()
    {
        $this->beat();

        $response = $this->get('/health')->assertOk();

        $this->assertSame([], $response->headers->getCookies());
    }
}
