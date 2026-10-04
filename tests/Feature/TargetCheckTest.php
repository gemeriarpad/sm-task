<?php

namespace Tests\Feature;

use App\Models\Target;
use App\Models\User;
use App\Services\GitHub\GitHubClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TargetCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Saving a target queues a sync; keep it from running against the HTTP fakes.
        Queue::fake();

        $this->actingAs(User::factory()->create());
    }

    /**
     * @return array<string, mixed>
     */
    private function githubUser(array $overrides = []): array
    {
        return array_merge([
            'id' => 958072,
            'login' => 'laravel',
            'type' => 'Organization',
            'name' => 'Laravel',
            'avatar_url' => 'https://avatars.githubusercontent.com/u/958072?v=4',
            'html_url' => 'https://github.com/laravel',
            'public_repos' => 143,
        ], $overrides);
    }

    public function test_guests_cannot_check_names()
    {
        auth()->logout();

        $this->getJson('/targets/check?name=laravel')->assertUnauthorized();
    }

    public function test_an_existing_account_is_found()
    {
        Http::fake(['api.github.com/users/Laravel' => Http::response($this->githubUser())]);

        $this->getJson('/targets/check?name=Laravel')
            ->assertOk()
            ->assertExactJson([
                'found' => true,
                'account' => [
                    'id' => 958072,
                    'login' => 'laravel',
                    'type' => 'organization',
                    'name' => 'Laravel',
                    'avatar_url' => 'https://avatars.githubusercontent.com/u/958072?v=4',
                    'html_url' => 'https://github.com/laravel',
                    'public_repos' => 143,
                ],
            ]);
    }

    public function test_user_accounts_are_reported_as_users()
    {
        Http::fake(['api.github.com/users/taylorotwell' => Http::response($this->githubUser(['login' => 'taylorotwell', 'type' => 'User']))]);

        $this->getJson('/targets/check?name=taylorotwell')->assertJsonPath('account.type', 'user');
    }

    public function test_an_unknown_account_is_not_found()
    {
        Http::fake(['api.github.com/users/*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->getJson('/targets/check?name=nobody-here')
            ->assertOk()
            ->assertExactJson(['found' => false, 'account' => null]);
    }

    public function test_rate_limit_errors_are_reported()
    {
        Http::fake(['api.github.com/users/*' => Http::response(
            ['message' => 'API rate limit exceeded'],
            403,
            ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '1790000000'],
        )]);

        $this->getJson('/targets/check?name=laravel')
            ->assertStatus(502)
            ->assertJsonPath('message', 'GitHub API rate limit exceeded, resets at 2026-09-21 14:13:20 UTC.');
    }

    public function test_other_github_errors_are_reported()
    {
        Http::fake(['api.github.com/users/*' => Http::response(['message' => 'Server Error'], 500)]);

        $this->getJson('/targets/check?name=laravel')
            ->assertStatus(502)
            ->assertJsonPath('message', 'GitHub is having problems right now (HTTP 500).');
    }

    public function test_invalid_names_are_rejected_without_calling_github()
    {
        $this->getJson('/targets/check?name=-bad-')->assertJsonValidationErrors('name');
        $this->getJson('/targets/check')->assertJsonValidationErrors('name');

        Http::assertNothingSent();
    }

    public function test_the_token_is_sent_when_configured()
    {
        config(['services.github.token' => 'test-token']);
        $this->app->forgetInstance(GitHubClient::class);
        Http::fake(['api.github.com/users/*' => Http::response($this->githubUser())]);

        $this->getJson('/targets/check?name=laravel')->assertOk();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28'));
    }

    public function test_no_token_is_sent_when_not_configured()
    {
        config(['services.github.token' => null]);
        $this->app->forgetInstance(GitHubClient::class);
        Http::fake(['api.github.com/users/*' => Http::response($this->githubUser())]);

        $this->getJson('/targets/check?name=laravel')->assertOk();

        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
    }

    public function test_checked_github_data_is_saved_with_the_target()
    {
        $this->post('/targets', [
            'name' => 'laravel',
            'type' => 'organization',
            'github_id' => 958072,
            'avatar_url' => 'https://avatars.githubusercontent.com/u/958072?v=4',
        ])->assertRedirect(route('targets.index'));

        $target = Target::sole();
        $this->assertSame(958072, $target->github_id);
        $this->assertSame('https://avatars.githubusercontent.com/u/958072?v=4', $target->avatar_url);
    }

    public function test_the_same_github_account_cannot_be_added_twice()
    {
        Target::factory()->create(['name' => 'old-name', 'github_id' => 958072]);

        $this->post('/targets', ['name' => 'laravel', 'type' => 'organization', 'github_id' => 958072])
            ->assertSessionHasErrors(['github_id' => 'This GitHub account has already been added under another name.']);
    }

    public function test_avatar_urls_must_point_to_github()
    {
        $this->post('/targets', ['name' => 'laravel', 'type' => 'organization', 'avatar_url' => 'https://evil.example.com/a.png'])
            ->assertSessionHasErrors('avatar_url');
    }
}
