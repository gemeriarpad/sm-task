<?php

namespace Tests\Feature\Sync;

use App\Enums\RepositoryScope;
use App\Enums\TargetType;
use App\Models\Repository;
use App\Models\RepositoryPage;
use App\Models\Target;
use App\Services\GitHub\GitHubException;
use App\Services\RepositorySynchronizer;
use App\Services\SyncSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ETags per GitHub page: unchanged pages come back as 304 Not Modified, which costs
 * no rate limit (with a token) and no database writes.
 */
class ConditionalRequestsTest extends TestCase
{
    use RefreshDatabase;

    private const FIRST = 'https://api.github.com/orgs/laravel/repos?type=sources&per_page=100';

    private const SECOND = 'https://api.github.com/organizations/958072/repos?type=sources&per_page=100&page=2';

    private Target $target;

    /**
     * Pages GitHub serves: url => [etag, repositories]. A request carrying the
     * current ETag gets a 304.
     *
     * @var array<string, array{0: string, 1: list<array<string, mixed>>}>
     */
    private array $pages = [];

    /** @var list<Request> */
    private array $requests = [];

    private bool $secondPageFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn (Request $request) => $this->respond($request));

        $this->target = Target::factory()->create(['name' => 'laravel', 'type' => TargetType::Organization]);
    }

    private function respond(Request $request): mixed
    {
        $this->requests[] = $request;

        if ($this->secondPageFails && $request->url() === self::SECOND) {
            return Http::response(['message' => 'Bad Gateway'], 502);
        }

        [$etag, $repositories] = $this->pages[$request->url()];

        if ($request->header('If-None-Match') === [$etag]) {
            return Http::response(null, 304, ['ETag' => $etag]);
        }

        $headers = ['ETag' => $etag];

        if ($request->url() === self::FIRST && isset($this->pages[self::SECOND])) {
            $headers['Link'] = '<'.self::SECOND.'>; rel="next"';
        }

        return Http::response($repositories, 200, $headers);
    }

    private function sync(): SyncSummary
    {
        $this->requests = [];

        return app(RepositorySynchronizer::class)->sync($this->target);
    }

    /**
     * @return list<string|null>
     */
    private function sentEtags(): array
    {
        return array_map(fn (Request $request) => $request->header('If-None-Match')[0] ?? null, $this->requests);
    }

    public function test_a_complete_sync_remembers_each_page()
    {
        $this->pages = [
            self::FIRST => ['"p1"', [GitHubClientTest::repo(1), GitHubClientTest::repo(2)]],
            self::SECOND => ['"p2"', [GitHubClientTest::repo(3)]],
        ];

        $summary = $this->sync();

        $this->assertSame([3, 2, 0], [$summary->repositories, $summary->pages, $summary->notModifiedPages]);
        $first = RepositoryPage::where('url', 'orgs/laravel/repos?type=sources&per_page=100')->sole();
        $this->assertSame('"p1"', $first->etag);
        $this->assertSame(self::SECOND, $first->next_url);
        $this->assertSame([1, 2], $first->github_ids);
        $this->assertSame([3], RepositoryPage::where('url', self::SECOND)->sole()->github_ids);
    }

    public function test_unchanged_pages_are_not_downloaded_or_rewritten_again()
    {
        $this->pages = [self::FIRST => ['"p1"', [GitHubClientTest::repo(1, ['stargazers_count' => 5])]]];
        $this->sync();
        $repository = Repository::where('github_id', 1)->sole();
        $repository->toBase()->update(['updated_at' => now()->subDay()]);
        $this->travel(1)->hour();

        $summary = $this->sync();

        $this->assertSame(['"p1"'], $this->sentEtags());
        $this->assertSame([1, 1, 1], [$summary->repositories, $summary->pages, $summary->notModifiedPages]);
        $fresh = $repository->fresh();
        // Seen by this sync, so not marked missing, but not rewritten.
        $this->assertTrue($fresh->last_seen_at->isSameSecond(now()));
        $this->assertNull($fresh->missing_at);
        $this->assertTrue($fresh->updated_at->lt(now()->subHours(2)));
        $this->assertSame(5, $fresh->stargazers_count);
    }

    public function test_paging_continues_past_an_unchanged_page_and_changed_pages_still_mark_missing()
    {
        $this->pages = [
            self::FIRST => ['"p1"', [GitHubClientTest::repo(1)]],
            self::SECOND => ['"p2"', [GitHubClientTest::repo(2), GitHubClientTest::repo(3)]],
        ];
        $this->sync();

        // Page 2 changed: repository 3 is gone, 2 has new stars.
        $this->pages[self::SECOND] = ['"p2-new"', [GitHubClientTest::repo(2, ['stargazers_count' => 77])]];
        $this->travel(1)->hour();
        $summary = $this->sync();

        $this->assertSame(['"p1"', '"p2"'], $this->sentEtags());
        $this->assertSame([2, 2, 1], [$summary->repositories, $summary->pages, $summary->notModifiedPages]);
        $this->assertNull(Repository::where('github_id', 1)->sole()->missing_at);
        $this->assertSame(77, Repository::where('github_id', 2)->sole()->stargazers_count);
        $this->assertNotNull(Repository::where('github_id', 3)->sole()->missing_at);
        $this->assertSame('"p2-new"', RepositoryPage::where('url', self::SECOND)->sole()->etag);
    }

    public function test_a_page_with_an_excluded_repository_is_always_refetched()
    {
        $this->pages = [self::FIRST => ['"p1"', [GitHubClientTest::repo(1), GitHubClientTest::repo(2)]]];
        $this->sync();
        Repository::where('github_id', 2)->update(['excluded_at' => now()]);

        // GitHub data changes while the repository is excluded: it isn't written.
        $this->pages[self::FIRST] = ['"p1-new"', [GitHubClientTest::repo(1), GitHubClientTest::repo(2, ['stargazers_count' => 42])]];
        $this->sync();
        $this->assertNull(RepositoryPage::sole()->etag);

        // Included again: the page is fetched in full, so the row catches up.
        Repository::where('github_id', 2)->update(['excluded_at' => null]);
        $this->sync();

        $this->assertSame([null], $this->sentEtags());
        $this->assertSame(42, Repository::where('github_id', 2)->sole()->stargazers_count);
    }

    public function test_an_unchanged_page_whose_rows_are_gone_locally_is_fetched_in_full()
    {
        $this->pages = [self::FIRST => ['"p1"', [GitHubClientTest::repo(1), GitHubClientTest::repo(2)]]];
        $this->sync();
        // E.g. a deactivation that dropped the repositories but kept the target.
        $this->target->repositories()->delete();

        $summary = $this->sync();

        $this->assertSame(['"p1"', null], $this->sentEtags());
        $this->assertSame(0, $summary->notModifiedPages);
        $this->assertSame(2, $this->target->repositories()->count());
    }

    public function test_a_failed_sync_keeps_the_pages_of_the_last_complete_one()
    {
        $this->pages = [self::FIRST => ['"p1"', [GitHubClientTest::repo(1)]]];
        $this->sync();

        $this->pages[self::FIRST] = ['"p1-new"', [GitHubClientTest::repo(1)]];
        $this->pages[self::SECOND] = ['"p2"', [GitHubClientTest::repo(2)]];
        $this->secondPageFails = true;

        try {
            $this->sync();
            $this->fail('The sync should have failed.');
        } catch (GitHubException) {
            // Expected.
        }

        $this->assertSame('"p1"', RepositoryPage::sole()->etag);
    }

    public function test_pages_from_another_scope_are_not_reused()
    {
        // The users endpoint has the same URL for both scopes (forks are filtered here).
        $target = Target::factory()->create(['name' => 'pyr0hu', 'type' => TargetType::User, 'repository_scope' => RepositoryScope::All]);
        $url = 'https://api.github.com/users/pyr0hu/repos?type=owner&per_page=100';
        $this->pages = [$url => ['"u1"', [GitHubClientTest::repo(1), GitHubClientTest::repo(2, ['fork' => true])]]];
        app(RepositorySynchronizer::class)->sync($target);

        $target->update(['repository_scope' => RepositoryScope::Sources]);
        $this->requests = [];
        $summary = app(RepositorySynchronizer::class)->sync($target);

        $this->assertSame([null], $this->sentEtags());
        $this->assertSame(1, $summary->repositories);
        $this->assertSame([1], RepositoryPage::where('target_id', $target->id)->sole()->github_ids);
    }
}
