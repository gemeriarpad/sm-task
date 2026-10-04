<?php

namespace App\Models;

use App\Enums\CloneMode;
use App\Enums\CloneStatus;
use App\Enums\RepositoryScope;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Events\TargetSyncUpdated;
use App\Jobs\SyncTargetRepositories;
use Database\Factories\TargetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A GitHub user or organization whose public repositories are synchronized.
 */
class Target extends Model
{
    /** @use HasFactory<TargetFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'type',
        'repository_scope',
        'clone_repositories',
        'github_id',
        'avatar_url',
        'status',
        'deactivated_at',
        'last_synced_at',
        'github_repositories_count',
        'last_failed_at',
        'last_error',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        // Adding a target always queues its first sync.
        'status' => 'queued',
        'repository_scope' => 'sources',
        'clone_repositories' => false,
    ];

    /**
     * Broadcast sync state changes so open pages can refresh without polling.
     */
    protected static function booted(): void
    {
        static::created(fn (Target $target) => $target->broadcastSyncUpdate());

        static::updated(function (Target $target) {
            if ($target->wasChanged(['status', 'last_synced_at', 'last_error', 'name', 'type'])) {
                $target->broadcastSyncUpdate();
            }
        });
    }

    /**
     * Broadcast that the target's sync state changed. Live updates are a nicety:
     * if the broadcaster (Reverb) is down the pages fall back to polling, so a
     * failure here must never break a sync or a request.
     */
    public function broadcastSyncUpdate(): void
    {
        try {
            TargetSyncUpdated::dispatch($this);
        } catch (Throwable $e) {
            Log::debug('Could not broadcast the target sync update.', ['target_id' => $this->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TargetType::class,
            'repository_scope' => RepositoryScope::class,
            'clone_repositories' => 'boolean',
            'status' => TargetStatus::class,
            'deactivated_at' => 'datetime',
            'github_id' => 'integer',
            'last_synced_at' => 'datetime',
            'github_repositories_count' => 'integer',
            'last_failed_at' => 'datetime',
        ];
    }

    /**
     * The repositories synchronized for the target.
     *
     * @return HasMany<Repository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    /**
     * The sync job attempts for the target, newest first.
     *
     * @return HasMany<SyncRun, $this>
     */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class)->latest('started_at');
    }

    /**
     * The GitHub pages of the last complete sync, for conditional requests.
     *
     * @return HasMany<RepositoryPage, $this>
     */
    public function repositoryPages(): HasMany
    {
        return $this->hasMany(RepositoryPage::class);
    }

    /**
     * The most recent sync job attempt.
     *
     * @return HasOne<SyncRun, $this>
     */
    public function latestSyncRun(): HasOne
    {
        return $this->hasOne(SyncRun::class)->latestOfMany('started_at');
    }

    /**
     * Whether the target is synced at all (not deactivated).
     */
    public function isActive(): bool
    {
        return $this->status !== TargetStatus::Inactive;
    }

    /**
     * Whether a sync job is running right now. A "syncing" status older than the
     * job's unique lock is stale (e.g. the worker died).
     */
    public function isSyncing(): bool
    {
        return $this->status === TargetStatus::Syncing
            && (bool) $this->updated_at?->isAfter(now()->subSeconds(SyncTargetRepositories::UNIQUE_FOR));
    }

    /**
     * Queue a repository sync unless one is already queued or running, or the
     * target is deactivated.
     *
     * A status that has been "in progress" for longer than the job's unique lock is
     * treated as stale (e.g. a worker died) so the target can be synced again. The
     * job's unique lock is the second line of defence against duplicates.
     *
     * @param  bool  $force  Skip the status check, for a new target that starts as
     *                       "queued" but has no job yet.
     */
    public function queueSync(bool $force = false): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $staleAfter = now()->subSeconds(SyncTargetRepositories::UNIQUE_FOR);

        if (! $force && $this->status->isInProgress() && $this->updated_at?->isAfter($staleAfter)) {
            return false;
        }

        $this->update(['status' => TargetStatus::Queued]);

        SyncTargetRepositories::dispatch($this);

        return true;
    }

    /**
     * Queue a sync for each of the given targets, see queueSync().
     *
     * @param  Builder<Target>  $targets
     * @return array{queued: int, skipped: int}
     */
    public static function queueSyncs(Builder $targets): array
    {
        $counts = ['queued' => 0, 'skipped' => 0];

        $targets->each(function (Target $target) use (&$counts) {
            $counts[$target->queueSync() ? 'queued' : 'skipped']++;
        });

        return $counts;
    }

    /**
     * Repository counts shown for a target. The clone counts are split by mode so the
     * "enabled" number can follow the target's own setting (see clonesEnabledCount).
     *
     * @return array<string, \Closure>
     */
    public static function repositoryCounts(): array
    {
        $active = fn ($q) => $q->whereNull('excluded_at')->whereNull('missing_at');
        $cloned = fn ($q) => $active($q)->where('clone_status', CloneStatus::Cloned);

        return [
            // On GitHub and not excluded.
            'repositories as repositories_count' => $active,
            'repositories as excluded_count' => fn ($q) => $q->whereNotNull('excluded_at'),
            'repositories as clone_not_never_count' => fn ($q) => $active($q)->where('clone_mode', '!=', CloneMode::Never),
            'repositories as clone_always_count' => fn ($q) => $active($q)->where('clone_mode', CloneMode::Always),
            'repositories as cloned_not_never_count' => fn ($q) => $cloned($q)->where('clone_mode', '!=', CloneMode::Never),
            'repositories as cloned_always_count' => fn ($q) => $cloned($q)->where('clone_mode', CloneMode::Always),
        ];
    }

    /**
     * Load the repository counts of repositoryCounts() into the query.
     *
     * @param  Builder<Target>  $query
     */
    public function scopeWithRepositoryCounts(Builder $query): void
    {
        $query->withCount(self::repositoryCounts());
    }

    /**
     * Targets that are synced at all (not deactivated).
     *
     * @param  Builder<Target>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', '!=', TargetStatus::Inactive);
    }

    /**
     * The list filters shared by the targets table and the API.
     *
     * @param  Builder<Target>  $query
     * @param  array{name?: ?string, type?: ?string, status?: ?string}  $filters
     */
    public function scopeFilter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['name'] ?? null, fn (Builder $query, string $name) => $query->whereLike('name', "%{$name}%"))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status));
    }

    /**
     * Repositories with cloning enabled: "inherit" counts only when the target clones.
     */
    public function clonesEnabledCount(): int
    {
        return (int) ($this->clone_repositories ? $this->clone_not_never_count : $this->clone_always_count);
    }

    /**
     * Repositories with cloning enabled that have a finished clone.
     */
    public function clonesClonedCount(): int
    {
        return (int) ($this->clone_repositories ? $this->cloned_not_never_count : $this->cloned_always_count);
    }
}
