<?php

namespace Tests\Feature\Sync;

use App\Enums\CloneStatus;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Jobs\SyncTargetRepositories;
use App\Models\Repository;
use App\Models\Target;
use App\Services\GitHub\GitHubException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class SyncTargetRepositoriesTest extends TestCase
{
    use RefreshDatabase;

    private Target $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->target = Target::factory()->failed('Old error')->create([
            'name' => 'laravel',
            'type' => TargetType::Organization,
            'status' => TargetStatus::Queued,
        ]);
    }

    private function handle(SyncTargetRepositories $job): void
    {
        app()->call([$job, 'handle']);
    }

    public function test_a_successful_sync_marks_the_target_synced()
    {
        Http::fake(['api.github.com/orgs/laravel/repos*' => Http::response([GitHubClientTest::repo(1)])]);

        $this->handle((new SyncTargetRepositories($this->target))->withFakeQueueInteractions());

        $this->target->refresh();
        $this->assertSame(TargetStatus::Synced, $this->target->status);
        $this->assertNotNull($this->target->last_synced_at);
        $this->assertNull($this->target->last_error);
        $this->assertSame(1, $this->target->repositories()->count());
        $this->assertSame(1, $this->target->github_repositories_count);
    }

    public function test_the_sync_clones_the_repositories_and_records_it_in_the_run()
    {
        Process::fake(fn (PendingProcess $process) => in_array('rev-parse', $process->command, true)
            ? Process::result("0123456789abcdef0123456789abcdef01234567\n")
            : Process::result());
        Http::fake(['api.github.com/orgs/laravel/repos*' => Http::response([GitHubClientTest::repo(1), GitHubClientTest::repo(2)])]);
        $this->target->update(['clone_repositories' => true]);

        $this->handle((new SyncTargetRepositories($this->target))->withFakeQueueInteractions());

        $run = $this->target->syncRuns()->sole();
        $this->assertSame([2, 2, 0, 0], [$run->repositories_count, $run->clones_count, $run->clone_errors_count, $run->clones_deferred_count]);
        $this->assertSame(2, $this->target->repositories()->where('clone_status', CloneStatus::Cloned)->count());
        $this->assertSame(TargetStatus::Synced, $this->target->fresh()->status);
    }

    public function test_a_target_deactivated_while_queued_is_not_synced()
    {
        $this->target->update(['status' => TargetStatus::Inactive]);

        $this->handle((new SyncTargetRepositories($this->target))->withFakeQueueInteractions());

        $this->assertSame(TargetStatus::Inactive, $this->target->fresh()->status);
        $this->assertSame(0, $this->target->syncRuns()->count());
        Http::assertNothingSent();
    }

    public function test_a_clone_interrupted_by_a_timeout_is_marked_failed()
    {
        $repository = Repository::factory()->for($this->target)->create(['clone_status' => CloneStatus::Cloning]);

        (new SyncTargetRepositories($this->target))->failed(new TimeoutExceededException('timed out'));

        $this->assertSame(CloneStatus::Failed, $repository->fresh()->clone_status);
        $this->assertSame('The sync stopped while this repository was being cloned; the next sync tries again.', $repository->fresh()->clone_error);
    }

    public function test_cloning_stops_early_enough_for_the_last_git_command_to_finish()
    {
        $job = new SyncTargetRepositories($this->target);

        // Git commands time out after 540 seconds (RepositoryCloner).
        $this->assertGreaterThan(540, SyncTargetRepositories::CLONE_RESERVE);
        $this->assertGreaterThan(SyncTargetRepositories::CLONE_RESERVE, $job->timeout);
        $this->assertTrue($job->deleteWhenMissingModels);
    }

    public function test_permanent_errors_fail_the_job_without_retrying()
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        $job = (new SyncTargetRepositories($this->target))->withFakeQueueInteractions();
        $this->handle($job);

        $job->assertFailed();
    }

    public function test_temporary_errors_are_rethrown_for_a_retry()
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad Gateway'], 502)]);

        $job = (new SyncTargetRepositories($this->target))->withFakeQueueInteractions();

        try {
            $this->handle($job);
            $this->fail('Expected a GitHubException.');
        } catch (GitHubException $e) {
            $this->assertTrue($e->retryable);
        }

        $this->target->refresh();
        $this->assertSame(TargetStatus::Queued, $this->target->status);
        $this->assertSame('Attempt 1 failed, retrying: GitHub is having problems right now (HTTP 502).', $this->target->last_error);
    }

    public function test_rate_limited_jobs_are_released_until_the_limit_resets()
    {
        $this->freezeTime();
        Http::fake(['api.github.com/*' => Http::response(['message' => 'API rate limit exceeded'], 403, [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) (time() + 600),
        ])]);

        $job = (new SyncTargetRepositories($this->target))->withFakeQueueInteractions();
        $this->handle($job);

        $job->assertReleased(delay: 605);
    }

    public function test_github_failures_mark_the_target_failed_with_the_github_error()
    {
        (new SyncTargetRepositories($this->target))->failed(GitHubException::notFound('Organization "laravel"'));

        $this->target->refresh();
        $this->assertSame(TargetStatus::Failed, $this->target->status);
        $this->assertSame('Organization "laravel" was not found on GitHub.', $this->target->last_error);
        $this->assertNotNull($this->target->last_failed_at);
    }

    public function test_timeouts_are_reported_with_a_readable_error()
    {
        (new SyncTargetRepositories($this->target))->failed(new TimeoutExceededException('App\\Jobs\\SyncTargetRepositories has timed out.'));

        $this->assertSame('The synchronization took longer than 1800 seconds and was stopped.', $this->target->fresh()->last_error);
    }

    public function test_unexpected_errors_are_logged_but_not_shown_verbatim()
    {
        Log::spy();
        $exception = new RuntimeException('SQLSTATE[HY000]: database is locked (SQL: update "targets" ...)');

        (new SyncTargetRepositories($this->target))->failed($exception);

        $this->assertSame('The synchronization failed unexpectedly. The error has been logged.', $this->target->fresh()->last_error);
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === 'Repository sync failed.'
            && $context['sync_target_id'] === $this->target->id
            && $context['sync_target'] === 'laravel'
            && $context['exception'] === $exception);
    }

    public function test_retried_attempts_are_logged_with_the_target_context()
    {
        Log::spy();
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad Gateway'], 502)]);

        try {
            $this->handle((new SyncTargetRepositories($this->target))->withFakeQueueInteractions());
        } catch (GitHubException) {
            //
        }

        $this->assertSame($this->target->id, Context::get('sync_target_id'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'Repository sync attempt failed, retrying.'
            && $context['attempt'] === 1
            && $context['status_code'] === 502)->once();
        // GitHub's own text is kept for debugging, but only in the log.
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'GitHub API request failed.'
            && $context['status'] === 502
            && $context['github_message'] === 'Bad Gateway')->once();
    }

    public function test_successful_syncs_are_logged()
    {
        Log::spy();
        Http::fake(['api.github.com/orgs/laravel/repos*' => Http::response([GitHubClientTest::repo(1), GitHubClientTest::repo(2)])]);

        $this->handle((new SyncTargetRepositories($this->target))->withFakeQueueInteractions());

        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context) => $message === 'Repository sync finished.'
            && $context['repositories'] === 2);
    }

    public function test_the_job_is_unique_per_target_and_never_overlaps()
    {
        $job = new SyncTargetRepositories($this->target);

        $this->assertSame((string) $this->target->id, $job->uniqueId());
        $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[0]);
        $this->assertSame((string) $this->target->id, $job->middleware()[0]->key);
        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);
        $this->assertTrue($job->failOnTimeout);
    }

    public function test_duplicate_dispatches_are_ignored_while_a_job_is_pending()
    {
        Queue::fake();

        SyncTargetRepositories::dispatch($this->target);
        SyncTargetRepositories::dispatch($this->target);
        SyncTargetRepositories::dispatch(Target::factory()->create());

        Queue::assertPushed(SyncTargetRepositories::class, 2);
    }

    public function test_queue_sync_skips_targets_already_in_progress()
    {
        Queue::fake();

        $this->target->update(['status' => TargetStatus::Syncing]);

        $this->assertFalse($this->target->queueSync());
        Queue::assertNothingPushed();
    }

    public function test_queue_sync_retries_targets_stuck_in_progress()
    {
        Queue::fake();

        $this->target->update(['status' => TargetStatus::Syncing]);
        $this->travel(SyncTargetRepositories::UNIQUE_FOR + 60)->seconds();

        $this->assertTrue($this->target->queueSync());
        Queue::assertPushed(SyncTargetRepositories::class);
    }

    public function test_queue_sync_marks_the_target_queued()
    {
        Queue::fake();

        $target = Target::factory()->synced()->create();

        $this->assertTrue($target->queueSync());
        $this->assertSame(TargetStatus::Queued, $target->fresh()->status);
        Queue::assertPushed(SyncTargetRepositories::class, fn (SyncTargetRepositories $job) => $job->target->is($target));
    }
}
