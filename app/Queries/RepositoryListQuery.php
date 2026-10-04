<?php

namespace App\Queries;

use App\Models\Repository;
use App\Models\Target;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The search, filters, sorting and pagination of a repository list, shared by the
 * target page (one target) and the repositories page (all targets).
 */
class RepositoryListQuery
{
    /**
     * Sort options: key => [column, direction, label].
     */
    public const SORTS = [
        'pushed' => ['pushed_at', 'desc', 'Last pushed'],
        'stars' => ['stargazers_count', 'desc', 'Most stars'],
        'issues' => ['open_issues_count', 'desc', 'Most open issues'],
        'name' => ['name', 'asc', 'Name'],
    ];

    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public const DEFAULT_PER_PAGE = 25;

    /**
     * @param  array{search: string, sort: string, language: string, archived: string, fork: string, missing: string, excluded: string, target: string}  $filters
     */
    private function __construct(
        public readonly array $filters,
        public readonly int $perPage,
    ) {}

    /**
     * Read and sanitize the list parameters from the query string.
     */
    public static function fromRequest(Request $request): self
    {
        $string = function (string $key) use ($request): string {
            $value = $request->query($key);

            // Ignore arrays like `?sort[]=x`.
            return is_string($value) ? trim($value) : '';
        };
        $toggle = fn (string $key): string => in_array($string($key), ['only', 'hide'], true) ? $string($key) : '';
        $perPage = (int) $string('per_page');

        return new self(
            filters: [
                'search' => $string('search'),
                'sort' => array_key_exists($string('sort'), self::SORTS) ? $string('sort') : 'pushed',
                'language' => $string('language'),
                'archived' => $toggle('archived'),
                'fork' => $toggle('fork'),
                'missing' => $toggle('missing'),
                // Excluded repositories are hidden unless asked for: '' (hide), 'only' or 'include'.
                'excluded' => in_array($string('excluded'), ['only', 'include'], true) ? $string('excluded') : '',
                'target' => ctype_digit($string('target')) ? $string('target') : '',
            ],
            perPage: in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE,
        );
    }

    /**
     * Apply the filters and sorting to a repository query.
     *
     * @param  Builder<Repository>  $query
     * @return Builder<Repository>
     */
    public function apply(Builder $query): Builder
    {
        [$column, $direction] = self::SORTS[$this->filters['sort']];

        return $query
            ->when($this->filters['search'], fn (Builder $q, string $search) => $q->where(
                fn (Builder $q) => $q->whereLike('name', "%{$search}%")->orWhereLike('description', "%{$search}%"),
            ))
            ->when($this->filters['language'], fn (Builder $q, string $language) => $q->where('language', $language))
            ->when($this->filters['archived'], fn (Builder $q, string $mode) => $q->where('archived', $mode === 'only'))
            ->when($this->filters['fork'], fn (Builder $q, string $mode) => $q->where('fork', $mode === 'only'))
            ->when($this->filters['missing'], fn (Builder $q, string $mode) => $mode === 'only' ? $q->whereNotNull('missing_at') : $q->whereNull('missing_at'))
            ->when($this->filters['target'], fn (Builder $q, string $target) => $q->where('target_id', (int) $target))
            ->when(
                $this->filters['excluded'] !== 'include',
                fn (Builder $q) => $this->filters['excluded'] === 'only' ? $q->whereNotNull('excluded_at') : $q->whereNull('excluded_at'),
            )
            // Empty values (e.g. never pushed) last on every database: PostgreSQL would
            // otherwise put NULLs first when sorting descending.
            ->orderByRaw("CASE WHEN {$column} IS NULL THEN 1 ELSE 0 END")
            ->orderBy($column, $direction)
            ->orderBy('id');
    }

    /**
     * The filtered, sorted and paginated repositories, serialized for the list.
     *
     * @param  Builder<Repository>  $query
     */
    public function paginate(Builder $query, bool $withTarget = false): LengthAwarePaginator
    {
        return $this->apply($query)
            // The target's clone setting decides what "follows the target" means.
            ->with('target:id,name,avatar_url,status,clone_repositories')
            ->paginate($this->perPage)
            ->withQueryString()
            ->through(fn (Repository $repository): array => self::item($repository, $withTarget));
    }

    /**
     * Select options for the list toolbar.
     *
     * @param  Builder<Repository>  $scope  The repositories the language options come from.
     * @return array<string, Collection<int, array{value: string, label: string}>>
     */
    public static function options(Builder $scope, bool $withTargets = false): array
    {
        $options = [
            'sorts' => collect(self::SORTS)->map(fn (array $sort, string $key) => ['value' => $key, 'label' => $sort[2]])->values(),
            'languages' => $scope->whereNotNull('language')->distinct()->orderBy('language')->pluck('language')
                ->map(fn (string $language) => ['value' => $language, 'label' => $language]),
        ];

        if ($withTargets) {
            $options['targets'] = Target::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Target $target) => ['value' => (string) $target->id, 'label' => $target->name]);
        }

        return $options;
    }

    /**
     * Serialize a repository for a list.
     *
     * @return array<string, mixed>
     */
    public static function item(Repository $repository, bool $withTarget = false): array
    {
        return [
            'id' => $repository->id,
            'name' => $repository->name,
            'full_name' => $repository->full_name,
            'description' => $repository->description,
            'html_url' => $repository->html_url,
            'language' => $repository->language,
            'stargazers_count' => $repository->stargazers_count,
            'forks_count' => $repository->forks_count,
            'open_issues_count' => $repository->open_issues_count,
            'fork' => $repository->fork,
            'archived' => $repository->archived,
            'github_updated_at' => $repository->github_updated_at?->toIso8601String(),
            'pushed_at' => $repository->pushed_at?->toIso8601String(),
            // When a sync last saw the repository on GitHub.
            'last_seen_at' => $repository->last_seen_at?->toIso8601String(),
            'missing_at' => $repository->missing_at?->toIso8601String(),
            'excluded_at' => $repository->excluded_at?->toIso8601String(),
            'clone' => [
                'mode' => $repository->clone_mode->value,
                'enabled' => $repository->shouldClone(),
                'status' => $repository->clone_status?->value,
                // Null follows the default branch.
                'branch' => $repository->clone_branch,
                'cloned_branch' => $repository->cloned_branch,
                // A local clone exists (cloned once and not deleted since).
                'available' => $repository->cloned_at !== null,
                // "Clone now" / "Update now" can queue a sync for it (see requestClone()).
                'requestable' => $repository->missing_at === null && $repository->shouldClone() && $repository->target->isActive(),
            ],
            'target' => $withTarget ? [
                'id' => $repository->target->id,
                'name' => $repository->target->name,
                'avatar_url' => $repository->target->avatar_url,
                'status' => $repository->target->status->value,
            ] : null,
        ];
    }
}
