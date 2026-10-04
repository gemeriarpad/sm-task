<?php

namespace App\Http\Controllers;

use App\Concerns\DataTableQuery;
use App\Enums\SyncRunStatus;
use App\Enums\TargetStatus;
use App\Models\SyncRun;
use App\Models\Target;
use App\Queries\SyncRunListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SyncRunController extends Controller
{
    use DataTableQuery;

    /**
     * Every sync job attempt, newest first.
     */
    public function index(Request $request): Response
    {
        return $this->table($request, 'sync-runs/Index', SyncRun::query(), ['target', 'status']);
    }

    /**
     * Failed attempts and syncs that failed to clone repositories, newest first.
     */
    public function errors(Request $request): Response
    {
        return $this->table($request, 'sync-runs/Errors', SyncRun::query()->errored(), ['target', 'error', 'retrying']);
    }

    /**
     * Render a server-side sorted, filtered and paginated sync run table.
     *
     * @param  Builder<SyncRun>  $query
     * @param  list<string>  $filterable
     */
    private function table(Request $request, string $component, Builder $query, array $filterable): Response
    {
        [$sort, $direction] = $this->sortParams($request, SyncRunListQuery::SORTABLE, 'started_at', 'desc');
        $filters = SyncRunListQuery::sanitize($this->filters($request, $filterable));

        $runs = SyncRunListQuery::apply($query, $filters, $sort, $direction)
            ->with('target:id,name,avatar_url')
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (SyncRun $run): array => SyncRunListQuery::item($run));

        return Inertia::render($component, [
            'runs' => $runs,
            'sort' => $sort,
            'direction' => $direction,
            'filters' => (object) $filters,
            'options' => [
                'targets' => Target::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Target $target) => ['value' => (string) $target->id, 'label' => $target->name]),
                'statuses' => array_map(fn (SyncRunStatus $status) => ['value' => $status->value, 'label' => $status->label()], SyncRunStatus::cases()),
                'retrying' => [['value' => 'yes', 'label' => 'Retrying'], ['value' => 'no', 'label' => 'Not retrying']],
            ],
            // Drives the polling fallback of the live updates.
            'syncing' => Target::whereIn('status', [TargetStatus::Queued, TargetStatus::Syncing])->exists(),
        ]);
    }
}
