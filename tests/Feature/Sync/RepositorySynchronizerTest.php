<?php

namespace Tests\Feature\Sync;

use App\Enums\TargetType;
use App\Models\Repository;
use App\Models\Target;
use App\Services\GitHub\GitHubException;
use App\Services\RepositorySynchronizer;
use App\Services\SyncSummary;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RepositorySynchronizerTest extends TestCase
{
    use RefreshDatabase;

    private Target $target;

    /**
     * Answers the faked GitHub requests; swapped per sync run. (Calling Http::fake()
     * again would only append stubs behind the first ones.)
     */
    private Closure $github;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn (Request $request) => ($this->github)($request));

        $this->target = Target::factory()->create(['name' => 'laravel', 'type' => TargetType::Organization]);
    }

    /**
     * @param  list<array<string, mixed>>  $repositories
     */
    private function fakeGitHub(array $repositories): void
    {
        $this->github = fn () => Http::response($repositories);
    }

    private function sync(): SyncSummary
    {
        return app(RepositorySynchronizer::class)->sync($this->target);
    }

    public function test_new_repositories_are_created()
    {
        $this->fakeGitHub([GitHubClientTest::repo(1), GitHubClientTest::repo(2, ['archived' => true])]);

        $this->assertSame(2, $this->sync()->repositories);

        $this->assertDatabaseCount('repositories', 2);
        $repository = Repository::where('github_id', 2)->sole();
        $this->assertSame($this->target->id, $repository->target_id);
        $this->assertSame('laravel/repo-2', $repository->full_name);
        $this->assertTrue($repository->archived);
        $this->assertSame('2026-09-01 10:00:00', $repository->github_updated_at->toDateTimeString());
        $this->assertNull($repository->missing_at);
    }

    public function test_known_repositories_are_updated_without_duplicates()
    {
        $this->fakeGitHub([GitHubClientTest::repo(1, ['stargazers_count' => 10])]);
        $this->sync();
        $created = Repository::sole();

        $this->fakeGitHub([GitHubClientTest::repo(1, ['name' => 'renamed', 'stargazers_count' => 99])]);
        $this->travel(1)->minute();
        $this->sync();

        $this->assertDatabaseCount('repositories', 1);
        $updated = Repository::sole();
        $this->assertSame($created->id, $updated->id);
        $this->assertSame('renamed', $updated->name);
        $this->assertSame(99, $updated->stargazers_count);
    }

    public function test_repositories_no_longer_returned_are_marked_missing_and_restored_when_back()
    {
        $this->fakeGitHub([GitHubClientTest::repo(1), GitHubClientTest::repo(2)]);
        $this->sync();

        $this->fakeGitHub([GitHubClientTest::repo(1)]);
        $this->travel(1)->minute();
        $this->sync();
        $this->assertNull(Repository::where('github_id', 1)->sole()->missing_at);
        $this->assertNotNull(Repository::where('github_id', 2)->sole()->missing_at);

        $this->fakeGitHub([GitHubClientTest::repo(1), GitHubClientTest::repo(2)]);
        $this->travel(1)->minute();
        $this->sync();
        $this->assertNull(Repository::where('github_id', 2)->sole()->missing_at);
    }

    public function test_a_failed_sync_never_marks_repositories_missing()
    {
        $this->fakeGitHub([GitHubClientTest::repo(1), GitHubClientTest::repo(2)]);
        $this->sync();

        // The first page succeeds, the second one fails.
        $next = 'https://api.github.com/organizations/958072/repos?page=2';
        $this->github = fn (Request $request) => $request->url() === $next
            ? Http::response(['message' => 'Server Error'], 500)
            : Http::response([GitHubClientTest::repo(1)], 200, ['Link' => "<{$next}>; rel=\"next\""]);

        $this->travel(1)->minute();

        try {
            $this->sync();
            $this->fail('Expected a GitHubException.');
        } catch (GitHubException) {
            //
        }

        $this->assertSame(0, Repository::whereNotNull('missing_at')->count());
    }

    public function test_repositories_of_other_targets_are_left_alone()
    {
        $other = Repository::factory()->create();

        $this->fakeGitHub([GitHubClientTest::repo(1)]);
        $this->travel(1)->minute();
        $this->sync();

        $this->assertNull($other->fresh()->missing_at);
    }

    public function test_a_repository_transferred_between_targets_moves_instead_of_duplicating()
    {
        $repository = Repository::factory()->create(['github_id' => 1]);

        $this->fakeGitHub([GitHubClientTest::repo(1)]);
        $this->sync();

        $this->assertDatabaseCount('repositories', 1);
        $this->assertSame($this->target->id, $repository->fresh()->target_id);
    }

    public function test_the_database_rejects_duplicate_github_ids()
    {
        Repository::factory()->create(['github_id' => 1]);

        $this->expectException(UniqueConstraintViolationException::class);

        Repository::factory()->create(['github_id' => 1]);
    }

    /**
     * Answer the account lookup and the repository list of "laravel".
     */
    private function fakeAccountAndRepositories(int $accountId): void
    {
        $this->github = fn (Request $request) => str_contains($request->url(), '/users/laravel')
            ? Http::response(['id' => $accountId, 'login' => 'laravel', 'type' => 'Organization', 'avatar_url' => "https://avatars.githubusercontent.com/u/{$accountId}?v=4", 'html_url' => 'https://github.com/laravel'])
            : Http::response([GitHubClientTest::repo(1)]);
    }

    public function test_a_target_added_without_its_account_details_gets_them_from_the_sync()
    {
        $this->target->update(['github_id' => null, 'avatar_url' => null]);
        $this->fakeAccountAndRepositories(958072);

        $this->sync();

        $this->target->refresh();
        $this->assertSame(958072, $this->target->github_id);
        $this->assertSame('https://avatars.githubusercontent.com/u/958072?v=4', $this->target->avatar_url);
        $this->assertDatabaseCount('repositories', 1);
    }

    public function test_the_account_is_only_looked_up_while_its_details_are_missing()
    {
        $this->fakeAccountAndRepositories(958072);

        $this->sync();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/users/laravel'));
    }

    public function test_an_account_id_held_by_another_target_is_not_taken_over()
    {
        Target::factory()->create(['name' => 'laravel-old', 'github_id' => 958072]);
        $this->target->update(['github_id' => null, 'avatar_url' => null]);
        $this->fakeAccountAndRepositories(958072);

        $this->sync();

        $this->target->refresh();
        $this->assertNull($this->target->github_id);
        $this->assertSame('https://avatars.githubusercontent.com/u/958072?v=4', $this->target->avatar_url);
    }
}
