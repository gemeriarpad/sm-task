<?php

namespace App\Services\GitHub;

/**
 * One page of a repository list. A page GitHub answered with 304 Not Modified has
 * no repositories: it is unchanged since the ETag was stored.
 */
final readonly class GitHubRepositoryPage
{
    /**
     * @param  list<GitHubRepository>  $repositories  Within the requested scope.
     */
    public function __construct(
        public string $url,
        public ?string $etag,
        public ?string $nextUrl,
        public array $repositories,
        public bool $notModified = false,
    ) {}

    public static function notModified(string $url, ?string $etag, ?string $nextUrl): self
    {
        return new self($url, $etag, $nextUrl, [], notModified: true);
    }
}
