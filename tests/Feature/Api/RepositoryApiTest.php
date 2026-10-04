<?php

namespace Tests\Feature\Api;

use App\Models\Repository;
use App\Models\Target;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RepositoryApiTest extends TestCase
{
    use RefreshDatabase;

    private Target $laravel;

    private Target $vue;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create(), ['read']);

        $this->laravel = Target::factory()->synced()->create(['name' => 'laravel']);
        $this->vue = Target::factory()->synced()->create(['name' => 'vuejs']);
        Repository::factory()->for($this->laravel)->create(['name' => 'framework', 'language' => 'PHP', 'stargazers_count' => 900, 'description' => 'The Laravel Framework.']);
        Repository::factory()->for($this->laravel)->create(['name' => 'pint', 'language' => 'PHP', 'stargazers_count' => 50, 'archived' => true]);
        Repository::factory()->for($this->laravel)->create(['name' => 'hidden', 'excluded_at' => now()]);
        Repository::factory()->for($this->vue)->create(['name' => 'core', 'language' => 'TypeScript', 'stargazers_count' => 400]);
    }

    public function test_repositories_of_every_target_are_listed_without_excluded_ones()
    {
        $this->getJson('/api/v1/repositories?sort=stars')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.name', 'framework')
            ->assertJsonPath('data.0.target', ['id' => $this->laravel->id, 'name' => 'laravel'])
            ->assertJsonPath('data.1.name', 'core')
            ->assertJsonStructure(['data' => [['id', 'github_id', 'full_name', 'html_url', 'stargazers_count', 'open_issues_count', 'archived', 'github_updated_at', 'missing_at', 'links' => ['self']]]])
            // The local clone is a web UI feature, not API data.
            ->assertJsonMissingPath('data.0.clone');
    }

    public function test_repositories_use_the_web_ui_filters()
    {
        $names = fn (string $query) => collect($this->getJson("/api/v1/repositories?{$query}")->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['framework', 'pint'], $names('language=PHP'));
        $this->assertSame(['framework'], $names('search=Laravel%20Framework'));
        $this->assertSame(['pint'], $names('archived=only'));
        $this->assertSame(['core'], $names("target={$this->vue->id}"));
        $this->assertSame(['hidden'], $names('excluded=only'));
    }

    public function test_a_targets_repositories_are_listed()
    {
        $names = collect($this->getJson("/api/v1/targets/{$this->vue->id}/repositories")->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['core'], $names);
    }

    public function test_invalid_query_parameters_are_rejected()
    {
        $this->getJson('/api/v1/repositories?sort=forks&archived=yes&per_page=1000')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort', 'archived', 'per_page']);
    }

    public function test_a_repository_can_be_shown()
    {
        $repository = Repository::where('name', 'core')->sole();

        $this->getJson("/api/v1/repositories/{$repository->id}")
            ->assertOk()
            ->assertJsonPath('data.full_name', $repository->full_name)
            ->assertJsonPath('data.target.name', 'vuejs');

        $this->getJson('/api/v1/repositories/999')->assertNotFound()->assertExactJson(['message' => 'Repository not found.']);
    }
}
