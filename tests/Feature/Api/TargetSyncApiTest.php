<?php

namespace Tests\Feature\Api;

use App\Enums\SyncRunStatus;
use App\Enums\TargetStatus;
use App\Jobs\SyncTargetRepositories;
use App\Models\SyncRun;
use App\Models\Target;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TargetSyncApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Sanctum::actingAs(User::factory()->create(), ['sync']);
    }

    public function test_a_sync_can_be_triggered()
    {
        $target = Target::factory()->synced()->create();

        $this->postJson("/api/v1/targets/{$target->id}/sync")
            ->assertAccepted()
            ->assertHeader('Location', route('api.v1.targets.sync.show', $target))
            ->assertHeader('Retry-After', '5')
            ->assertJsonPath('meta.queued', true)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.in_progress', true);

        Queue::assertPushed(SyncTargetRepositories::class, fn (SyncTargetRepositories $job) => $job->target->is($target));
    }

    public function test_triggering_again_while_a_sync_is_in_progress_is_idempotent()
    {
        $target = Target::factory()->synced()->create();

        $this->postJson("/api/v1/targets/{$target->id}/sync")->assertAccepted()->assertJsonPath('meta.queued', true);
        $this->postJson("/api/v1/targets/{$target->id}/sync")->assertAccepted()->assertJsonPath('meta.queued', false);

        Queue::assertPushed(SyncTargetRepositories::class, 1);
    }

    public function test_a_deactivated_target_cannot_be_synced()
    {
        $target = Target::factory()->inactive()->create(['name' => 'laravel']);

        $this->postJson("/api/v1/targets/{$target->id}/sync")
            ->assertConflict()
            ->assertExactJson(['message' => 'The target "laravel" is deactivated. Reactivate it to sync it again.']);

        Queue::assertNothingPushed();
    }

    public function test_the_sync_status_can_be_polled()
    {
        $target = Target::factory()->create(['status' => TargetStatus::Syncing]);
        $run = SyncRun::factory()->for($target)->running()->create();

        $this->getJson("/api/v1/targets/{$target->id}/sync")
            ->assertOk()
            ->assertHeader('Retry-After', '5')
            ->assertJsonPath('data.in_progress', true)
            ->assertJsonPath('data.latest_run.id', $run->id)
            ->assertJsonPath('data.latest_run.status', 'running');

        $target->update(['status' => TargetStatus::Failed, 'last_error' => 'GitHub API rate limit exceeded.']);
        $run->finish(SyncRunStatus::Failed, ['error' => 'GitHub API rate limit exceeded.']);

        $this->getJson("/api/v1/targets/{$target->id}/sync")
            ->assertOk()
            ->assertHeaderMissing('Retry-After')
            ->assertJsonPath('data.in_progress', false)
            ->assertJsonPath('data.last_error', 'GitHub API rate limit exceeded.')
            ->assertJsonPath('data.latest_run.error', 'GitHub API rate limit exceeded.');
    }

    public function test_a_target_that_never_synced_has_no_latest_run()
    {
        $target = Target::factory()->inactive()->create();

        $this->getJson("/api/v1/targets/{$target->id}/sync")->assertOk()->assertJsonPath('data.latest_run', null);
    }

    public function test_every_active_target_can_be_synced_at_once()
    {
        Target::factory()->count(2)->synced()->create();
        Target::factory()->create(['status' => TargetStatus::Syncing]);
        Target::factory()->inactive()->create();

        $this->postJson('/api/v1/sync')
            ->assertAccepted()
            ->assertExactJson(['data' => ['queued' => 2, 'skipped' => 1]]);

        Queue::assertPushed(SyncTargetRepositories::class, 2);
    }
}
