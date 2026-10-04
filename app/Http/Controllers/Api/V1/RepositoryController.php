<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RepositoryIndexRequest;
use App\Http\Resources\RepositoryResource;
use App\Models\Repository;
use App\Models\Target;
use App\Queries\RepositoryListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RepositoryController extends Controller
{
    /**
     * The repositories of every target, with the web UI's search, filters and sorts.
     */
    public function index(RepositoryIndexRequest $request): AnonymousResourceCollection
    {
        return $this->list($request, Repository::query());
    }

    /**
     * The repositories of one target.
     */
    public function forTarget(RepositoryIndexRequest $request, Target $target): AnonymousResourceCollection
    {
        return $this->list($request, $target->repositories()->getQuery());
    }

    public function show(Repository $repository): RepositoryResource
    {
        return new RepositoryResource($repository->load('target:id,name'));
    }

    /**
     * @param  Builder<Repository>  $query
     */
    private function list(RepositoryIndexRequest $request, Builder $query): AnonymousResourceCollection
    {
        $list = RepositoryListQuery::fromRequest($request);

        return RepositoryResource::collection(
            $list->apply($query)->with('target:id,name')->paginate($list->perPage)->withQueryString(),
        );
    }
}
