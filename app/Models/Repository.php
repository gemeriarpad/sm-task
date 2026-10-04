<?php

namespace App\Models;

use App\Enums\CloneMode;
use App\Enums\CloneStatus;
use App\Enums\TargetStatus;
use App\Events\RepositoryCloneUpdated;
use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A public GitHub repository owned by a target.
 */
class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory;

    /**
     * Branch names accepted for cloning: a safe subset of git's ref name rules (no
     * leading "-", "/" or ".", no "..", "//", "@{" or trailing "/", "." or ".lock").
     */
    public const BRANCH_PATTERN = '#^(?![-/.])(?!.*(?:\.\.|//|@\{|/\.))[A-Za-z0-9._/@+=,%-]+(?<![/.])(?<!\.lock)$#';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'target_id',
        'github_id',
        'name',
        'full_name',
        'description',
        'html_url',
        'language',
        'stargazers_count',
        'forks_count',
        'open_issues_count',
        'size_kb',
        'default_branch',
        'fork',
        'archived',
        'github_updated_at',
        'pushed_at',
        'last_seen_at',
        'missing_at',
        'excluded_at',
        'clone_mode',
        'clone_branch',
        'clone_status',
        'cloned_commit',
        'cloned_branch',
        'cloned_at',
        'clone_error',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'clone_mode' => 'inherit',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'github_id' => 'integer',
            'stargazers_count' => 'integer',
            'forks_count' => 'integer',
            'open_issues_count' => 'integer',
            'size_kb' => 'integer',
            'fork' => 'boolean',
            'archived' => 'boolean',
            'github_updated_at' => 'datetime',
            'pushed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'missing_at' => 'datetime',
            'excluded_at' => 'datetime',
            'clone_mode' => CloneMode::class,
            'clone_status' => CloneStatus::class,
            'cloned_at' => 'datetime',
        ];
    }

    /**
     * Broadcast clone state changes so an open repository page can refresh.
     */
    protected static function booted(): void
    {
        static::updated(function (Repository $repository) {
            if ($repository->wasChanged(['clone_status', 'cloned_at', 'clone_mode', 'clone_branch'])) {
                try {
                    RepositoryCloneUpdated::dispatch($repository);
                } catch (Throwable $e) {
                    Log::debug('Could not broadcast the repository clone update.', ['repository_id' => $repository->id, 'error' => $e->getMessage()]);
                }
            }
        });
    }

    /**
     * The target that owns the repository.
     *
     * @return BelongsTo<Target, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    /**
     * Repositories whose clone failed or was skipped as too large, while cloning them
     * is still wanted: not excluded, still on GitHub, cloning enabled (see shouldClone())
     * and the target active. Fixing the cause (or not wanting the clone anymore) removes
     * a repository from this list.
     *
     * @param  Builder<Repository>  $query
     */
    public function scopeCloneProblems(Builder $query): void
    {
        $query->whereIn('clone_status', [CloneStatus::Failed, CloneStatus::Skipped])
            ->whereNull('excluded_at')
            ->whereNull('missing_at')
            ->where('clone_mode', '!=', CloneMode::Never)
            ->whereHas('target', fn (Builder $target) => $target->where('status', '!=', TargetStatus::Inactive))
            ->where(fn (Builder $q) => $q->where('clone_mode', CloneMode::Always)
                ->orWhereHas('target', fn (Builder $target) => $target->where('clone_repositories', true)));
    }

    /**
     * Whether the repository should be cloned: never when excluded, otherwise its
     * own mode wins and "inherit" follows the target's setting.
     */
    public function shouldClone(): bool
    {
        if ($this->excluded_at !== null) {
            return false;
        }

        return match ($this->clone_mode) {
            CloneMode::Always => true,
            CloneMode::Never => false,
            CloneMode::Inherit => (bool) $this->target?->clone_repositories,
        };
    }

    /**
     * Whether the repository is larger than the configured clone limit.
     */
    public function exceedsCloneLimit(): bool
    {
        return $this->size_kb !== null && $this->size_kb > config('services.github.clone_max_size_mb') * 1024;
    }

    /**
     * The branch to clone: the chosen one, or the repository's default branch.
     */
    public function cloneBranch(): ?string
    {
        return $this->clone_branch ?? $this->default_branch;
    }

    /**
     * Have the target's sync clone or update this repository right away: mark it
     * queued and queue a sync (a sync that is already running picks it up before it
     * finishes). Repositories removed from GitHub or excluded keep their clone as is.
     */
    public function requestClone(): bool
    {
        if ($this->missing_at !== null || ! $this->shouldClone() || ! $this->target->isActive()) {
            return false;
        }

        if ($this->clone_status !== CloneStatus::Cloning) {
            $this->update(['clone_status' => CloneStatus::Queued]);
        }

        $this->target->queueSync();

        return true;
    }
}
