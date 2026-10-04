<?php

namespace App\Services;

use App\Models\Repository;
use App\Models\RepositoryPage;
use App\Models\Target;
use App\Services\Git\RepositoryCloner;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubException;
use App\Services\GitHub\GitHubRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors a target's public GitHub repositories into the local database.
 */
class RepositorySynchronizer
{
    /**
     * Columns refreshed from GitHub on every sync of an already known repository.
     */
    private const UPDATED_COLUMNS = [
        'target_id',
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
        'updated_at',
    ];

    public function __construct(
        private readonly GitHubClient $github,
        private readonly RepositoryCloner $cloner,
    ) {}

    /**
     * Create new repositories, update known ones, drop the ones outside the target's
     * scope and mark the ones GitHub no longer returns as missing. Excluded
     * repositories are left untouched.
     *
     * Pages unchanged since the last complete sync are requested conditionally and
     * come back as 304 Not Modified (see GitHubClient::repositoryPages()).
     *
     * @throws GitHubException
     */
    public function sync(Target $target): SyncSummary
    {
        $this->fillAccount($target);

        // Every repository returned by this run gets exactly this timestamp, so after a
        // complete run anything seen earlier than it was not returned anymore.
        $startedAt = Carbon::now();
        $seen = 0;
        $batch = [];
        $unchanged = [];
        $pages = [];
        $notModified = 0;
        $excluded = Repository::query()->whereNotNull('excluded_at')->pluck('github_id')->flip();
        $cached = $target->repositoryPages()->where('scope', $target->repository_scope)->get()->keyBy('url');
        $known = $cached
            ->filter(fn (RepositoryPage $page) => $page->etag !== null)
            ->map(fn (RepositoryPage $page) => ['etag' => $page->etag, 'next_url' => $page->next_url])
            ->all();

        foreach ($this->github->repositoryPages($target->type, $target->name, $target->repository_scope, $known) as $page) {
            if ($page->notModified) {
                $ids = $cached[$page->url]->github_ids;

                if ($this->allStored($target, $ids)) {
                    $notModified++;
                    $seen += count($ids);
                    array_push($unchanged, ...$ids);
                    $pages[] = $this->pageRow($target, $page->url, $page->etag, $page->nextUrl, $ids);

                    continue;
                }

                // Rows of an "unchanged" page are gone locally (e.g. dropped while the
                // target was deactivated): fetch the page in full to recreate them.
                $page = $this->github->repositoryPage($target->type, $target->name, $target->repository_scope, $page->url);
            }

            $ids = [];
            $holdsExcluded = false;

            foreach ($page->repositories as $repository) {
                $seen++;
                $ids[] = $repository->id;

                // Excluded repositories are frozen: never updated (nor marked missing).
                if ($excluded->has($repository->id)) {
                    $holdsExcluded = true;

                    continue;
                }

                $batch[] = $this->row($target, $repository, $startedAt);

                if (count($batch) === 100) {
                    $this->upsert($batch);
                    $batch = [];
                }
            }

            // A page holding an excluded repository is always refetched: the excluded
            // row isn't written, so once it is included again a 304 would keep it stale.
            $pages[] = $this->pageRow($target, $page->url, $holdsExcluded ? null : $page->etag, $page->nextUrl, $ids);
        }

        $this->upsert($batch);

        // Unchanged pages hold the same repositories with the same data as last time:
        // only record that they were seen, so they aren't marked missing below.
        foreach (array_chunk($unchanged, 500) as $chunk) {
            Repository::query()->whereIn('github_id', $chunk)->whereNull('excluded_at')->toBase()->update(['last_seen_at' => $startedAt]);
        }

        // Only reached when every page was fetched; a failed run throws above and
        // never deletes or marks anything.
        $this->deleteOutOfScope($target);

        $target->repositories()
            ->whereNull('missing_at')
            ->whereNull('excluded_at')
            ->where('last_seen_at', '<', $startedAt)
            ->update(['missing_at' => $startedAt, 'updated_at' => $startedAt]);

        $this->rememberPages($target, $pages);

        return new SyncSummary($seen, count($pages), $notModified);
    }

    /**
     * Fill in the GitHub id and avatar of a target added without them (seeded, created
     * over the API, or added while the form's GitHub check failed): one account lookup,
     * only while they are missing. A missing account is left to the repository request,
     * which reports it.
     *
     * @throws GitHubException
     */
    private function fillAccount(Target $target): void
    {
        if ($target->github_id !== null && $target->avatar_url !== null) {
            return;
        }

        $account = $this->github->account($target->name);

        if ($account === null) {
            return;
        }

        // The id is unique: another target may hold the same account under its old name.
        $taken = Target::query()->where('github_id', $account->id)->whereKeyNot($target->id)->exists();

        $target->update([
            'avatar_url' => $account->avatarUrl,
            ...($taken ? [] : ['github_id' => $account->id]),
        ]);
    }

    /**
     * Repositories outside the target's scope (forks, when it syncs sources only)
     * were never removed from GitHub, so they are deleted rather than marked missing,
     * together with their clones.
     */
    private function deleteOutOfScope(Target $target): void
    {
        if ($target->repository_scope->includesForks()) {
            return;
        }

        $target->repositories()->where('fork', true)->get()->each(function (Repository $repository) {
            $this->cloner->delete($repository);
            $repository->delete();
        });
    }

    /**
     * Whether every repository of a page GitHub reported as unchanged is still stored
     * for the target.
     *
     * @param  list<int>  $ids
     */
    private function allStored(Target $target, array $ids): bool
    {
        return $ids === [] || $target->repositories()->whereIn('github_id', $ids)->count() === count($ids);
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function pageRow(Target $target, string $url, ?string $etag, ?string $nextUrl, array $ids): array
    {
        return ['scope' => $target->repository_scope, 'url' => $url, 'etag' => $etag, 'next_url' => $nextUrl, 'github_ids' => $ids];
    }

    /**
     * Replace the target's stored pages with the ones of this complete sync. A failed
     * sync never gets here, so the previous pages (still valid) stay.
     *
     * @param  list<array<string, mixed>>  $pages
     */
    private function rememberPages(Target $target, array $pages): void
    {
        DB::transaction(function () use ($target, $pages) {
            $target->repositoryPages()->delete();
            $target->repositoryPages()->createMany($pages);
        });
    }

    /**
     * Insert or update a batch of repositories keyed on the unique GitHub id.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsert(array $rows): void
    {
        if ($rows !== []) {
            Repository::upsert($rows, uniqueBy: ['github_id'], update: self::UPDATED_COLUMNS);
        }
    }

    /**
     * Map a GitHub repository onto a row of the repositories table.
     *
     * @return array<string, mixed>
     */
    private function row(Target $target, GitHubRepository $repository, Carbon $seenAt): array
    {
        return [
            'target_id' => $target->id,
            'github_id' => $repository->id,
            'name' => $repository->name,
            'full_name' => $repository->fullName,
            'description' => $repository->description,
            'html_url' => $repository->htmlUrl,
            'language' => $repository->language,
            'stargazers_count' => $repository->stargazersCount,
            'forks_count' => $repository->forksCount,
            'open_issues_count' => $repository->openIssuesCount,
            'size_kb' => $repository->sizeKb,
            'default_branch' => $repository->defaultBranch,
            'fork' => $repository->fork,
            'archived' => $repository->archived,
            'github_updated_at' => $repository->updatedAt?->utc()->toDateTimeString(),
            'pushed_at' => $repository->pushedAt?->utc()->toDateTimeString(),
            'last_seen_at' => $seenAt->toDateTimeString(),
            // A repository that shows up again is no longer missing.
            'missing_at' => null,
        ];
    }
}
