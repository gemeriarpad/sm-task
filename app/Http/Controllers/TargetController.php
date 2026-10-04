<?php

namespace App\Http\Controllers;

use App\Concerns\DataTableQuery;
use App\Enums\CloneMode;
use App\Enums\RepositoryScope;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Http\Requests\TargetRequest;
use App\Models\Target;
use App\Queries\RepositoryListQuery;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubException;
use App\Services\TargetLifecycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TargetController extends Controller
{
    use DataTableQuery;

    /**
     * Columns the targets table may be sorted by.
     */
    private const SORTABLE = ['name', 'type', 'status', 'repositories_count', 'excluded_count', 'last_synced_at', 'created_at'];

    /**
     * Columns the targets table may be filtered by.
     */
    private const FILTERABLE = ['name', 'type', 'status'];

    /**
     * List the targets in a server-side sorted, filtered and paginated table.
     */
    public function index(Request $request): Response
    {
        $perPage = $this->perPage($request);
        [$sort, $direction] = $this->sortParams($request, self::SORTABLE, 'name');
        $filters = $this->filters($request, self::FILTERABLE);

        $targets = $this->filtered($filters)
            ->withRepositoryCounts()
            // Never synced targets last on every database (PostgreSQL sorts NULLs first
            // descending). Only for the real column: count aliases can't be used in an
            // ORDER BY expression on PostgreSQL.
            ->when($sort === 'last_synced_at', fn (Builder $query) => $query->orderByRaw('CASE WHEN last_synced_at IS NULL THEN 1 ELSE 0 END'))
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Target $target): array => $this->listItem($target));

        return Inertia::render('targets/Index', [
            'targets' => $targets,
            'sort' => $sort,
            'direction' => $direction,
            'filters' => (object) $filters,
            'options' => $this->options(),
        ]);
    }

    /**
     * Show the form for adding a target.
     */
    public function create(): Response
    {
        return Inertia::render('targets/Create', [
            'options' => $this->options(),
        ]);
    }

    /**
     * Store a newly added target.
     */
    public function store(TargetRequest $request): RedirectResponse
    {
        Target::create($request->validated())->queueSync(force: true);

        return to_route('targets.index');
    }

    /**
     * Show a target with a searchable, filterable and paginated list of its repositories.
     */
    public function show(Request $request, Target $target): Response
    {
        $list = RepositoryListQuery::fromRequest($request);
        $target->loadCount(Target::repositoryCounts());

        return Inertia::render('targets/Show', [
            'target' => [
                ...$this->listItem($target),
                'missing_count' => $target->repositories()->whereNull('excluded_at')->whereNotNull('missing_at')->count(),
                'repository_scope' => $target->repository_scope->value,
                'clone_repositories' => $target->clone_repositories,
                // What deleting the target or its clones removes (the dialogs list it).
                'stored_count' => $target->repositories()->count(),
                'local_clones_count' => $target->repositories()->whereNotNull('cloned_at')->count(),
                'missing_clones_count' => $target->repositories()->whereNotNull('cloned_at')->whereNotNull('missing_at')->count(),
                'runs_count' => $target->syncRuns()->count(),
            ],
            'repositories' => $list->paginate($target->repositories()->getQuery()),
            'query' => $list->filters,
            'options' => RepositoryListQuery::options($target->repositories()->getQuery()),
        ]);
    }

    /**
     * Queue a repository sync for the target.
     */
    public function sync(Target $target): RedirectResponse
    {
        $target->queueSync();

        return back();
    }

    /**
     * Queue a repository sync for every active target.
     */
    public function syncAll(): RedirectResponse
    {
        Target::queueSyncs(Target::query()->active());

        return back();
    }

    /**
     * Look up a name on GitHub so the form can confirm it exists before saving.
     */
    public function check(Request $request, GitHubClient $github): JsonResponse
    {
        $name = $request->validate([
            'name' => ['required', 'string', 'max:39', 'regex:'.TargetRequest::NAME_PATTERN],
        ])['name'];

        try {
            $account = $github->account($name);
        } catch (GitHubException $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'found' => $account !== null,
            'account' => $account?->toArray(),
        ]);
    }

    /**
     * Show the form for editing a target.
     */
    public function edit(Target $target): Response
    {
        $repositories = fn () => $target->repositories();
        $active = fn () => $repositories()->whereNull('missing_at')->whereNull('excluded_at');

        return Inertia::render('targets/Edit', [
            'target' => [
                'id' => $target->id,
                'name' => $target->name,
                'type' => $target->type->value,
                'avatar_url' => $target->avatar_url,
                'status' => $target->status->value,
                'clone_repositories' => $target->clone_repositories,
                'repository_scope' => $target->repository_scope->value,
            ],
            // What changing the settings would do; the form explains it before saving.
            'impact' => [
                'forks_count' => $repositories()->where('fork', true)->count(),
                'fork_clones_count' => $repositories()->where('fork', true)->whereNotNull('cloned_at')->count(),
                'inherit_count' => $active()->where('clone_mode', CloneMode::Inherit)->count(),
                'inherit_clones_count' => $repositories()->where('clone_mode', CloneMode::Inherit)->whereNotNull('cloned_at')->count(),
                'always_count' => $active()->where('clone_mode', CloneMode::Always)->count(),
            ],
            'options' => $this->options(),
        ]);
    }

    /**
     * Update the given target's settings (its GitHub name and type are fixed).
     */
    public function update(TargetRequest $request, Target $target): RedirectResponse
    {
        $target->update($request->validated());

        // Another scope changes which repositories are synced, and turning cloning on
        // clones them: a sync applies both right away. Turning cloning off keeps the
        // existing clones as they are ("Delete all clones" on the target removes them).
        if ($target->wasChanged('repository_scope') || ($target->wasChanged('clone_repositories') && $target->clone_repositories)) {
            $target->queueSync();
        }

        return to_route('targets.index');
    }

    /**
     * Stop syncing the target, keeping the data the user chose to keep.
     */
    public function deactivate(Request $request, Target $target, TargetLifecycle $lifecycle): RedirectResponse
    {
        $validated = $request->validate([
            'keep_repositories' => ['required', 'boolean'],
            'keep_clones' => ['required', 'boolean'],
            'keep_history' => ['required', 'boolean'],
        ]);

        $this->ensureNotSyncing($target);

        if ($target->isActive()) {
            $lifecycle->deactivate(
                $target,
                keepRepositories: (bool) $validated['keep_repositories'],
                keepClones: (bool) $validated['keep_clones'],
                keepHistory: (bool) $validated['keep_history'],
            );
        }

        return back();
    }

    /**
     * Sync a deactivated target again.
     */
    public function reactivate(Target $target, TargetLifecycle $lifecycle): RedirectResponse
    {
        if (! $target->isActive()) {
            $lifecycle->reactivate($target);
        }

        return back();
    }

    /**
     * Delete the target with all of its data; the user confirms by typing its name.
     */
    public function destroy(Request $request, Target $target, TargetLifecycle $lifecycle): RedirectResponse
    {
        $request->validate(
            ['confirm_name' => ['required', 'string', Rule::in([$target->name])]],
            ['confirm_name.required' => 'Type the target name to confirm.', 'confirm_name.in' => 'The name does not match.'],
        );

        $this->ensureNotSyncing($target);

        $lifecycle->delete($target);

        return to_route('targets.index');
    }

    /**
     * Delete every local clone of the target.
     */
    public function destroyClones(Target $target, TargetLifecycle $lifecycle): RedirectResponse
    {
        $this->ensureNotSyncing($target);

        $lifecycle->deleteClones($target);

        return back();
    }

    /**
     * Data is never removed while a sync job may be writing it.
     *
     * @throws ValidationException
     */
    private function ensureNotSyncing(Target $target): void
    {
        if ($target->isSyncing()) {
            throw ValidationException::withMessages([
                'target' => 'A sync is running for this target. Wait for it to finish and try again.',
            ]);
        }
    }

    /**
     * The targets query with the active table filters applied.
     *
     * @param  array<string, string>  $filters
     * @return Builder<Target>
     */
    private function filtered(array $filters): Builder
    {
        return Target::query()->filter($filters);
    }

    /**
     * Serialize a target for the table.
     *
     * @return array<string, mixed>
     */
    private function listItem(Target $target): array
    {
        return [
            'repositories_count' => $target->repositories_count,
            'github_repositories_count' => $target->github_repositories_count,
            'excluded_count' => $target->excluded_count,
            'clones_enabled_count' => $target->clonesEnabledCount(),
            'clones_cloned_count' => $target->clonesClonedCount(),
            'id' => $target->id,
            'name' => $target->name,
            'type' => $target->type->value,
            'avatar_url' => $target->avatar_url,
            'status' => $target->status->value,
            'deactivated_at' => $target->deactivated_at?->toIso8601String(),
            'last_synced_at' => $target->last_synced_at?->toIso8601String(),
            'last_failed_at' => $target->last_failed_at?->toIso8601String(),
            'last_error' => $target->last_error,
        ];
    }

    /**
     * Select options for the target type and status.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function options(): array
    {
        $map = fn (TargetType|TargetStatus|RepositoryScope $case): array => ['value' => $case->value, 'label' => $case->label()];

        return [
            'types' => array_map($map, TargetType::cases()),
            'statuses' => array_map($map, TargetStatus::cases()),
            'scopes' => array_map($map, RepositoryScope::cases()),
        ];
    }
}
