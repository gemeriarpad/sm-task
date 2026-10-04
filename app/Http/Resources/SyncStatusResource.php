<?php

namespace App\Http\Resources;

use App\Models\Target;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A target's sync state, for clients that trigger a sync and poll until it ends.
 *
 * @mixin Target
 */
class SyncStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'target_id' => $this->id,
            'status' => $this->status->value,
            // Queued or syncing: keep polling.
            'in_progress' => $this->status->isInProgress(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_failed_at' => $this->last_failed_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'latest_run' => $this->whenLoaded('latestSyncRun', fn () => $this->latestSyncRun ? new SyncRunResource($this->latestSyncRun) : null),
        ];
    }
}
