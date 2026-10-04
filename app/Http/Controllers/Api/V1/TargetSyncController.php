<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SyncStatusResource;
use App\Models\Target;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trigger syncs from outside (a CI job, a cron, another backend) and poll them.
 *
 * Triggering is idempotent: a target already queued or syncing isn't queued twice
 * (see Target::queueSync), and the response says whether this request queued it.
 */
class TargetSyncController extends Controller
{
    /**
     * Seconds a client should wait between polls while a sync is in progress.
     */
    public const POLL_INTERVAL = 5;

    /**
     * The target's sync state, for polling.
     */
    public function show(Target $target): JsonResponse
    {
        return $this->status($target, Response::HTTP_OK);
    }

    /**
     * Queue a sync of the target. 202 Accepted: the sync runs in the background.
     */
    public function store(Target $target): JsonResponse
    {
        if (! $target->isActive()) {
            return response()->json(['message' => "The target \"{$target->name}\" is deactivated. Reactivate it to sync it again."], Response::HTTP_CONFLICT);
        }

        $queued = $target->queueSync();

        return $this->status($target, Response::HTTP_ACCEPTED, ['queued' => $queued])
            ->header('Location', route('api.v1.targets.sync.show', $target));
    }

    /**
     * Queue a sync of every active target.
     */
    public function storeAll(): JsonResponse
    {
        return response()->json(['data' => Target::queueSyncs(Target::query()->active())], Response::HTTP_ACCEPTED);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function status(Target $target, int $code, array $meta = []): JsonResponse
    {
        $response = (new SyncStatusResource($target->load('latestSyncRun')))
            ->additional($meta === [] ? [] : ['meta' => $meta])
            ->response()
            ->setStatusCode($code);

        // Tell pollers when to ask again.
        if ($target->status->isInProgress()) {
            $response->header('Retry-After', (string) self::POLL_INTERVAL);
        }

        return $response;
    }
}
