<?php

namespace Tests\Feature;

use App\Enums\CloneMode;
use App\Enums\RepositoryScope;
use App\Enums\TargetType;
use App\Jobs\SyncTargetRepositories;
use App\Models\Repository;
use App\Models\Target;
use App\Models\User;
use App\Services\GitHub\GitHubClient;
use App\Services\RepositorySynchronizer;
use App\Services\SyncSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Sync\GitHubClientTest;
use Tests\TestCase;

class ScopeAndExclusionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->actingAs(User::factory()->create());
    }

    private function sync(Target $target): SyncSummary
    {
        return app(RepositorySynchronizer::class)->sync($target);
    }

    // --- Scope -------------------------------------------------------------

    public function test_organizations_ask_github_for_sources_only_by_default()
    {
        Http::fake(['api.github.com/*' => Http::response([])]);

        iterator_to_array((new GitHubClient(null))->repositories(TargetType::Organization, 'laravel', RepositoryScope::Sources));
        iterator_to_array((new GitHubClient(null))->repositories(TargetType::Organization, 'laravel', RepositoryScope::All));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/orgs/laravel/repos?type=sources&per_page=100');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/orgs/laravel/repos?type=public&per_page=100');
    }

    public function test_user_forks_are_filtered_locally_for_sources_only()
    {
        Http::fake(['api.github.com/users/pyr0hu/repos*' => Http::response([GitHubClientTest::repo(1), GitHubClientTest::repo(2, ['fork' => true])])]);

        $sources = iterator_to_array((new GitHubClient(null))->repositories(TargetType::User, 'pyr0hu', RepositoryScope::Sources), false);
        $all = iterator_to_array((new GitHubClient(null))->repositories(TargetType::User, 'pyr0hu', RepositoryScope::All), false);

        $this->assertSame([1], array_map(fn ($repository) => $repository->id, $sources));
        $this->assertSame([1, 2], array_map(fn ($repository) => $repository->id, $all));
    }

    public function test_new_targets_sync_sources_only()
    {
        $this->assertSame(RepositoryScope::Sources, Target::factory()->create()->repository_scope);
    }

    public function test_narrowing_the_scope_deletes_out_of_scope_repositories_and_their_clones()
    {
        $base = config('services.github.clone_path');
        $target = Target::factory()->create(['name' => 'pyr0hu', 'type' => TargetType::User, 'repository_scope' => RepositoryScope::Sources]);
        $fork = Repository::factory()->for($target)->create(['github_id' => 2, 'fork' => true]);
        File::ensureDirectoryExists("{$base}/2/.git");
        Http::fake(['api.github.com/users/pyr0hu/repos*' => Http::response([GitHubClientTest::repo(1), GitHubClientTest::repo(2, ['fork' => true])])]);

        $this->assertSame(1, $this->sync($target)->repositories);

        $this->assertModelMissing($fork);
        $this->assertDirectoryDoesNotExist("{$base}/2");
        $this->assertSame([1], $target->repositories()->pluck('github_id')->all());
        $this->assertSame(0, $target->repositories()->whereNotNull('missing_at')->count());

        File::deleteDirectory($base);
    }

    public function test_forks_are_kept_when_the_scope_includes_them()
    {
        $target = Target::factory()->create(['name' => 'pyr0hu', 'type' => TargetType::User, 'repository_scope' => RepositoryScope::All]);
        Http::fake(['api.github.com/users/pyr0hu/repos*' => Http::response([GitHubClientTest::repo(1), GitHubClientTest::repo(2, ['fork' => true])])]);

        $this->assertSame(2, $this->sync($target)->repositories);
        $this->assertSame(1, $target->repositories()->where('fork', true)->count());
    }

    public function test_changing_the_scope_queues_a_sync()
    {
        $target = Target::factory()->synced()->create(['name' => 'laravel', 'type' => TargetType::Organization]);

        $this->patch("/targets/{$target->id}", ['repository_scope' => 'all'])->assertRedirect();

        $this->assertSame(RepositoryScope::All, $target->fresh()->repository_scope);
        Queue::assertPushed(SyncTargetRepositories::class, 1);
    }

    public function test_an_unknown_scope_is_rejected()
    {
        $this->post('/targets', ['name' => 'laravel', 'type' => 'organization', 'repository_scope' => 'everything'])
            ->assertSessionHasErrors('repository_scope');
    }

    // --- Exclusion ---------------------------------------------------------

    public function test_a_repository_can_be_excluded_and_included_again()
    {
        $repository = Repository::factory()->create();

        $this->post("/repositories/{$repository->id}/exclusion")->assertRedirect();
        $this->assertNotNull($repository->fresh()->excluded_at);

        $this->delete("/repositories/{$repository->id}/exclusion")->assertRedirect();
        $this->assertNull($repository->fresh()->excluded_at);
    }

    public function test_excluded_repositories_are_hidden_unless_asked_for()
    {
        Repository::factory()->create(['name' => 'visible']);
        Repository::factory()->create(['name' => 'hidden', 'excluded_at' => now()]);

        $names = fn (string $query) => collect($this->get("/repositories?sort=name&{$query}")->viewData('page')['props']['repositories']['data'])->pluck('name')->all();

        $this->assertSame(['visible'], $names(''));
        $this->assertSame(['hidden'], $names('excluded=only'));
        $this->assertSame(['hidden', 'visible'], $names('excluded=include'));
    }

    public function test_excluded_repositories_are_frozen_by_syncs()
    {
        $target = Target::factory()->create(['name' => 'laravel', 'type' => TargetType::Organization]);
        $excluded = Repository::factory()->for($target)->create(['github_id' => 1, 'stargazers_count' => 5, 'excluded_at' => now()]);
        $excludedAndGone = Repository::factory()->for($target)->create(['github_id' => 9, 'excluded_at' => now()]);
        Http::fake(['api.github.com/orgs/laravel/repos*' => Http::response([GitHubClientTest::repo(1, ['stargazers_count' => 999]), GitHubClientTest::repo(2)])]);
        $this->travel(1)->minute();

        $this->sync($target);

        $this->assertSame(5, $excluded->fresh()->stargazers_count);
        $this->assertNull($excludedAndGone->fresh()->missing_at);
        $this->assertDatabaseCount('repositories', 3);
    }

    public function test_excluded_repositories_are_never_cloned()
    {
        $repository = Repository::factory()->create(['clone_mode' => CloneMode::Always, 'excluded_at' => now()]);

        $this->assertFalse($repository->shouldClone());
        $this->assertFalse($repository->requestClone());
    }

    public function test_the_dashboard_leaves_excluded_repositories_out()
    {
        $target = Target::factory()->create();
        Repository::factory()->for($target)->create(['pushed_at' => now()]);
        Repository::factory()->for($target)->create(['excluded_at' => now(), 'pushed_at' => now()]);

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('stats.repositories', 1)
            ->where('targets.0.repositories_count', 1)
            ->has('repositoryStrip', 1));
    }
}
