<?php

namespace App\Services;

use App\Enums\TargetStatus;
use App\Models\Repository;
use App\Models\SyncRun;
use App\Models\Target;
use App\Services\Git\RepositoryCloner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deactivating, reactivating and deleting targets, and removing their clones.
 *
 * Callers make sure no sync is running for the target (TargetController refuses
 * these actions while one is), so nothing writes to the data being removed.
 */
class TargetLifecycle
{
    /**
     * The clone columns of a repository without a local clone.
     */
    private const NO_CLONE = [
        'clone_status' => null,
        'cloned_commit' => null,
        'cloned_branch' => null,
        'cloned_at' => null,
        'clone_error' => null,
    ];

    public function __construct(private readonly RepositoryCloner $cloner) {}

    /**
     * Stop syncing a target, dropping the data the user chose not to keep. Kept data
     * stays browsable; reactivating syncs whatever was dropped again.
     */
    public function deactivate(Target $target, bool $keepRepositories, bool $keepClones, bool $keepHistory): void
    {
        // A clone is only reachable through its repository, so dropping the
        // repositories drops their clones too.
        if (! $keepRepositories || ! $keepClones) {
            $this->deleteClones($target);
        }

        DB::transaction(function () use ($target, $keepRepositories, $keepHistory) {
            if (! $keepRepositories) {
                $target->repositories()->delete();
            }

            if (! $keepHistory) {
                SyncRun::query()->where('target_id', $target->id)->delete();
            }

            $target->update([
                'status' => TargetStatus::Inactive,
                'deactivated_at' => now(),
                ...($keepRepositories ? [] : ['github_repositories_count' => null]),
                // The last error is part of the sync history.
                ...($keepHistory ? [] : ['last_error' => null, 'last_failed_at' => null]),
            ]);
        });

        Log::info('Target deactivated.', [
            'target_id' => $target->id,
            'target' => $target->name,
            'kept_repositories' => $keepRepositories,
            'kept_clones' => $keepRepositories && $keepClones,
            'kept_history' => $keepHistory,
        ]);
    }

    /**
     * Sync a deactivated target again.
     */
    public function reactivate(Target $target): void
    {
        $target->update(['status' => TargetStatus::Queued, 'deactivated_at' => null]);
        $target->queueSync(force: true);
    }

    /**
     * Delete the target with its repositories, clones and sync history.
     */
    public function delete(Target $target): void
    {
        $this->deleteClones($target);

        // Repositories and sync runs go with it (cascading foreign keys).
        $target->delete();

        Log::info('Target deleted.', ['target_id' => $target->id, 'target' => $target->name]);
    }

    /**
     * Delete every local clone of the target's repositories. With cloning still
     * enabled, the next sync clones them again. Returns the number of clones deleted.
     */
    public function deleteClones(Target $target): int
    {
        $deleted = 0;

        $target->repositories()->lazyById()->each(function (Repository $repository) use (&$deleted) {
            if ($this->cloner->isCloned($repository)) {
                $deleted++;
            }

            $this->cloner->delete($repository);
        });

        $target->repositories()->update(self::NO_CLONE);

        return $deleted;
    }

    /**
     * Delete one repository's local clone. Its clone setting is kept: with cloning
     * enabled, the next sync clones it again.
     */
    public function deleteClone(Repository $repository): void
    {
        $this->cloner->delete($repository);
        $repository->update(self::NO_CLONE);

        Log::info('Repository clone deleted.', ['repository_id' => $repository->id, 'repository' => $repository->full_name]);
    }
}
