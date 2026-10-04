<?php

namespace App\Http\Controllers;

use App\Concerns\DataTableQuery;
use App\Enums\CloneStatus;
use App\Enums\TargetStatus;
use App\Models\Repository;
use App\Models\Target;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Clone errors" tab of the Errors section: repositories whose clone failed or
 * was skipped as too large, with the message stored on the repository. A sync run
 * only counts its clone errors; the messages are listed here.
 */
class CloneErrorController extends Controller
{
    use DataTableQuery;

    /**
     * Sort keys => columns (null: sorted by the target's name).
     */
    private const SORTS = [
        'repository' => 'full_name',
        'target' => null,
        'kind' => 'clone_status',
        'size' => 'size_kb',
    ];

    private const FILTERABLE = ['target', 'kind'];

    public function index(Request $request): Response
    {
        [$sort, $direction] = $this->sortParams($request, array_keys(self::SORTS), 'repository');
        $filters = array_filter($this->filters($request, self::FILTERABLE), fn (string $value, string $key) => match ($key) {
            'target' => ctype_digit($value),
            'kind' => in_array($value, [CloneStatus::Failed->value, CloneStatus::Skipped->value], true),
        }, ARRAY_FILTER_USE_BOTH);
        $column = self::SORTS[$sort];

        $repositories = Repository::query()
            ->cloneProblems()
            ->with('target:id,name,avatar_url')
            ->when($filters['target'] ?? null, fn (Builder $q, string $target) => $q->where('target_id', (int) $target))
            ->when($filters['kind'] ?? null, fn (Builder $q, string $kind) => $q->where('clone_status', $kind))
            // Unknown sizes last on every database (PostgreSQL sorts NULLs first descending).
            ->when($sort === 'size', fn (Builder $q) => $q->orderByRaw('CASE WHEN size_kb IS NULL THEN 1 ELSE 0 END'))
            ->when(
                $column === null,
                fn (Builder $q) => $q->orderBy(Target::select('name')->whereColumn('targets.id', 'repositories.target_id'), $direction),
                fn (Builder $q) => $q->orderBy($column, $direction),
            )
            ->orderBy('full_name')
            ->paginate($this->perPage($request, default: 25))
            ->withQueryString()
            ->through(fn (Repository $repository): array => [
                'id' => $repository->id,
                'full_name' => $repository->full_name,
                'size_kb' => $repository->size_kb,
                'clone_status' => $repository->clone_status->value,
                'clone_branch' => $repository->cloneBranch(),
                'clone_error' => $repository->clone_error,
                'target' => ['id' => $repository->target->id, 'name' => $repository->target->name, 'avatar_url' => $repository->target->avatar_url],
            ]);

        return Inertia::render('clone-errors/Index', [
            'repositories' => $repositories,
            'sort' => $sort,
            'direction' => $direction,
            'filters' => (object) $filters,
            'options' => [
                'targets' => Target::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Target $target) => ['value' => (string) $target->id, 'label' => $target->name]),
                'kinds' => [
                    ['value' => CloneStatus::Failed->value, 'label' => 'Failed'],
                    ['value' => CloneStatus::Skipped->value, 'label' => 'Too large'],
                ],
            ],
            // Drives the polling fallback of the live updates.
            'syncing' => Target::whereIn('status', [TargetStatus::Queued, TargetStatus::Syncing])->exists(),
        ]);
    }
}
