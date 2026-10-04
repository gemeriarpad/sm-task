<?php

namespace App\Queries;

use App\Enums\SyncRunStatus;
use App\Models\SyncRun;
use App\Models\Target;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filters, sorting and serialization of a sync run table, shared by the sync
 * history and error pages (the dashboard reuses the serialization).
 */
class SyncRunListQuery
{
    /**
     * Columns the tables may be sorted by.
     */
    public const SORTABLE = ['started_at', 'target', 'attempt', 'status', 'duration_ms', 'repositories_count'];

    /**
     * Sortable columns that are empty while a run is still going.
     */
    private const NULLABLE = ['duration_ms', 'repositories_count'];

    /**
     * Drop filter values that can't match anything (e.g. `filter[status]=x`), so the
     * page echoes back only the filters actually applied.
     *
     * @param  array<string, string>  $filters
     * @return array<string, string>
     */
    public static function sanitize(array $filters): array
    {
        return array_filter($filters, fn (string $value, string $key) => match ($key) {
            'target' => ctype_digit($value),
            'status' => SyncRunStatus::tryFrom($value) !== null,
            'retrying' => in_array($value, ['yes', 'no'], true),
            default => true,
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Apply the filters and sorting to a sync run query.
     *
     * @param  Builder<SyncRun>  $query
     * @param  array<string, string>  $filters  Sanitized filters.
     * @return Builder<SyncRun>
     */
    public static function apply(Builder $query, array $filters, string $sort, string $direction): Builder
    {
        return $query
            ->when($filters['target'] ?? null, fn (Builder $q, string $target) => $q->where('target_id', (int) $target))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['retrying'] ?? null, fn (Builder $q, string $retrying) => $q->where('will_retry', $retrying === 'yes'))
            ->when($filters['error'] ?? null, fn (Builder $q, string $error) => $q->whereLike('error', "%{$error}%"))
            // Empty values (a run still going) last on every database: PostgreSQL would
            // otherwise put NULLs first when sorting descending.
            ->when(in_array($sort, self::NULLABLE, true), fn (Builder $q) => $q->orderByRaw("CASE WHEN {$sort} IS NULL THEN 1 ELSE 0 END"))
            ->when(
                $sort === 'target',
                fn (Builder $q) => $q->orderBy(Target::select('name')->whereColumn('targets.id', 'sync_runs.target_id'), $direction),
                fn (Builder $q) => $q->orderBy($sort, $direction),
            )
            // Newest first within equal values.
            ->when($sort !== 'started_at', fn (Builder $q) => $q->orderByDesc('started_at'))
            ->orderByDesc('id');
    }

    /**
     * Serialize a sync run for a list.
     *
     * @return array<string, mixed>
     */
    public static function item(SyncRun $run): array
    {
        return [
            'id' => $run->id,
            'attempt' => $run->attempt,
            'status' => $run->status->value,
            'started_at' => $run->started_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'duration_ms' => $run->duration_ms,
            'repositories_count' => $run->repositories_count,
            'clones_count' => $run->clones_count,
            'clone_errors_count' => $run->clone_errors_count,
            'clones_deferred_count' => $run->clones_deferred_count,
            'error' => $run->error,
            'will_retry' => $run->will_retry,
            'target' => ['id' => $run->target->id, 'name' => $run->target->name, 'avatar_url' => $run->target->avatar_url],
        ];
    }
}
