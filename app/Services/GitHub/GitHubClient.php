<?php

namespace App\Services\GitHub;

use App\Enums\RepositoryScope;
use App\Enums\TargetType;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the GitHub REST API.
 */
class GitHubClient
{
    /**
     * Maximum page size the GitHub list endpoints allow.
     */
    private const PER_PAGE = 100;

    public function __construct(
        private readonly ?string $token,
        private readonly string $baseUrl = 'https://api.github.com',
        private readonly int $timeout = 10,
    ) {}

    /**
     * Look up a user or organization by login, null when it does not exist.
     *
     * @throws GitHubException
     */
    public function account(string $login): ?GitHubAccount
    {
        $response = $this->get('users/'.rawurlencode($login));

        if ($response->notFound()) {
            return null;
        }

        if ($response->failed()) {
            throw $this->failure($response);
        }

        return GitHubAccount::fromApi($response->json());
    }

    /**
     * The core API rate limit of this client's token (or of this IP without one), e.g.
     * to check a token. GitHub doesn't count requests to this endpoint.
     *
     * @return array{limit: int, remaining: int, reset: int}
     *
     * @throws GitHubException
     */
    public function rateLimit(): array
    {
        $response = $this->get('rate_limit');

        if ($response->failed()) {
            throw $this->failure($response);
        }

        return [
            'limit' => (int) $response->json('resources.core.limit'),
            'remaining' => (int) $response->json('resources.core.remaining'),
            'reset' => (int) $response->json('resources.core.reset'),
        ];
    }

    /**
     * Lazily yield the public repositories a user or organization owns, one API page
     * at a time, following GitHub's Link header until the last page.
     *
     * Organizations filter forks on GitHub's side (type=sources); the users endpoint
     * has no such filter, so forks are skipped here.
     *
     * @return Generator<int, GitHubRepository>
     *
     * @throws GitHubException
     */
    public function repositories(TargetType $type, string $login, RepositoryScope $scope = RepositoryScope::All): Generator
    {
        foreach ($this->repositoryPages($type, $login, $scope) as $page) {
            foreach ($page->repositories as $repository) {
                yield $repository;
            }
        }
    }

    /**
     * The same list as repositories(), page by page, with conditional requests.
     *
     * $known holds the ETag and next URL of each page from an earlier sync, keyed by
     * page URL. Such a page is requested with If-None-Match; GitHub answers 304 Not
     * Modified (no body) when it is unchanged, and with a token that response doesn't
     * count against the rate limit. It is yielded as "not modified" and paging
     * continues with the stored next URL.
     *
     * @param  array<string, array{etag: ?string, next_url: ?string}>  $known
     * @return Generator<int, GitHubRepositoryPage>
     *
     * @throws GitHubException
     */
    public function repositoryPages(TargetType $type, string $login, RepositoryScope $scope = RepositoryScope::All, array $known = []): Generator
    {
        $url = $this->firstPageUrl($type, $login, $scope);

        while ($url !== null) {
            $cached = $known[$url] ?? null;
            $response = $this->get($url, $cached['etag'] ?? null);

            if ($response->status() === 304 && $cached !== null) {
                yield GitHubRepositoryPage::notModified($url, $cached['etag'], $cached['next_url']);
                $url = $cached['next_url'];

                continue;
            }

            $page = $this->page($url, $response, $type, $login, $scope);

            yield $page;

            $url = $page->nextUrl;
        }
    }

    /**
     * Fetch one page of a repository list unconditionally, e.g. when the local copy
     * of a page GitHub reported as unchanged turns out to be incomplete.
     *
     * @throws GitHubException
     */
    public function repositoryPage(TargetType $type, string $login, RepositoryScope $scope, string $url): GitHubRepositoryPage
    {
        return $this->page($url, $this->get($url), $type, $login, $scope);
    }

    /**
     * The first page of a target's repository list, with its query string. The next
     * links GitHub returns already carry every query parameter (including the page).
     */
    private function firstPageUrl(TargetType $type, string $login, RepositoryScope $scope): string
    {
        [$path, $listType] = match ($type) {
            TargetType::Organization => ['orgs/'.rawurlencode($login).'/repos', $scope->includesForks() ? 'public' : 'sources'],
            TargetType::User => ['users/'.rawurlencode($login).'/repos', 'owner'],
        };

        return $path.'?'.http_build_query(['type' => $listType, 'per_page' => self::PER_PAGE]);
    }

    /**
     * Map a repository list response onto a page, or throw for an error response.
     *
     * @throws GitHubException
     */
    private function page(string $url, Response $response, TargetType $type, string $login, RepositoryScope $scope): GitHubRepositoryPage
    {
        if ($response->notFound()) {
            throw GitHubException::notFound(ucfirst($type->label())." \"{$login}\"");
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $repositories = [];

        foreach ($response->json() as $data) {
            $repository = GitHubRepository::fromApi($data);

            if ($repository->fork && ! $scope->includesForks()) {
                continue;
            }

            $repositories[] = $repository;
        }

        return new GitHubRepositoryPage($url, $response->header('ETag') ?: null, $this->nextPageUrl($response), $repositories);
    }

    /**
     * Send a GET request, turning connection problems into a retryable GitHubException.
     * The URL carries its own query string (an empty query array would make Guzzle
     * drop it, losing the page parameter of a next link).
     *
     * @param  string|null  $etag  Sent as If-None-Match for a conditional request.
     *
     * @throws GitHubException
     */
    private function get(string $url, ?string $etag = null): Response
    {
        try {
            return $this->request()
                ->when($etag !== null, fn (PendingRequest $request) => $request->withHeaders(['If-None-Match' => $etag]))
                ->get($url);
        } catch (ConnectionException $e) {
            throw GitHubException::connectionFailed($e);
        }
    }

    /**
     * The exception for a failed response. GitHub's own message only goes to the log:
     * the exception carries a message written for users.
     */
    private function failure(Response $response): GitHubException
    {
        Log::warning('GitHub API request failed.', [
            'status' => $response->status(),
            'url' => (string) $response->effectiveUri(),
            'github_message' => $response->json('message'),
        ]);

        return GitHubException::fromResponse($response);
    }

    /**
     * The `rel="next"` URL from GitHub's Link header, null on the last page.
     */
    private function nextPageUrl(Response $response): ?string
    {
        preg_match('/<([^>]+)>;\s*rel="next"/', $response->header('Link'), $matches);

        return $matches[1] ?? null;
    }

    /**
     * A request preconfigured for the GitHub REST API.
     */
    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->withUserAgent(config('app.name'))
            ->timeout($this->timeout)
            ->when(filled($this->token), fn (PendingRequest $request) => $request->withToken($this->token));
    }
}
