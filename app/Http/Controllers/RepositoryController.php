<?php

namespace App\Http\Controllers;

use App\Enums\CloneMode;
use App\Enums\TargetStatus;
use App\Models\Repository;
use App\Models\Target;
use App\Queries\RepositoryListQuery;
use App\Services\Git\RepositoryCloner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RepositoryController extends Controller
{
    /**
     * List the repositories of every target with search, filters and pagination.
     */
    public function index(Request $request): Response
    {
        $list = RepositoryListQuery::fromRequest($request);

        return Inertia::render('repositories/Index', [
            'repositories' => $list->paginate(Repository::query(), withTarget: true),
            'query' => $list->filters,
            'options' => RepositoryListQuery::options(Repository::query(), withTargets: true),
            'totals' => [
                'repositories' => Repository::whereNull('excluded_at')->count(),
                'missing' => Repository::whereNull('excluded_at')->whereNotNull('missing_at')->count(),
                'excluded' => Repository::whereNotNull('excluded_at')->count(),
            ],
            'syncing' => Target::whereIn('status', [TargetStatus::Queued, TargetStatus::Syncing])->exists(),
        ]);
    }

    /**
     * Exclude a repository: hidden from lists, frozen by syncs and never cloned.
     */
    public function exclude(Repository $repository): RedirectResponse
    {
        $repository->update(['excluded_at' => now()]);

        return back();
    }

    /**
     * Include a previously excluded repository again; the next sync updates it.
     */
    public function include(Repository $repository): RedirectResponse
    {
        $repository->update(['excluded_at' => null]);

        return back();
    }

    /**
     * Show a single repository.
     */
    public function show(Repository $repository, RepositoryCloner $cloner): Response
    {
        $repository->load('target:id,name,avatar_url,status,clone_repositories');

        return Inertia::render('repositories/Show', [
            'repository' => [
                ...RepositoryListQuery::item($repository, withTarget: true),
                'last_seen_at' => $repository->last_seen_at?->toIso8601String(),
                'created_at' => $repository->created_at?->toIso8601String(),
                'size_kb' => $repository->size_kb,
                'default_branch' => $repository->default_branch,
            ],
            'clone' => [
                'mode' => $repository->clone_mode->value,
                // Null follows the default branch.
                'branch' => $repository->clone_branch,
                'cloned_branch' => $repository->cloned_branch,
                'enabled' => $repository->shouldClone(),
                'target_clones' => $repository->target->clone_repositories,
                'status' => $repository->clone_status?->value,
                'commit' => $repository->cloned_commit,
                'cloned_at' => $repository->cloned_at?->toIso8601String(),
                'error' => $repository->clone_error,
                'available' => $cloner->isCloned($repository),
                'too_large' => $repository->exceedsCloneLimit(),
                'max_size_mb' => config('services.github.clone_max_size_mb'),
                // Cloning runs as part of the target's sync.
                'target_status' => $repository->target->status->value,
            ],
            'cloneModes' => collect(CloneMode::cases())->map(fn (CloneMode $mode) => ['value' => $mode->value, 'label' => $mode->label()]),
        ]);
    }
}
