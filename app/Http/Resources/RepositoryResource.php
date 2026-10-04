<?php

namespace App\Http\Resources;

use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The GitHub data of a repository. The local clone is left out: it's a feature of
 * the web UI (file browser, zip download), not data for API clients.
 *
 * @mixin Repository
 */
class RepositoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'github_id' => $this->github_id,
            'name' => $this->name,
            'full_name' => $this->full_name,
            'description' => $this->description,
            'html_url' => $this->html_url,
            'language' => $this->language,
            'stargazers_count' => $this->stargazers_count,
            'forks_count' => $this->forks_count,
            'open_issues_count' => $this->open_issues_count,
            'fork' => $this->fork,
            'archived' => $this->archived,
            'github_updated_at' => $this->github_updated_at?->toIso8601String(),
            'pushed_at' => $this->pushed_at?->toIso8601String(),
            // When a sync last saw the repository on GitHub.
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            // Set when a complete sync no longer returned it.
            'missing_at' => $this->missing_at?->toIso8601String(),
            'excluded_at' => $this->excluded_at?->toIso8601String(),
            'target' => $this->whenLoaded('target', fn () => [
                'id' => $this->target->id,
                'name' => $this->target->name,
            ]),
            'links' => [
                'self' => route('api.v1.repositories.show', $this->resource),
            ],
        ];
    }
}
