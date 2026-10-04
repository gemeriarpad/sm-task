<?php

namespace App\Http\Controllers;

use App\Enums\CloneMode;
use App\Enums\CloneStatus;
use App\Models\Repository;
use App\Services\Git\ClonedFiles;
use App\Services\Git\CloneException;
use App\Services\Git\RepositoryCloner;
use App\Services\TargetLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Cloning settings of a repository and read-only access to its clone.
 */
class RepositoryCloneController extends Controller
{
    public function __construct(private readonly ClonedFiles $files) {}

    /**
     * Change whether the repository is cloned and which branch; a sync applies it
     * right away.
     */
    public function update(Request $request, Repository $repository): RedirectResponse
    {
        $validated = $request->validate([
            'mode' => ['sometimes', Rule::enum(CloneMode::class)],
            // Null follows the default branch.
            'branch' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:'.Repository::BRANCH_PATTERN],
        ], ['branch.regex' => 'This is not a valid branch name.']);

        $repository->fill([
            ...(array_key_exists('mode', $validated) ? ['clone_mode' => $validated['mode']] : []),
            ...(array_key_exists('branch', $validated) ? ['clone_branch' => $validated['branch']] : []),
        ])->save();

        if ($repository->wasChanged() && ! $repository->requestClone() && $repository->clone_status === CloneStatus::Queued) {
            // Nothing to clone anymore: drop the pending request.
            $repository->update(['clone_status' => $repository->cloned_at !== null ? CloneStatus::Cloned : null]);
        }

        return back();
    }

    /**
     * Clone or update the repository now, through a sync of its target.
     */
    public function refresh(Repository $repository): RedirectResponse
    {
        $repository->requestClone();

        return back();
    }

    /**
     * Delete the repository's local clone. Refused while its target syncs, because the
     * sync may be writing the clone.
     *
     * @throws ValidationException
     */
    public function destroy(Repository $repository, TargetLifecycle $lifecycle): RedirectResponse
    {
        if ($repository->target->isSyncing()) {
            throw ValidationException::withMessages([
                'clone' => 'A sync is running for this target. Wait for it to finish and try again.',
            ]);
        }

        $lifecycle->deleteClone($repository);

        return back();
    }

    /**
     * The repository's branches on GitHub, for the branch picker.
     */
    public function branches(Repository $repository, RepositoryCloner $cloner): JsonResponse
    {
        try {
            $branches = $cloner->branches($repository);
        } catch (CloneException $e) {
            Log::warning('Could not list the repository branches.', ['repository_id' => $repository->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Could not load the branches from GitHub. Try again later.'], 502);
        }

        return response()->json(['branches' => $branches, 'default' => $repository->default_branch]);
    }

    /**
     * The entries of one folder of the clone (the tree loads folders lazily).
     */
    public function tree(Request $request, Repository $repository): JsonResponse
    {
        try {
            return response()->json(['entries' => $this->files->list($repository, $this->path($request))]);
        } catch (CloneException) {
            abort(404);
        }
    }

    /**
     * A file preview.
     */
    public function file(Request $request, Repository $repository): JsonResponse
    {
        try {
            return response()->json($this->files->read($repository, $this->path($request)));
        } catch (CloneException) {
            abort(404);
        }
    }

    /**
     * Download the clone (including .git) as a zip.
     */
    public function download(Repository $repository): BinaryFileResponse
    {
        try {
            $zip = $this->files->zip($repository);
        } catch (CloneException) {
            abort(404);
        }

        $name = $repository->name.($repository->cloned_commit ? '-'.substr($repository->cloned_commit, 0, 7) : '').'.zip';

        return response()->download($zip, $name)->deleteFileAfterSend();
    }

    private function path(Request $request): string
    {
        $path = $request->query('path', '');

        return is_string($path) ? $path : '';
    }
}
