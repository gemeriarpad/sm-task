<?php

namespace App\Jobs;

use App\Enums\CloneStatus;
use App\Enums\SyncRunStatus;
use App\Enums\TargetStatus;
use App\Exceptions\FailureMessage;
use App\Models\Repository;
use App\Models\SyncRun;
use App\Models\Target;
use App\Services\CloneSynchronizer;
use App\Services\GitHub\GitHubException;
use App\Services\RepositorySynchronizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Synchronizes one target's repositories in the background: stores what GitHub
 * returns, then clones or updates the repositories with cloning enabled.
 *
 * Safeguards (see the README for the reasoning):
 *  - Duplicate requests: ShouldBeUnique keeps at most one queued/running job per
 *    target; Target::queueSync() also skips targets that are already in progress.
 *  - Overlapping: WithoutOverlapping guarantees two workers never sync the same
 *    target at the same time, even if a unique lock expired.
 *  - Timeouts: $timeout kills a hung job (failing it); the HTTP client and every
 *    git command have their own timeouts. Cloning stops starting new clones well
 *    before $timeout and leaves the rest for the next sync, so a large target is
 *    cloned over several syncs instead of failing. Keep $timeout below the queue's
 *    retry_after.
 *  - Retries: temporary GitHub errors are retried with backoff, rate limits are
 *    retried once the limit resets, anything else fails immediately.
 *
 * Errors: the target's last_error only ever gets a message meant for users; the
 * full exception is logged with the target id/name for debugging. A failed clone
 * doesn't fail the sync: the error is stored on the repository and counted in the run.
 */
class SyncTargetRepositories implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Attempts before the job is marked as failed.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before the 2nd and 3rd attempt.
     *
     * @var list<int>
     */
    public array $backoff = [30, 120];

    /**
     * Seconds the job may run (must stay below the queue connection's retry_after).
     */
    public int $timeout = 1800;

    /**
     * Seconds kept free at the end of the run: no new clone starts after
     * $timeout - CLONE_RESERVE, so the last one (each git command may take up to
     * 540 seconds) can finish before the job is killed.
     */
    public const CLONE_RESERVE = 600;

    /**
     * Mark the job as failed (instead of retrying it) when it times out.
     */
    public bool $failOnTimeout = true;

    /**
     * Seconds the unique lock is held at most, in case a worker dies mid-job.
     */
    public const UNIQUE_FOR = 3600;

    public int $uniqueFor = self::UNIQUE_FOR;

    /**
     * Longest rate limit wait we schedule a retry for, in seconds.
     */
    private const MAX_RATE_LIMIT_WAIT = 3600;

    /**
     * Drop the job when its target was deleted while it waited in the queue.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Target $target) {}

    /**
     * The unique ID of the job: one sync per target.
     */
    public function uniqueId(): string
    {
        return (string) $this->target->id;
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->target->id))
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 60),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(RepositorySynchronizer $synchronizer, CloneSynchronizer $cloneSynchronizer): void
    {
        // Every log line written while this job runs (including the worker's own
        // exception reports) carries the target, so a failure can be traced back.
        Context::add($this->logContext());

        // Deactivated while the job waited in the queue.
        $this->target->refresh();

        if (! $this->target->isActive()) {
            Log::info('Repository sync skipped: the target is inactive.');

            return;
        }

        $cloneDeadline = now()->addSeconds($this->timeout - self::CLONE_RESERVE);

        $this->target->update(['status' => TargetStatus::Syncing]);

        // History: one row per attempt.
        $run = $this->target->syncRuns()->create([
            'attempt' => $this->attempts(),
            'status' => SyncRunStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $summary = $synchronizer->sync($this->target);

            // The repository list is complete: show it while the clones are made.
            $this->target->update(['github_repositories_count' => $summary->repositories]);

            $clones = $cloneSynchronizer->sync($this->target, $cloneDeadline);
        } catch (GitHubException $e) {
            $this->handleGitHubError($e, $run);

            return;
        } catch (Throwable $e) {
            // Unexpected (e.g. database) errors are retried by the queue; the run only
            // records a safe message, the worker logs the exception with context.
            $run->finish(SyncRunStatus::Failed, [
                'error' => $this->userMessage($e),
                'will_retry' => $this->attempts() < $this->tries,
            ]);

            throw $e;
        }

        $run->finish(SyncRunStatus::Succeeded, [
            'repositories_count' => $summary->repositories,
            'clones_count' => $clones->cloned,
            'clone_errors_count' => $clones->failed,
            'clones_deferred_count' => $clones->deferred,
        ]);

        $this->target->update([
            'status' => TargetStatus::Synced,
            'last_synced_at' => now(),
            'last_error' => null,
        ]);

        Log::info('Repository sync finished.', [
            'repositories' => $summary->repositories,
            'pages' => $summary->pages,
            // Unchanged since the last sync: no body, no rate limit cost with a token.
            'pages_not_modified' => $summary->notModifiedPages,
            'clones' => $clones->cloned,
            'clone_errors' => $clones->failed,
            'clones_deferred' => $clones->deferred,
            'duration_ms' => $run->duration_ms,
            'attempt' => $this->attempts(),
        ]);
    }

    /**
     * Retry temporary failures, fail permanent ones right away.
     *
     * @throws GitHubException
     */
    private function handleGitHubError(GitHubException $e, SyncRun $run): void
    {
        $run->finish(SyncRunStatus::Failed, [
            'error' => $e->getMessage(),
            'will_retry' => $e->retryable && $this->attempts() < $this->tries,
        ]);

        if (! $e->retryable) {
            // e.g. 404: retrying won't help, and failed() records the error.
            $this->fail($e);

            return;
        }

        if ($this->attempts() >= $this->tries) {
            throw $e;
        }

        Log::warning('Repository sync attempt failed, retrying.', [
            'attempt' => $this->attempts(),
            'max_attempts' => $this->tries,
            'status_code' => $e->getCode(),
            'retry_after' => $e->retryAfter,
            'error' => $e->getMessage(),
        ]);

        $this->target->update([
            'status' => TargetStatus::Queued,
            'last_failed_at' => now(),
            'last_error' => "Attempt {$this->attempts()} failed, retrying: {$e->getMessage()}",
        ]);

        if ($e->retryAfter !== null) {
            // Wait for the rate limit to reset instead of burning the remaining attempts.
            $this->release(min($e->retryAfter + 5, self::MAX_RATE_LIMIT_WAIT));

            return;
        }

        throw $e;
    }

    /**
     * Handle a job failure (permanent error, attempts exhausted or timeout).
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Repository sync failed.', [
            ...$this->logContext(),
            'exception' => $exception,
        ]);

        // A timed-out (killed) attempt never got to finish its run or its clone.
        $this->target->syncRuns()
            ->where('status', SyncRunStatus::Running)
            ->get()
            ->each(fn (SyncRun $run) => $run->finish(SyncRunStatus::Failed, ['error' => $this->userMessage($exception)]));

        $this->target->repositories()
            ->where('clone_status', CloneStatus::Cloning)
            ->get()
            ->each(fn (Repository $repository) => $repository->update([
                'clone_status' => CloneStatus::Failed,
                'clone_error' => 'The sync stopped while this repository was being cloned; the next sync tries again.',
            ]));

        $this->target->update([
            'status' => TargetStatus::Failed,
            'last_failed_at' => now(),
            'last_error' => $this->userMessage($exception),
        ]);
    }

    /**
     * A message safe to show in the UI: GitHub errors are already worded for users,
     * anything unexpected (e.g. a database error) is not shown verbatim.
     */
    private function userMessage(?Throwable $exception): string
    {
        return FailureMessage::for($exception, 'The synchronization', $this->timeout);
    }

    /**
     * Identifies the target in log entries.
     *
     * @return array<string, mixed>
     */
    private function logContext(): array
    {
        return [
            'sync_target_id' => $this->target->id,
            'sync_target' => $this->target->name,
            'sync_target_type' => $this->target->type->value,
        ];
    }
}
