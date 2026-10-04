<?php

namespace Tests\Unit\GitHub;

use App\Services\GitHub\GitHubRepository;
use PHPUnit\Framework\TestCase;

class GitHubRepositoryTest extends TestCase
{
    public function test_it_maps_a_repository_api_item()
    {
        $repository = GitHubRepository::fromApi([
            'id' => 1296269,
            'name' => 'Hello-World',
            'full_name' => 'octocat/Hello-World',
            'description' => 'This your first repo!',
            'html_url' => 'https://github.com/octocat/Hello-World',
            'language' => 'PHP',
            'stargazers_count' => 80,
            'forks_count' => 9,
            'open_issues_count' => 3,
            'fork' => true,
            'archived' => true,
            'updated_at' => '2026-09-01T10:00:00Z',
            'pushed_at' => '2026-08-31T09:30:00Z',
        ]);

        $this->assertSame(1296269, $repository->id);
        $this->assertSame('Hello-World', $repository->name);
        $this->assertSame('octocat/Hello-World', $repository->fullName);
        $this->assertSame('This your first repo!', $repository->description);
        $this->assertSame('https://github.com/octocat/Hello-World', $repository->htmlUrl);
        $this->assertSame('PHP', $repository->language);
        $this->assertSame(80, $repository->stargazersCount);
        $this->assertSame(9, $repository->forksCount);
        $this->assertSame(3, $repository->openIssuesCount);
        $this->assertTrue($repository->fork);
        $this->assertTrue($repository->archived);
        $this->assertSame('2026-09-01 10:00:00', $repository->updatedAt->utc()->toDateTimeString());
        $this->assertSame('2026-08-31 09:30:00', $repository->pushedAt->utc()->toDateTimeString());
    }

    public function test_optional_fields_fall_back_to_safe_defaults()
    {
        // An empty repository has no language, description or push yet.
        $repository = GitHubRepository::fromApi([
            'id' => 1,
            'name' => 'empty',
            'full_name' => 'octocat/empty',
            'description' => null,
            'html_url' => 'https://github.com/octocat/empty',
            'language' => null,
            'updated_at' => '2026-09-01T10:00:00Z',
            'pushed_at' => null,
        ]);

        $this->assertNull($repository->description);
        $this->assertNull($repository->language);
        $this->assertSame(0, $repository->stargazersCount);
        $this->assertSame(0, $repository->forksCount);
        $this->assertSame(0, $repository->openIssuesCount);
        $this->assertFalse($repository->fork);
        $this->assertFalse($repository->archived);
        $this->assertNull($repository->pushedAt);
    }
}
