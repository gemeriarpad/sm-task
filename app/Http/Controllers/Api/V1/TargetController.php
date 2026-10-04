<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TargetIndexRequest;
use App\Http\Resources\TargetResource;
use App\Models\Target;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;

class TargetController extends Controller
{
    /**
     * The targets, filtered, sorted and paginated.
     */
    public function index(TargetIndexRequest $request): AnonymousResourceCollection
    {
        $sort = $request->validated('sort') ?? 'name';
        $direction = $request->validated('direction') ?? ($sort === 'name' ? 'asc' : 'desc');

        $targets = Target::query()
            ->filter([
                'name' => $request->validated('search'),
                'type' => $request->validated('type'),
                'status' => $request->validated('status'),
            ])
            ->withCount(self::repositoriesCount())
            // Never synced targets last on every database (PostgreSQL sorts NULLs first descending).
            ->when($sort === 'last_synced_at', fn (Builder $query) => $query->orderByRaw('CASE WHEN last_synced_at IS NULL THEN 1 ELSE 0 END'))
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate((int) ($request->validated('per_page') ?? 25))
            ->withQueryString();

        return TargetResource::collection($targets);
    }

    /**
     * One target with its sync state and repository count.
     */
    public function show(Target $target): TargetResource
    {
        return new TargetResource($target->loadCount(self::repositoriesCount()));
    }

    /**
     * The same "repositories" count as the web UI: on GitHub and not excluded.
     *
     * @return array<string, \Closure>
     */
    private static function repositoriesCount(): array
    {
        return Arr::only(Target::repositoryCounts(), 'repositories as repositories_count');
    }
}
