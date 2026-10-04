<?php

namespace App\Models;

use App\Enums\SyncRunStatus;
use Database\Factories\SyncRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt of a repository sync job for a target.
 */
class SyncRun extends Model
{
    /** @use HasFactory<SyncRunFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'target_id',
        'attempt',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'repositories_count',
        'clones_count',
        'clone_errors_count',
        'clones_deferred_count',
        'error',
        'will_retry',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SyncRunStatus::class,
            'attempt' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'repositories_count' => 'integer',
            'clones_count' => 'integer',
            'clone_errors_count' => 'integer',
            'clones_deferred_count' => 'integer',
            'will_retry' => 'boolean',
        ];
    }

    /**
     * The target that was synchronized.
     *
     * @return BelongsTo<Target, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    /**
     * Runs that ended with an error: failed attempts, and syncs that succeeded but
     * failed to clone some repositories.
     *
     * @param  Builder<SyncRun>  $query
     */
    public function scopeErrored(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('status', SyncRunStatus::Failed)->orWhere('clone_errors_count', '>', 0));
    }

    /**
     * Mark the attempt as finished.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function finish(SyncRunStatus $status, array $attributes = []): void
    {
        $this->update([
            'status' => $status,
            'finished_at' => now(),
            'duration_ms' => (int) round($this->started_at->diffInMilliseconds(now(), absolute: true)),
            ...$attributes,
        ]);
    }
}
