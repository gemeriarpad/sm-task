<?php

namespace App\Services\GitHub;

use App\Enums\TargetType;

/**
 * A GitHub user or organization as returned by the users API.
 */
final readonly class GitHubAccount
{
    public function __construct(
        public int $id,
        public string $login,
        public TargetType $type,
        public ?string $name,
        public string $avatarUrl,
        public string $htmlUrl,
        public int $publicRepos,
    ) {}

    /**
     * Build an account from a `GET /users/{username}` response.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromApi(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            login: (string) $data['login'],
            type: ($data['type'] ?? null) === 'Organization' ? TargetType::Organization : TargetType::User,
            name: isset($data['name']) ? (string) $data['name'] : null,
            avatarUrl: (string) $data['avatar_url'],
            htmlUrl: (string) $data['html_url'],
            publicRepos: (int) ($data['public_repos'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'login' => $this->login,
            'type' => $this->type->value,
            'name' => $this->name,
            'avatar_url' => $this->avatarUrl,
            'html_url' => $this->htmlUrl,
            'public_repos' => $this->publicRepos,
        ];
    }
}
