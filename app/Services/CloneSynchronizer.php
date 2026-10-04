<?php

namespace App\Services;

use App\Enums\CloneStatus;
use App\Models\Repository;
use App\Models\Target;
use App\Services\Git\CloneException;
use App\Services\Git\RepositoryCloner;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The cloning step of a sync: clones new repositories and updates the clones that
 * changed, one repository at a time.
 *
 * Only repositories with work to do are touched (never cloned, failed last time,
 * pushed to since the last clone, or set to another branch), so a repeated sync is
 * cheap. One repository failing doesn't stop the others: its error is stored on the
 * repository and the next sync tries it again.
 */
class CloneSynchronizer
{
    /**
     * Passes over the target's repositories. A later pass picks up repositories that
     * were requested (e.g. a branch picked on the repository page) while the sync ran.
     */
    private const MAX_PASSES = 5;

    public function __construct(private readonly RepositoryCloner $cloner) {}

    /**
     * Clone or update the target's repositories. With a deadline, no new clone starts
     * after it: the remaining repositories stay queued for the next sync.
     */
    public function sync(Target $target, ?CarbonInterface $deadline = null): CloneSummary
    {
        $cloned = 0;
        $failed = 0;
        /** @var array<int, true> $processed */
        $processed = [];

        $this->releaseUnwanted($target);

        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $pending = $this->pending($target, $processed);

            if ($pending->isEmpty()) {
                break;
            }

            // Shows which repositories are waiting; a bulk update, so no per-row events.
            Repository::query()->whereKey($pending->modelKeys())->update(['clone_status' => CloneStatus::Queued]);

            foreach ($pending->values() as $index => $repository) {
                if ($deadline?->isPast()) {
                    return new CloneSummary($cloned, $failed, deferred: $pending->count() - $index);
                }

                $processed[$repository->id] = true;

                match ($this->process($repository)) {
                    true => $cloned++,
                    false => $failed++,
                    null => null,
                };
            }
        }

        return new CloneSummary($cloned, $failed);
    }

    /**
     * Repositories of the target with cloning enabled and work to do.
     *
     * @param  array<int, true>  $processed
     * @return Collection<int, Repository>
     */
    private function pending(Target $target, array $processed): Collection
    {
        // The setting may change while the sync runs.
        $target->refresh();

        return $target->repositories()
            ->whereNull('missing_at')
            ->whereNull('excluded_at')
            ->orderBy('id')
            ->get()
            ->each(fn (Repository $repository) => $repository->setRelation('target', $target))
            ->filter(fn (Repository $repository) => $repository->shouldClone() && $this->needsWork($repository, $processed))
            ->values();
    }

    /**
     * Whether the repository has to be cloned (again) or updated.
     *
     * @param  array<int, true>  $processed
     */
    private function needsWork(Repository $repository, array $processed): bool
    {
        // Already handled by this sync: only again when it was requested since.
        if (isset($processed[$repository->id])) {
            return $repository->clone_status === CloneStatus::Queued;
        }

        if ($repository->exceedsCloneLimit()) {
            return $repository->clone_status !== CloneStatus::Skipped;
        }

        return $repository->clone_status !== CloneStatus::Cloned
            || ! $this->cloner->isCloned($repository)
            || $repository->cloned_branch !== $repository->cloneBranch()
            || $repository->cloned_at === null
            || ($repository->pushed_at !== null && $repository->pushed_at->isAfter($repository->cloned_at));
    }

    /**
     * Clone or update one repository. Returns true when done, false when it failed and
     * null when it was skipped for its size.
     */
    private function process(Repository $repository): ?bool
    {
        if ($repository->exceedsCloneLimit()) {
            $repository->update([
                'clone_status' => CloneStatus::Skipped,
                'clone_error' => sprintf(
                    'Not cloned: GitHub reports %d MB, the limit is %d MB (GITHUB_CLONE_MAX_SIZE_MB).',
                    (int) ceil($repository->size_kb / 1024),
                    config('services.github.clone_max_size_mb'),
                ),
            ]);

            return null;
        }

        $repository->update(['clone_status' => CloneStatus::Cloning]);

        try {
            $commit = $this->cloner->cloneOrUpdate($repository);
        } catch (CloneException $e) {
            Log::warning('Repository clone failed.', [
                'clone_repository_id' => $repository->id,
                'clone_repository' => $repository->full_name,
                'clone_branch' => $repository->cloneBranch(),
                'error' => $e->getMessage(),
            ]);

            $repository->update(['clone_status' => CloneStatus::Failed, 'clone_error' => $e->getMessage()]);

            return false;
        }

        $repository->update([
            'clone_status' => CloneStatus::Cloned,
            'cloned_commit' => $commit,
            'cloned_branch' => $repository->cloneBranch(),
            'cloned_at' => now(),
            'clone_error' => null,
        ]);

        return true;
    }

    /**
     * Repositories still marked queued that should not be cloned anymore (cloning
     * turned off, excluded or removed from GitHub) go back to their previous state.
     */
    private function releaseUnwanted(Target $target): void
    {
        $target->repositories()
            ->where('clone_status', CloneStatus::Queued)
            ->get()
            ->each(fn (Repository $repository) => $repository->setRelation('target', $target))
            ->reject(fn (Repository $repository) => $repository->missing_at === null && $repository->shouldClone())
            ->each(fn (Repository $repository) => $repository->update([
                'clone_status' => $repository->cloned_at !== null ? CloneStatus::Cloned : null,
            ]));
    }
}
