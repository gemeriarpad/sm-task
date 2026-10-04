<?php

namespace Tests\Feature\Api;

use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Models\Repository;
use App\Models\Target;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TargetApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create(), ['read']);
    }

    public function test_targets_are_listed_with_pagination()
    {
        $laravel = Target::factory()->synced()->create(['name' => 'laravel', 'type' => TargetType::Organization]);
        Target::factory()->create(['name' => 'vuejs']);
        Repository::factory()->for($laravel)->count(2)->create();
        Repository::factory()->for($laravel)->missing()->create();
        Repository::factory()->for($laravel)->create(['excluded_at' => now()]);

        $this->getJson('/api/v1/targets?per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'last_page', 'total']])
            ->assertJsonPath('data.0.name', 'laravel')
            ->assertJsonPath('data.0.type', 'organization')
            ->assertJsonPath('data.0.status', 'synced')
            // On GitHub and not excluded, like the web UI.
            ->assertJsonPath('data.0.repositories_count', 2)
            ->assertJsonPath('data.0.links.sync', route('api.v1.targets.sync.show', $laravel))
            ->assertJsonPath('data.1.name', 'vuejs');
    }

    public function test_targets_can_be_filtered_and_sorted()
    {
        Target::factory()->synced()->create(['name' => 'laravel', 'type' => TargetType::Organization, 'last_synced_at' => now()->subDay()]);
        Target::factory()->failed()->create(['name' => 'taylorotwell', 'type' => TargetType::User]);
        Target::factory()->synced()->create(['name' => 'vuejs', 'type' => TargetType::Organization, 'last_synced_at' => now()]);

        $names = fn (string $query) => collect($this->getJson("/api/v1/targets?{$query}")->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['laravel', 'vuejs'], $names('type=organization'));
        $this->assertSame(['taylorotwell'], $names('status=failed'));
        $this->assertSame(['vuejs'], $names('search=vue'));
        // Newest sync first, never synced last.
        $this->assertSame(['vuejs', 'laravel', 'taylorotwell'], $names('sort=last_synced_at'));
        $this->assertSame(['vuejs', 'taylorotwell', 'laravel'], $names('sort=name&direction=desc'));
    }

    public function test_invalid_query_parameters_are_rejected()
    {
        $this->getJson('/api/v1/targets?status=nope&sort=stars&per_page=7&type=team')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'sort', 'per_page', 'type']);
    }

    public function test_a_target_can_be_shown()
    {
        $target = Target::factory()->failed('GitHub API rate limit exceeded.')->create(['name' => 'laravel']);
        Repository::factory()->for($target)->create();

        $this->getJson("/api/v1/targets/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.status', TargetStatus::Failed->value)
            ->assertJsonPath('data.last_error', 'GitHub API rate limit exceeded.')
            ->assertJsonPath('data.repositories_count', 1);
    }

    public function test_an_unknown_target_is_a_404_without_internal_details()
    {
        $this->getJson('/api/v1/targets/999')->assertNotFound()->assertExactJson(['message' => 'Target not found.']);
    }
}
