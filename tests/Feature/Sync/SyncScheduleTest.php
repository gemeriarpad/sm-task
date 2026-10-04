<?php

namespace Tests\Feature\Sync;

use App\Models\User;
use App\Services\SyncSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SyncScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_shows_when_the_next_scheduled_sync_runs()
    {
        $this->actingAs(User::factory()->create());
        config(['services.github.sync_schedule' => '*/15 * * * *']);
        $this->travelTo('2026-10-04 12:07:30');

        $this->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('schedule', [
                'expression' => '*/15 * * * *',
                'description' => 'Every 15 minutes',
                'next_run_at' => '2026-10-04T12:15:00+00:00',
                'scheduler_running' => false,
                'last_heartbeat_at' => null,
            ]));
    }

    public function test_clock_times_in_the_description_name_the_scheduler_timezone()
    {
        config(['services.github.sync_schedule' => '0 3 * * *']);

        $this->assertSame('Every day at 3:00am UTC', app(SyncSchedule::class)->description());
    }

    public function test_an_invalid_expression_has_no_next_run()
    {
        config(['services.github.sync_schedule' => 'not a cron']);
        $schedule = app(SyncSchedule::class);

        $this->assertNull($schedule->nextRunAt());
        $this->assertNull($schedule->description());
    }

    public function test_the_scheduler_heartbeat_tells_whether_the_scheduler_is_running()
    {
        Queue::fake();
        // Off the hour, so the hourly resync isn't due and only the heartbeat runs.
        $this->travelTo('2026-10-04 12:07:00');

        $this->artisan('schedule:run')->assertSuccessful();

        $schedule = app(SyncSchedule::class);
        $this->assertTrue($schedule->schedulerRunning());
        $this->assertEquals(now(), $schedule->lastHeartbeatAt());

        $this->travel(4)->minutes();
        $this->assertFalse($schedule->schedulerRunning());
    }
}
