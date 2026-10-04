<?php

namespace Tests\Unit\GitHub;

use App\Enums\TargetType;
use App\Services\GitHub\GitHubAccount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GitHubAccountTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: TargetType}>
     */
    public static function accountTypes(): array
    {
        return [
            'organization' => ['Organization', TargetType::Organization],
            'user' => ['User', TargetType::User],
            'bot accounts are treated as users' => ['Bot', TargetType::User],
        ];
    }

    #[DataProvider('accountTypes')]
    public function test_it_maps_the_github_account_type(string $githubType, TargetType $expected)
    {
        $account = GitHubAccount::fromApi([
            'id' => 1,
            'login' => 'octocat',
            'type' => $githubType,
            'avatar_url' => 'https://avatars.githubusercontent.com/u/1?v=4',
            'html_url' => 'https://github.com/octocat',
        ]);

        $this->assertSame($expected, $account->type);
    }

    public function test_it_serializes_for_the_check_endpoint()
    {
        $account = GitHubAccount::fromApi([
            'id' => 583231,
            'login' => 'octocat',
            'type' => 'User',
            'name' => 'The Octocat',
            'avatar_url' => 'https://avatars.githubusercontent.com/u/583231?v=4',
            'html_url' => 'https://github.com/octocat',
            'public_repos' => 8,
        ]);

        $this->assertSame([
            'id' => 583231,
            'login' => 'octocat',
            'type' => 'user',
            'name' => 'The Octocat',
            'avatar_url' => 'https://avatars.githubusercontent.com/u/583231?v=4',
            'html_url' => 'https://github.com/octocat',
            'public_repos' => 8,
        ], $account->toArray());
    }
}
