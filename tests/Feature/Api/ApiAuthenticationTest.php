<?php

namespace Tests\Feature\Api;

use App\Models\Target;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_requests_without_a_token_get_a_json_401_instead_of_a_login_redirect()
    {
        // No Accept header: the API still answers in JSON.
        $this->get('/api/v1/targets')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_a_browser_session_does_not_authenticate_the_api()
    {
        // A session user would get every token ability, so the API accepts tokens only.
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/v1/targets')->assertUnauthorized();
    }

    public function test_a_token_needs_the_ability_of_the_route()
    {
        $target = Target::factory()->create();

        Sanctum::actingAs(User::factory()->create(), ['sync']);
        $this->getJson('/api/v1/targets')->assertForbidden()->assertJsonStructure(['message']);
        $this->getJson("/api/v1/targets/{$target->id}/repositories")->assertForbidden();

        Sanctum::actingAs(User::factory()->create(), ['read']);
        $this->postJson("/api/v1/targets/{$target->id}/sync")->assertForbidden();
        $this->postJson('/api/v1/sync')->assertForbidden();
    }

    public function test_polling_works_with_either_ability()
    {
        $target = Target::factory()->synced()->create();

        foreach (['read', 'sync'] as $ability) {
            Sanctum::actingAs(User::factory()->create(), [$ability]);
            $this->getJson("/api/v1/targets/{$target->id}/sync")->assertOk();
        }
    }

    public function test_a_revoked_token_stops_working()
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile', ['read']);

        $this->withToken($token->plainTextToken)->getJson('/api/v1/targets')->assertOk();

        $token->accessToken->delete();
        $this->app['auth']->forgetGuards();

        $this->withToken($token->plainTextToken)->getJson('/api/v1/targets')->assertUnauthorized();
    }

    public function test_unknown_routes_and_ids_return_json_404s()
    {
        Sanctum::actingAs(User::factory()->create(), ['read']);

        $this->get('/api/v1/nope')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        $this->get('/api/v1/targets/not-a-number')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    }

    public function test_the_api_is_rate_limited_per_token()
    {
        Sanctum::actingAs(User::factory()->create(), ['read']);

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/targets')->assertOk();
        }

        $this->getJson('/api/v1/targets')->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_each_token_has_its_own_rate_limit()
    {
        $user = User::factory()->create();
        $ci = $user->createToken('ci', ['read'])->plainTextToken;
        $app = $user->createToken('app', ['read'])->plainTextToken;

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/targets', ['Authorization' => "Bearer {$ci}"])->assertOk();
        }

        $this->getJson('/api/v1/targets', ['Authorization' => "Bearer {$ci}"])->assertTooManyRequests();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/targets', ['Authorization' => "Bearer {$app}"])->assertOk();
    }

    public function test_requests_with_a_wrong_token_are_rate_limited_per_ip()
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/targets', ['Authorization' => 'Bearer 1|wrong'])->assertUnauthorized();
        }

        $this->getJson('/api/v1/targets', ['Authorization' => 'Bearer 1|wrong'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['message']);

        // Another address isn't affected.
        $this->getJson('/api/v1/targets', ['Authorization' => 'Bearer 1|wrong', 'REMOTE_ADDR' => '10.0.0.2'])->assertUnauthorized();
    }

    public function test_the_per_ip_limit_leaves_the_web_app_alone()
    {
        for ($i = 0; $i < 121; $i++) {
            $this->get('/login')->assertOk();
        }
    }
}
