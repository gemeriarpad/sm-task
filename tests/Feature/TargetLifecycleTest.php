<?php

namespace Tests\Feature;

use App\Enums\CloneMode;
use App\Enums\CloneStatus;
use App\Enums\TargetStatus;
use App\Jobs\SyncTargetRepositories;
use App\Models\Repository;
use App\Models\SyncRun;
use App\Models\Target;
use App\Models\User;
use App\Services\Git\RepositoryCloner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TargetLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    private Target $target;

    private Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->actingAs(User::factory()->create());

        $this->base = storage_path('framework/testing/clones-lifecycle');
        File::deleteDirectory($this->base);
        config(['services.github.clone_path' => $this->base]);
        $this->app->forgetInstance(RepositoryCloner::class);

        $this->target = Target::factory()->synced()->failed('Old error')->create(['name' => 'laravel', 'github_repositories_count' => 1]);
        $this->repository = Repository::factory()->for($this->target)->create([
            'clone_status' => CloneStatus::Cloned,
            'cloned_commit' => '0123456789abcdef0123456789abcdef01234567',
            'cloned_branch' => 'main',
            'cloned_at' => now(),
        ]);
        File::ensureDirectoryExists($this->clonePath().'/.git');
        SyncRun::factory()->for($this->target)->create();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);

        parent::tearDown();
    }

    private function clonePath(): string
    {
        return "{$this->base}/{$this->repository->github_id}";
    }

    /**
     * @param  array<string, bool>  $keep
     */
    private function deactivate(array $keep = []): void
    {
        $this->post("/targets/{$this->target->id}/deactivate", [
            'keep_repositories' => true,
            'keep_clones' => true,
            'keep_history' => true,
            ...$keep,
        ])->assertRedirect();
    }

    // --- Deactivate ----------------------------------------------------------

    public function test_deactivating_keeps_everything_by_default()
    {
        $this->deactivate();

        $this->target->refresh();
        $this->assertSame(TargetStatus::Inactive, $this->target->status);
        $this->assertNotNull($this->target->deactivated_at);
        $this->assertSame('Old error', $this->target->last_error);
        $this->assertSame(CloneStatus::Cloned, $this->repository->fresh()->clone_status);
        $this->assertDirectoryExists($this->clonePath());
        $this->assertSame(1, SyncRun::count());
    }

    public function test_deactivating_can_drop_the_clones_only()
    {
        $this->deactivate(['keep_clones' => false]);

        $this->repository->refresh();
        $this->assertDirectoryDoesNotExist($this->clonePath());
        $this->assertNull($this->repository->clone_status);
        $this->assertNull($this->repository->cloned_commit);
        $this->assertNull($this->repository->cloned_at);
        $this->assertSame(1, SyncRun::count());
    }

    public function test_dropping_the_repositories_drops_their_clones_too()
    {
        $this->deactivate(['keep_repositories' => false]);

        $this->assertModelMissing($this->repository);
        $this->assertDirectoryDoesNotExist($this->clonePath());
        $this->assertNull($this->target->fresh()->github_repositories_count);
        $this->assertSame(1, SyncRun::count());
    }

    public function test_deactivating_can_drop_the_sync_history()
    {
        $this->deactivate(['keep_history' => false]);

        $this->assertSame(0, SyncRun::count());
        $this->assertNull($this->target->fresh()->last_error);
        $this->assertModelExists($this->repository);
    }

    public function test_the_deactivate_choices_are_required()
    {
        $this->post("/targets/{$this->target->id}/deactivate")
            ->assertSessionHasErrors(['keep_repositories', 'keep_clones', 'keep_history']);

        $this->assertSame(TargetStatus::Failed, $this->target->fresh()->status);
    }

    public function test_nothing_is_removed_while_a_sync_is_running()
    {
        $this->target->update(['status' => TargetStatus::Syncing]);
        $error = ['target' => 'A sync is running for this target. Wait for it to finish and try again.'];

        $this->post("/targets/{$this->target->id}/deactivate", ['keep_repositories' => false, 'keep_clones' => false, 'keep_history' => false])
            ->assertSessionHasErrors($error);
        $this->delete("/targets/{$this->target->id}", ['confirm_name' => 'laravel'])->assertSessionHasErrors($error);
        $this->delete("/targets/{$this->target->id}/clones")->assertSessionHasErrors($error);
        $this->delete("/repositories/{$this->repository->id}/clone")->assertSessionHasErrors(['clone' => $error['target']]);

        $this->assertSame(TargetStatus::Syncing, $this->target->fresh()->status);
        $this->assertModelExists($this->repository);
        $this->assertDirectoryExists($this->clonePath());
    }

    public function test_inactive_targets_are_never_synced()
    {
        $this->deactivate();

        $this->post("/targets/{$this->target->id}/sync")->assertRedirect();
        $this->post('/targets/sync')->assertRedirect();
        $this->artisan('targets:sync')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(TargetStatus::Inactive, $this->target->fresh()->status);
    }

    public function test_reactivating_queues_a_sync()
    {
        $this->deactivate(['keep_repositories' => false]);

        $this->post("/targets/{$this->target->id}/reactivate")->assertRedirect();

        $this->target->refresh();
        $this->assertSame(TargetStatus::Queued, $this->target->status);
        $this->assertNull($this->target->deactivated_at);
        Queue::assertPushed(SyncTargetRepositories::class, 1);
    }

    // --- Delete --------------------------------------------------------------

    public function test_deleting_requires_typing_the_target_name()
    {
        $this->delete("/targets/{$this->target->id}")->assertSessionHasErrors(['confirm_name' => 'Type the target name to confirm.']);
        $this->delete("/targets/{$this->target->id}", ['confirm_name' => 'vuejs'])->assertSessionHasErrors(['confirm_name' => 'The name does not match.']);

        $this->assertModelExists($this->target);
    }

    public function test_deleting_removes_the_target_with_all_of_its_data()
    {
        $this->delete("/targets/{$this->target->id}", ['confirm_name' => 'laravel'])
            ->assertRedirect(route('targets.index'));

        $this->assertModelMissing($this->target);
        $this->assertModelMissing($this->repository);
        $this->assertSame(0, SyncRun::count());
        $this->assertDirectoryDoesNotExist($this->clonePath());
    }

    // --- Clones --------------------------------------------------------------

    public function test_all_clones_of_a_target_can_be_deleted()
    {
        $this->delete("/targets/{$this->target->id}/clones")->assertRedirect();

        $this->assertDirectoryDoesNotExist($this->clonePath());
        $this->assertNull($this->repository->fresh()->clone_status);
        $this->assertNull($this->repository->fresh()->cloned_at);
        $this->assertModelExists($this->repository);
    }

    public function test_one_repository_clone_can_be_deleted_and_keeps_its_setting()
    {
        $this->repository->update(['clone_mode' => CloneMode::Always]);
        $other = Repository::factory()->for($this->target)->create(['clone_status' => CloneStatus::Cloned, 'cloned_at' => now()]);
        File::ensureDirectoryExists("{$this->base}/{$other->github_id}/.git");

        $this->delete("/repositories/{$this->repository->id}/clone")->assertRedirect();

        $this->assertDirectoryDoesNotExist($this->clonePath());
        $this->repository->refresh();
        $this->assertNull($this->repository->clone_status);
        $this->assertNull($this->repository->cloned_commit);
        $this->assertNull($this->repository->cloned_at);
        // Still set to clone: the next sync clones it again.
        $this->assertSame(CloneMode::Always, $this->repository->clone_mode);
        $this->assertDirectoryExists("{$this->base}/{$other->github_id}");
        $this->assertSame(CloneStatus::Cloned, $other->fresh()->clone_status);
    }

    public function test_the_target_page_lists_what_deleting_removes()
    {
        Repository::factory()->for($this->target)->missing()->create(['cloned_at' => now()]);

        $this->get("/targets/{$this->target->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('target.stored_count', 2)
                ->where('target.local_clones_count', 2)
                ->where('target.missing_clones_count', 1)
                ->where('target.runs_count', 1));
    }
}
