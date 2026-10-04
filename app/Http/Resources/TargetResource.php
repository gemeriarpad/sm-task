<?php

namespace App\Http\Resources;

use App\Models\Target;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Target
 */
class TargetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'avatar_url' => $this->avatar_url,
            'status' => $this->status->value,
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_failed_at' => $this->last_failed_at?->toIso8601String(),
            'last_error' => $this->last_error,
            // Stored repositories still on GitHub, not counting excluded ones.
            'repositories_count' => $this->whenCounted('repositories'),
            // What GitHub returned in the last successful sync.
            'github_repositories_count' => $this->github_repositories_count,
            'created_at' => $this->created_at?->toIso8601String(),
            'links' => [
                'self' => route('api.v1.targets.show', $this->resource),
                'repositories' => route('api.v1.targets.repositories.index', $this->resource),
                'sync' => route('api.v1.targets.sync.show', $this->resource),
            ],
        ];
    }
}
