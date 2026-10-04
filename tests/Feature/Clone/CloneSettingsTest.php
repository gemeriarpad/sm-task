<?php

namespace Tests\Feature\Clone;

use App\Enums\CloneMode;
use App\Enums\CloneStatus;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Jobs\SyncTargetRepositories;
use App\Models\Repository;
use App\Models\Target;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CloneSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Target $target;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->actingAs(User::factory()->create());
        $this->target = Target::factory()->synced()->create(['name' => 'laravel', 'type' => TargetType::Organization]);
    }

    /**
     * @return array<string, array{0: CloneMode, 1: bool, 2: bool}>
     */
    public static function cloneSettings(): array
    {
        return [
            'inherit, target clones' => [CloneMode::Inherit, true, true],
            'inherit, target does not clone' => [CloneMode::Inherit, false, false],
            'always overrides the target' => [CloneMode::Always, false, true],
            'never overrides the target' => [CloneMode::Never, true, false],
        ];
    }

    #[DataProvider('cloneSettings')]
    public function test_the_repository_mode_wins_over_the_target_setting(CloneMode $mode, bool $targetClones, bool $expected)
    {
        $this->target->update(['clone_repositories' => $targetClones]);
        $repository = Repository::factory()->for($this->target)->create(['clone_mode' => $mode]);

        $this->assertSame($expected, $repository->shouldClone());
    }

    public function test_requesting_a_clone_marks_it_queued_and_queues_a_sync_of_the_target()
    {
        $repository = Repository::factory()->for($this->target)->create(['clone_mode' => CloneMode::Always]);

        $this->assertTrue($repository->requestClone());

        $this->assertSame(CloneStatus::Queued, $repository->fresh()->clone_status);
        $this->assertSame(TargetStatus::Queued, $this->target->fresh()->status);
        Queue::assertPushed(SyncTargetRepositories::class, fn (SyncTargetRepositories $job) => $job->target->is($this->target));
    }

    public function test_only_repositories_that_should_be_cloned_can_be_requested()
    {
        $this->assertFalse(Repository::factory()->for($this->target)->create()->requestClone());
        $this->assertFalse(Repository::factory()->for($this->target)->missing()->create(['clone_mode' => CloneMode::Always])->requestClone());
        $this->assertFalse(Repository::factory()->for($this->target)->create(['clone_mode' => CloneMode::Always, 'excluded_at' => now()])->requestClone());
        $this->assertFalse(Repository::factory()->for(Target::factory()->inactive())->create(['clone_mode' => CloneMode::Always])->requestClone());

        Queue::assertNothingPushed();
    }

    public function test_a_repository_being_cloned_keeps_its_status_when_requested_again()
    {
        $repository = Repository::factory()->for($this->target)->create(['clone_mode' => CloneMode::Always, 'clone_status' => CloneStatus::Cloning]);

        $this->assertTrue($repository->requestClone());

        $this->assertSame(CloneStatus::Cloning, $repository->fresh()->clone_status);
    }

    public function test_a_repository_can_be_set_to_always_clone()
    {
        $repository = Repository::factory()->for($this->target)->create();

        $this->patch("/repositories/{$repository->id}/clone", ['mode' => 'always'])->assertRedirect();

        $this->assertSame(CloneMode::Always, $repository->fresh()->clone_mode);
        $this->assertSame(CloneStatus::Queued, $repository->fresh()->clone_status);
        Queue::assertPushed(SyncTargetRepositories::class, 1);
    }

    public function test_turning_cloning_off_drops_a_pending_request()
    {
        $repository = Repository::factory()->for($this->target)->create(['clone_mode' => CloneMode::Always, 'clone_status' => CloneStatus::Queued]);

        $this->patch("/repositories/{$repository->id}/clone", ['mode' => 'never'])->assertRedirect();

        $this->assertNull($repository->fresh()->clone_status);
        Queue::assertNothingPushed();
    }

    public function test_an_invalid_clone_mode_is_rejected()
    {
        $repository = Repository::factory()->for($this->target)->create();

        $this->patch("/repositories/{$repository->id}/clone", ['mode' => 'sometimes'])->assertSessionHasErrors('mode');
    }

    public function test_a_branch_can_be_chosen_and_reset_to_the_default()
    {
        $repository = Repository::factory()->for($this->target)->create(['clone_mode' => CloneMode::Always, 'default_branch' => 'main']);

        $this->patch("/repositories/{$repository->id}/clone", ['branch' => 'release/1.x'])->assertRedirect();

        $this->assertSame('release/1.x', $repository->fresh()->clone_branch);
        $this->assertSame('release/1.x', $repository->fresh()->cloneBranch());
        Queue::assertPushed(SyncTargetRepositories::class, 1);

        $this->patch("/repositories/{$repository->id}/clone", ['branch' => null])->assertRedirect();

        $this->assertNull($repository->fresh()->clone_branch);
        $this->assertSame('main', $repository->fresh()->cloneBranch());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidBranches(): array
    {
        return [
            'option injection' => ['--upload-pack=touch /tmp/pwned'],
            'parent reference' => ['feature/../main'],
            'whitespace' => ['my branch'],
            'lock file' => ['main.lock'],
            'trailing slash' => ['feature/'],
        ];
    }

    #[DataProvider('invalidBranches')]
    public function test_invalid_branch_names_are_rejected(string $branch)
    {
        $repository = Repository::factory()->for($this->target)->create();

        $this->patch("/repositories/{$repository->id}/clone", ['branch' => $branch])
            ->assertSessionHasErrors(['branch' => 'This is not a valid branch name.']);

        $this->assertNull($repository->fresh()->clone_branch);
    }

    public function test_update_now_only_requests_repositories_with_cloning_enabled()
    {
        $off = Repository::factory()->for($this->target)->create();
        $on = Repository::factory()->for($this->target)->create(['clone_mode' => CloneMode::Always]);

        $this->post("/repositories/{$off->id}/clone")->assertRedirect();
        Queue::assertNothingPushed();

        $this->post("/repositories/{$on->id}/clone")->assertRedirect();
        Queue::assertPushed(SyncTargetRepositories::class, 1);
        $this->assertSame(CloneStatus::Queued, $on->fresh()->clone_status);
    }

    public function test_the_branches_are_listed_from_github()
    {
        Process::fake([
            '*' => Process::result("aaa\trefs/heads/main\nbbb\trefs/heads/release/10.x\nccc\trefs/heads/release/9.x\nddd\trefs/heads/Feature\n"),
        ]);
        $repository = Repository::factory()->for($this->target)->create(['full_name' => 'laravel/framework', 'default_branch' => 'main']);

        $this->getJson("/repositories/{$repository->id}/branches")
            ->assertOk()
            ->assertExactJson(['branches' => ['Feature', 'main', 'release/9.x', 'release/10.x'], 'default' => 'main']);

        Process::assertRan(fn (PendingProcess $process) => $process->command === ['git', 'ls-remote', '--heads', 'https://github.com/laravel/framework.git']);
    }

    public function test_a_failing_branch_list_returns_a_readable_error()
    {
        Process::fake(['*' => Process::result(errorOutput: 'fatal: unable to access: Could not resolve host: github.com', exitCode: 128)]);
        $repository = Repository::factory()->for($this->target)->create(['full_name' => 'laravel/framework']);

        $this->getJson("/repositories/{$repository->id}/branches")
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Could not load the branches from GitHub. Try again later.']);
    }
}
