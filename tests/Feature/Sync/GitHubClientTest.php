<?php

namespace Tests\Feature\Sync;

use App\Enums\TargetType;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubClientTest extends TestCase
{
    public static function repo(int $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'name' => "repo-{$id}",
            'full_name' => "laravel/repo-{$id}",
            'description' => "Repository {$id}",
            'html_url' => "https://github.com/laravel/repo-{$id}",
            'language' => 'PHP',
            'stargazers_count' => 10,
            'forks_count' => 2,
            'open_issues_count' => 1,
            'fork' => false,
            'archived' => false,
            'updated_at' => '2026-09-01T10:00:00Z',
            'pushed_at' => '2026-08-31T09:00:00Z',
        ], $overrides);
    }

    private function client(): GitHubClient
    {
        return new GitHubClient(null);
    }

    public function test_it_follows_pagination_links_and_maps_repositories()
    {
        $next = 'https://api.github.com/organizations/958072/repos?type=public&per_page=100&page=2';

        Http::fake([
            'api.github.com/orgs/laravel/repos*' => Http::response([self::repo(1), self::repo(2)], 200, ['Link' => "<{$next}>; rel=\"next\", <{$next}>; rel=\"last\""]),
            'api.github.com/organizations/958072/repos*' => Http::response([self::repo(3, ['archived' => true, 'fork' => true, 'language' => null])]),
        ]);

        $repositories = iterator_to_array($this->client()->repositories(TargetType::Organization, 'laravel'), false);

        $this->assertCount(3, $repositories);
        $this->assertSame('laravel/repo-1', $repositories[0]->fullName);
        $this->assertSame('2026-08-31 09:00:00', $repositories[0]->pushedAt->toDateTimeString());
        $this->assertTrue($repositories[2]->archived);
        $this->assertTrue($repositories[2]->fork);
        $this->assertNull($repositories[2]->language);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/orgs/laravel/repos?type=public&per_page=100');
        // The next link is requested exactly as given, keeping its page parameter.
        Http::assertSent(fn (Request $request) => $request->url() === $next);
        Http::assertSentCount(2);
    }

    public function test_users_are_listed_through_the_users_endpoint()
    {
        Http::fake(['api.github.com/users/taylorotwell/repos*' => Http::response([self::repo(1)])]);

        iterator_to_array($this->client()->repositories(TargetType::User, 'taylorotwell'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/users/taylorotwell/repos?type=owner&per_page=100');
    }

    public function test_a_missing_account_throws_a_permanent_error()
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);

        try {
            iterator_to_array($this->client()->repositories(TargetType::Organization, 'taylorotwell'));
            $this->fail('Expected a GitHubException.');
        } catch (GitHubException $e) {
            $this->assertSame('Organization "taylorotwell" was not found on GitHub.', $e->getMessage());
            $this->assertFalse($e->retryable);
        }
    }

    public function test_server_errors_are_retryable()
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad Gateway'], 502)]);

        try {
            iterator_to_array($this->client()->repositories(TargetType::User, 'laravel'));
            $this->fail('Expected a GitHubException.');
        } catch (GitHubException $e) {
            $this->assertSame('GitHub is having problems right now (HTTP 502).', $e->getMessage());
            $this->assertTrue($e->retryable);
        }
    }

    public function test_rate_limits_are_retryable_after_the_reset()
    {
        $this->freezeTime();

        Http::fake(['api.github.com/*' => Http::response(['message' => 'API rate limit exceeded'], 403, [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) (time() + 120),
        ])]);

        try {
            iterator_to_array($this->client()->repositories(TargetType::User, 'laravel'));
            $this->fail('Expected a GitHubException.');
        } catch (GitHubException $e) {
            $this->assertTrue($e->retryable);
            $this->assertSame(120, $e->retryAfter);
        }
    }

    public function test_secondary_rate_limits_use_retry_after()
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'You have exceeded a secondary rate limit'], 403, ['Retry-After' => '60'])]);

        try {
            iterator_to_array($this->client()->repositories(TargetType::User, 'laravel'));
            $this->fail('Expected a GitHubException.');
        } catch (GitHubException $e) {
            $this->assertTrue($e->retryable);
            $this->assertSame(60, $e->retryAfter);
        }
    }

    public function test_connection_failures_are_retryable()
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            iterator_to_array($this->client()->repositories(TargetType::User, 'laravel'));
            $this->fail('Expected a GitHubException.');
        } catch (GitHubException $e) {
            $this->assertTrue($e->retryable);
            $this->assertSame('Could not reach the GitHub API (network error or timeout).', $e->getMessage());
            $this->assertStringContainsString('Operation timed out', $e->getPrevious()->getMessage());
        }
    }
}
