<?php

namespace App\Http\Controllers;

use App\Enums\SyncRunStatus;
use App\Enums\TargetStatus;
use App\Models\Repository;
use App\Models\SyncRun;
use App\Models\Target;
use App\Queries\RepositoryListQuery;
use App\Queries\SyncRunListQuery;
use App\Services\QueueMonitor;
use App\Services\SyncSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Repository strip modes: key => label.
     */
    private const STRIPS = [
        'pushed' => 'Recently pushed',
        'stars' => 'Most starred',
        'added' => 'Recently added',
        'removed' => 'Recently removed',
    ];

    /**
     * Repository list filters closest to each strip mode, for its "View all" link.
     * "Recently added" has no matching sort, so it opens the default list.
     */
    private const STRIP_LIST_FILTERS = [
        'pushed' => ['sort' => 'pushed', 'missing' => 'hide'],
        'stars' => ['sort' => 'stars'],
        'added' => [],
        'removed' => ['missing' => 'only'],
    ];

    /**
     * Target strip modes: key => label.
     */
    private const TARGET_STRIPS = [
        'synced' => 'Recently synced',
        'added' => 'Recently added',
        'repositories' => 'Most repositories',
        'failed' => 'Failed',
    ];

    /**
     * Targets table sorting or filters matching each target strip mode, for its "View all" link.
     */
    private const TARGET_STRIP_LIST_FILTERS = [
        'synced' => ['sort' => 'last_synced_at', 'direction' => 'desc'],
        'added' => ['sort' => 'created_at', 'direction' => 'desc'],
        'repositories' => ['sort' => 'repositories_count', 'direction' => 'desc'],
        'failed' => ['filter' => ['status' => 'failed']],
    ];

    private const STRIP_SIZE = 5;

    /**
     * Rows in the latest errors and sync history lists; the rest is on their pages.
     */
    private const RUN_LIST_SIZE = 20;

    /**
     * Overview of targets, repositories and recent sync activity.
     */
    public function index(Request $request, SyncSchedule $schedule, QueueMonitor $queue): Response
    {
        $strip = $this->mode($request, 'strip', self::STRIPS, 'pushed');
        $targetStrip = $this->mode($request, 'target_strip', self::TARGET_STRIPS, 'synced');
        $statusCounts = Target::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('Dashboard', [
            'stats' => [
                'targets' => Target::count(),
                'repositories' => Repository::whereNull('excluded_at')->count(),
                'missing' => Repository::whereNull('excluded_at')->whereNotNull('missing_at')->count(),
                'statuses' => collect(TargetStatus::cases())->mapWithKeys(fn (TargetStatus $status) => [$status->value => (int) ($statusCounts[$status->value] ?? 0)]),
            ],
            'schedule' => $schedule->toArray(),
            'queue' => $queue->toArray(),
            'targetStrip' => $targetStrip,
            'targetStrips' => collect(self::TARGET_STRIPS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'targets' => $this->targetStrip($targetStrip)->limit(self::STRIP_SIZE)->get()->map(fn (Target $target) => [
                'id' => $target->id,
                'name' => $target->name,
                'type' => $target->type->value,
                'avatar_url' => $target->avatar_url,
                'status' => $target->status->value,
                'last_synced_at' => $target->last_synced_at?->toIso8601String(),
                'last_error' => $target->last_error,
                'repositories_count' => $target->repositories_count,
            ]),
            // Without the ordering: the "most repositories" order uses a count alias.
            'targetsTotal' => $this->targetStrip($targetStrip)->reorder()->count(),
            'targetsUrl' => route('targets.index', self::TARGET_STRIP_LIST_FILTERS[$targetStrip]),
            'strip' => $strip,
            'strips' => collect(self::STRIPS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'repositoryStrip' => $this->strip($strip)->with('target:id,name,avatar_url,status,clone_repositories')->limit(self::STRIP_SIZE)->get()
                ->map(fn (Repository $repository) => RepositoryListQuery::item($repository, withTarget: true)),
            'repositoryStripTotal' => $this->strip($strip)->count(),
            'repositoryStripUrl' => route('repositories.index', self::STRIP_LIST_FILTERS[$strip]),
            'latestErrors' => SyncRun::query()->with('target:id,name,avatar_url')->where('status', SyncRunStatus::Failed)
                ->latest('started_at')->limit(self::RUN_LIST_SIZE)->get()->map(fn (SyncRun $run) => SyncRunListQuery::item($run)),
            'history' => SyncRun::query()->with('target:id,name,avatar_url')->latest('started_at')->latest('id')
                ->limit(self::RUN_LIST_SIZE)->get()->map(fn (SyncRun $run) => SyncRunListQuery::item($run)),
            'syncing' => ($statusCounts[TargetStatus::Queued->value] ?? 0) + ($statusCounts[TargetStatus::Syncing->value] ?? 0) > 0,
        ]);
    }

    /**
     * The strip mode requested in the query string, or the default one.
     *
     * @param  array<string, string>  $modes
     */
    private function mode(Request $request, string $key, array $modes, string $default): string
    {
        $mode = $request->query($key);

        return is_string($mode) && array_key_exists($mode, $modes) ? $mode : $default;
    }

    /**
     * The targets shown in the dashboard strip for the given mode.
     *
     * @return Builder<Target>
     */
    private function targetStrip(string $mode): Builder
    {
        $query = Target::query()->withCount(['repositories' => fn (Builder $q) => $q->whereNull('excluded_at')]);

        return match ($mode) {
            'added' => $query->orderByDesc('created_at')->orderByDesc('id'),
            'repositories' => $query->orderByDesc('repositories_count')->orderBy('name'),
            'failed' => $query->where('status', TargetStatus::Failed)->orderByDesc('last_failed_at')->orderBy('name'),
            // Never synced targets last on every database (PostgreSQL sorts NULLs first descending).
            default => $query->orderByRaw('CASE WHEN last_synced_at IS NULL THEN 1 ELSE 0 END')->orderByDesc('last_synced_at')->orderBy('name'),
        };
    }

    /**
     * The repositories shown in the dashboard strip for the given mode.
     *
     * @return Builder<Repository>
     */
    private function strip(string $mode): Builder
    {
        $query = Repository::query()->whereNull('excluded_at');

        return match ($mode) {
            'stars' => $query->orderByDesc('stargazers_count'),
            'added' => $query->orderByDesc('created_at')->orderByDesc('id'),
            'removed' => $query->whereNotNull('missing_at')->orderByDesc('missing_at'),
            default => $query->whereNull('missing_at')->orderByRaw('CASE WHEN pushed_at IS NULL THEN 1 ELSE 0 END')->orderByDesc('pushed_at'),
        };
    }
}
