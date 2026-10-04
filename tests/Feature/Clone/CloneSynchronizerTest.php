<?php

namespace Tests\Feature\Clone;

use App\Enums\CloneMode;
use App\Enums\CloneStatus;
use App\Models\Repository;
use App\Models\Target;
use App\Services\CloneSynchronizer;
use App\Services\Git\RepositoryCloner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class CloneSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    private const COMMIT = '0123456789abcdef0123456789abcdef01234567';

    private string $base;

    private Target $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = storage_path('framework/testing/clones-synchronizer');
        File::deleteDirectory($this->base);
        config(['services.github.clone_path' => $this->base]);
        $this->app->forgetInstance(RepositoryCloner::class);

        $this->target = Target::factory()->synced()->create(['name' => 'laravel', 'clone_repositories' => true]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);

        parent::tearDown();
    }

    /**
     * Fake git: every command succeeds and HEAD is self::COMMIT.
     */
    private function fakeGit(?callable $before = null): void
    {
        Process::fake(function (PendingProcess $process) use ($before) {
            if ($before) {
                $result = $before($process);

                if ($result !== null) {
                    return $result;
                }
            }

            return in_array('rev-parse', $process->command, true) ? Process::result(self::COMMIT."\n") : Process::result();
        });
    }

    /**
     * A repository with an up to date clone of its default branch on disk.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function cloned(array $attributes = []): Repository
    {
        $repository = Repository::factory()->for($this->target)->create([
            'default_branch' => 'main',
            'clone_status' => CloneStatus::Cloned,
            'cloned_commit' => self::COMMIT,
            'cloned_branch' => 'main',
            'cloned_at' => now(),
            'pushed_at' => now()->subDay(),
            ...$attributes,
        ]);
        File::ensureDirectoryExists("{$this->base}/{$repository->github_id}/.git");

        return $repository;
    }

    private function assertClonedTimes(string $fullName, int $times): void
    {
        Process::assertRanTimes(fn (PendingProcess $process) => in_array('clone', $process->command, true)
            && in_array("https://github.com/{$fullName}.git", $process->command, true), $times);
    }

    public function test_new_repositories_with_cloning_enabled_are_cloned()
    {
        $this->fakeGit();
        $wanted = Repository::factory()->for($this->target)->create(['full_name' => 'laravel/framework', 'default_branch' => 'main']);
        Repository::factory()->for($this->target)->create(['clone_mode' => CloneMode::Never]);
        Repository::factory()->for($this->target)->create(['excluded_at' => now()]);
        Repository::factory()->for($this->target)->missing()->create();

        $summary = app(CloneSynchronizer::class)->sync($this->target);

        $this->assertSame([1, 0, 0], [$summary->cloned, $summary->failed, $summary->deferred]);
        $wanted->refresh();
        $this->assertSame(CloneStatus::Cloned, $wanted->clone_status);
        $this->assertSame(self::COMMIT, $wanted->cloned_commit);
        $this->assertSame('main', $wanted->cloned_branch);
        $this->assertNotNull($wanted->cloned_at);
        $this->assertClonedTimes('laravel/framework', 1);
        $this->assertSame(1, Repository::where('clone_status', CloneStatus::Cloned)->count());
    }

    public function test_up_to_date_clones_are_left_alone()
    {
        Process::fake();
        $this->cloned();

        $summary = app(CloneSynchronizer::class)->sync($this->target);

        $this->assertSame(0, $summary->cloned);
        Process::assertNothingRan();
    }

    public function test_clones_of_repositories_pushed_to_since_are_updated()
    {
        $this->fakeGit();
        $repository = $this->cloned(['cloned_at' => now()->subHour(), 'pushed_at' => now()]);

        $summary = app(CloneSynchronizer::class)->sync($this->target);

        $this->assertSame(1, $summary->cloned);
        Process::assertRan(fn (PendingProcess $process) => $process->command === ['git', '-C', "{$this->base}/{$repository->github_id}", 'fetch', '--depth', '1', '--no-tags', 'origin', 'main']);
    }

    public function test_a_repository_set_to_another_branch_is_cloned_again()
    {
        $this->fakeGit();
        $repository = $this->cloned(['full_name' => 'laravel/framework', 'clone_branch' => '12.x']);

        app(CloneSynchronizer::class)->sync($this->target);

        Process::assertRan(fn (PendingProcess $process) => in_array('clone', $process->command, true) && in_array('12.x', $process->command, true));
        $this->assertSame('12.x', $repository->fresh()->cloned_branch);
    }

    public function test_a_failed_clone_is_recorded_and_the_others_continue()
    {
        $this->fakeGit(fn (PendingProcess $process) => in_array('https://github.com/laravel/broken.git', $process->command, true)
            ? Process::result(errorOutput: 'fatal: repository not found', exitCode: 128)
            : null);
        $broken = Repository::factory()->for($this->target)->create(['full_name' => 'laravel/broken']);
        $fine = Repository::factory()->for($this->target)->create(['full_name' => 'laravel/fine']);

        $summary = app(CloneSynchronizer::class)->sync($this->target);

        $this->assertSame([1, 1], [$summary->cloned, $summary->failed]);
        $this->assertSame(CloneStatus::Failed, $broken->fresh()->clone_status);
        // git's own output only goes to the log.
        $this->assertSame('The repository was not found on GitHub, or it is not public anymore.', $broken->fresh()->clone_error);
        $this->assertSame(CloneStatus::Cloned, $fine->fresh()->clone_status);
    }

    public function test_failed_clones_are_tried_again_by_the_next_sync()
    {
        $this->fakeGit();
        $repository = $this->cloned(['clone_status' => CloneStatus::Failed, 'clone_error' => 'Boom']);

        $summary = app(CloneSynchronizer::class)->sync($this->target);

        $this->assertSame(1, $summary->cloned);
        $this->assertSame(CloneStatus::Cloned, $repository->fresh()->clone_status);
        $this->assertNull($repository->fresh()->clone_error);
    }

    public function test_repositories_over_the_size_limit_are_skipped_once()
    {
        Process::fake();
        config(['services.github.clone_max_size_mb' => 1]);
        $repository = Repository::factory()->for($this->target)->create(['size_kb' => 3 * 1024]);

        app(CloneSynchronizer::class)->sync($this->target);

        $this->assertSame(CloneStatus::Skipped, $repository->fresh()->clone_status);
        $this->assertSame('Not cloned: GitHub reports 3 MB, the limit is 1 MB (GITHUB_CLONE_MAX_SIZE_MB).', $repository->fresh()->clone_error);

        $repository->update(['clone_error' => null]);
        app(CloneSynchronizer::class)->sync($this->target);

        $this->assertNull($repository->fresh()->clone_error);
        Process::assertNothingRan();
    }

    public function test_no_clone_starts_after_the_deadline()
    {
        Process::fake();
        Repository::factory()->for($this->target)->count(3)->create();

        $summary = app(CloneSynchronizer::class)->sync($this->target, deadline: now()->subSecond());

        $this->assertSame([0, 3], [$summary->cloned, $summary->deferred]);
        $this->assertSame(3, Repository::where('clone_status', CloneStatus::Queued)->count());
        Process::assertNothingRan();
    }

    public function test_requests_for_repositories_that_should_no_longer_be_cloned_are_dropped()
    {
        Process::fake();
        $withClone = $this->cloned(['clone_mode' => CloneMode::Never, 'clone_status' => CloneStatus::Queued]);
        $withoutClone = Repository::factory()->for($this->target)->create(['excluded_at' => now(), 'clone_status' => CloneStatus::Queued]);

        app(CloneSynchronizer::class)->sync($this->target);

        $this->assertSame(CloneStatus::Cloned, $withClone->fresh()->clone_status);
        $this->assertNull($withoutClone->fresh()->clone_status);
        Process::assertNothingRan();
    }

    public function test_a_repository_requested_again_while_the_sync_runs_is_handled_again()
    {
        $first = Repository::factory()->for($this->target)->create(['full_name' => 'laravel/first']);
        Repository::factory()->for($this->target)->create(['full_name' => 'laravel/second']);

        // While the second repository is cloned, someone asks for the first one again.
        $this->fakeGit(function (PendingProcess $process) use ($first) {
            if (in_array('https://github.com/laravel/second.git', $process->command, true)) {
                Repository::whereKey($first->id)->update(['clone_status' => CloneStatus::Queued]);
            }

            return null;
        });

        $summary = app(CloneSynchronizer::class)->sync($this->target);

        $this->assertSame(3, $summary->cloned);
        $this->assertClonedTimes('laravel/first', 2);
        $this->assertSame(CloneStatus::Cloned, $first->fresh()->clone_status);
    }
}
