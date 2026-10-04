<?php

namespace App\Services\GitHub;

use Carbon\CarbonImmutable;

/**
 * A repository as returned by the GitHub repository list APIs.
 */
final readonly class GitHubRepository
{
    public function __construct(
        public int $id,
        public string $name,
        public string $fullName,
        public ?string $description,
        public string $htmlUrl,
        public ?string $language,
        public int $stargazersCount,
        public int $forksCount,
        public int $openIssuesCount,
        public bool $fork,
        public bool $archived,
        public int $sizeKb,
        public ?string $defaultBranch,
        public ?CarbonImmutable $updatedAt,
        public ?CarbonImmutable $pushedAt,
    ) {}

    /**
     * Build a repository from one item of a repository list response.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromApi(array $data): self
    {
        $date = fn (?string $value): ?CarbonImmutable => $value ? CarbonImmutable::parse($value) : null;

        return new self(
            id: (int) $data['id'],
            name: (string) $data['name'],
            fullName: (string) $data['full_name'],
            description: isset($data['description']) ? (string) $data['description'] : null,
            htmlUrl: (string) $data['html_url'],
            language: isset($data['language']) ? (string) $data['language'] : null,
            stargazersCount: (int) ($data['stargazers_count'] ?? 0),
            forksCount: (int) ($data['forks_count'] ?? 0),
            // Note: GitHub counts open pull requests as issues here.
            openIssuesCount: (int) ($data['open_issues_count'] ?? 0),
            fork: (bool) ($data['fork'] ?? false),
            archived: (bool) ($data['archived'] ?? false),
            // GitHub reports the size in KB.
            sizeKb: (int) ($data['size'] ?? 0),
            defaultBranch: isset($data['default_branch']) ? (string) $data['default_branch'] : null,
            updatedAt: $date($data['updated_at'] ?? null),
            pushedAt: $date($data['pushed_at'] ?? null),
        );
    }
}
