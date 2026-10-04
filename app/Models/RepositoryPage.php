<?php

namespace App\Models;

use App\Enums\RepositoryScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One GitHub page of a target's repository list as of the last complete sync: its
 * ETag for conditional requests, the next page's URL and the repositories on it.
 *
 * @property list<int> $github_ids
 */
class RepositoryPage extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'target_id',
        'scope',
        'url',
        'etag',
        'next_url',
        'github_ids',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => RepositoryScope::class,
            'github_ids' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Target, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }
}
