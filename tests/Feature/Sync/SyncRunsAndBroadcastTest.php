<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncRunStatus;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Events\TargetSyncUpdated;
use App\Jobs\SyncTargetRepositories;
use App\Models\SyncRun;
use App\Models\Target;
use App\Services\GitHub\GitHubException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SyncRunsAndBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Target $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->target = Target::factory()->create(['name' => 'laravel', 'type' => TargetType::Organization, 'status' => TargetStatus::Queued]);
    }

    private function handle(SyncTargetRepositories $job): void
    {
        app()->call([$job, 'handle']);
    }

    public function test_a_successful_attempt_is_recorded()
    {
        Http::fake(['api.github.com/orgs/laravel/repos*' => Http::response([GitHubClientTest::repo(1), GitHubClientTest::repo(2)])]);

        $this->handle((new SyncTargetRepositories($this->target))->withFakeQueueInteractions());

        $run = SyncRun::sole();
        $this->assertSame(SyncRunStatus::Succeeded, $run->status);
        $this->assertSame(1, $run->attempt);
        $this->assertSame(2, $run->repositories_count);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->duration_ms);
        $this->assertNull($run->error);
    }

    public function test_a_retried_attempt_is_recorded_as_failed_with_will_retry()
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad Gateway'], 502)]);

        try {
            $this->handle((new SyncTargetRepositories($this->target))->withFakeQueueInteractions());
        } catch (GitHubException) {
            //
        }

        $run = SyncRun::sole();
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertTrue($run->will_retry);
        $this->assertSame('GitHub is having problems right now (HTTP 502).', $run->error);
    }

    public function test_a_permanent_failure_is_recorded_without_retry()
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->handle((new SyncTargetRepositories($this->target))->withFakeQueueInteractions());

        $run = SyncRun::sole();
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertFalse($run->will_retry);
        $this->assertSame('Organization "laravel" was not found on GitHub.', $run->error);
    }

    public function test_a_timed_out_attempt_left_running_is_closed_by_failed()
    {
        $run = SyncRun::factory()->running()->for($this->target)->create(['started_at' => now()->subMinutes(5)]);

        (new SyncTargetRepositories($this->target))->failed(new TimeoutExceededException('timed out'));

        $run->refresh();
        $this->assertSame(SyncRunStatus::Failed, $run->status);
        $this->assertSame('The synchronization took longer than 1800 seconds and was stopped.', $run->error);
        $this->assertNotNull($run->finished_at);
    }

    public function test_sync_state_changes_are_broadcast()
    {
        Event::fake([TargetSyncUpdated::class]);

        $this->target->update(['status' => TargetStatus::Syncing]);
        $this->target->update(['last_error' => 'Boom']);

        Event::assertDispatchedTimes(TargetSyncUpdated::class, 2);
        Event::assertDispatched(TargetSyncUpdated::class, fn (TargetSyncUpdated $event) => $event->target->is($this->target)
            && $event->broadcastAs() === 'target.sync.updated'
            && $event->broadcastOn()[0]->name === 'private-sync'
            && $event->broadcastWith() === ['target_id' => $this->target->id, 'status' => 'syncing']);
    }

    public function test_unrelated_changes_are_not_broadcast()
    {
        Event::fake([TargetSyncUpdated::class]);

        $this->target->update(['avatar_url' => 'https://avatars.githubusercontent.com/u/1']);

        Event::assertNotDispatched(TargetSyncUpdated::class);
    }

    public function test_a_failing_broadcaster_never_breaks_a_sync()
    {
        Event::listen(TargetSyncUpdated::class, fn () => throw new RuntimeException('Reverb is not running'));

        $this->target->update(['status' => TargetStatus::Synced]);

        $this->assertSame(TargetStatus::Synced, $this->target->fresh()->status);
    }
}
