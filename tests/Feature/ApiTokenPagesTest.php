<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ApiTokenPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_the_page_lists_the_users_tokens_without_the_secret()
    {
        $this->user->createToken('mobile', ['read']);
        User::factory()->create()->createToken('someone else', ['read']);

        $this->get('/api-tokens')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('api-tokens/Index')
                ->has('tokens', 1)
                ->where('tokens.0.name', 'mobile')
                ->where('tokens.0.abilities', ['read'])
                ->missing('tokens.0.token')
                ->has('abilities', 2)
                ->where('newToken', null));
    }

    public function test_a_created_token_is_shown_once_and_works_with_its_abilities()
    {
        $this->post('/api-tokens', ['name' => 'CI hook', 'abilities' => ['sync']])->assertRedirect('/api-tokens');

        $token = PersonalAccessToken::sole();
        $this->assertSame(['sync'], $token->abilities);
        $this->assertTrue($token->tokenable->is($this->user));

        $newToken = $this->get('/api-tokens')->viewData('page')['props']['newToken'];
        $this->assertSame('CI hook', $newToken['name']);
        $plain = $newToken['token'];

        // Flashed: gone on the next visit.
        $this->get('/api-tokens')->assertInertia(fn (Assert $page) => $page->where('newToken', null));

        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->postJson('/api/v1/sync')->assertAccepted();
        $this->withToken($plain)->getJson('/api/v1/targets')->assertForbidden();
    }

    public function test_a_token_needs_a_name_and_a_known_ability()
    {
        $this->post('/api-tokens', ['name' => '', 'abilities' => []])->assertSessionHasErrors(['name', 'abilities']);
        $this->post('/api-tokens', ['name' => 'x', 'abilities' => ['admin']])->assertSessionHasErrors('abilities.0');

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_a_token_can_be_revoked_but_only_by_its_owner()
    {
        $own = $this->user->createToken('mobile', ['read'])->accessToken;
        $other = User::factory()->create()->createToken('other', ['read'])->accessToken;

        $this->delete("/api-tokens/{$other->id}")->assertNotFound();
        $this->delete("/api-tokens/{$own->id}")->assertRedirect('/api-tokens');

        $this->assertModelMissing($own);
        $this->assertModelExists($other);
    }

    public function test_guests_cannot_manage_tokens()
    {
        auth()->logout();

        $this->get('/api-tokens')->assertRedirect('/login');
        $this->post('/api-tokens', ['name' => 'x', 'abilities' => ['read']])->assertRedirect('/login');
    }
}
