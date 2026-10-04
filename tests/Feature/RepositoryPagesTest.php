<?php

namespace Tests\Feature;

use App\Enums\CloneMode;
use App\Enums\CloneStatus;
use App\Enums\TargetStatus;
use App\Models\Repository;
use App\Models\Target;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RepositoryPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_the_repositories_page_lists_repositories_of_every_target()
    {
        $laravel = Target::factory()->create(['name' => 'laravel']);
        $vue = Target::factory()->create(['name' => 'vuejs', 'status' => TargetStatus::Queued]);
        Repository::factory()->for($laravel)->create(['name' => 'framework', 'stargazers_count' => 10]);
        Repository::factory()->for($vue)->create(['name' => 'core', 'stargazers_count' => 20]);

        $this->get('/repositories?sort=stars')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('repositories/Index')
                ->has('repositories.data', 2)
                ->where('repositories.data.0.name', 'core')
                ->where('repositories.data.0.target.name', 'vuejs')
                // Neither is cloned, and their targets don't clone.
                ->where('repositories.data.0.clone.available', false)
                ->where('repositories.data.0.clone.requestable', false)
                ->where('totals.repositories', 2)
                ->where('syncing', true)
                ->has('options.targets', 2));

        $this->get("/repositories?target={$laravel->id}")
            ->assertInertia(fn (Assert $page) => $page->has('repositories.data', 1)->where('repositories.data.0.name', 'framework'));

        $this->get('/repositories?target=abc')
            ->assertInertia(fn (Assert $page) => $page->has('repositories.data', 2)->where('query.target', ''));
    }

    public function test_a_repository_page_shows_its_details_and_clone_state()
    {
        $repository = Repository::factory()->create(['name' => 'framework', 'size_kb' => 2048, 'default_branch' => 'main']);

        $this->get("/repositories/{$repository->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('repositories/Show')
                ->where('repository.name', 'framework')
                ->where('repository.size_kb', 2048)
                ->where('repository.default_branch', 'main')
                ->where('clone.mode', 'inherit')
                ->where('clone.enabled', false)
                ->where('clone.available', false)
                ->where('clone.max_size_mb', 512)
                ->has('cloneModes', 3));
    }

    public function test_list_items_show_the_clone_state_and_the_last_sync()
    {
        $target = Target::factory()->inactive()->create(['clone_repositories' => true]);
        $seenAt = now()->subHour()->startOfSecond();
        Repository::factory()->for($target)->create([
            'clone_mode' => CloneMode::Inherit,
            'clone_status' => CloneStatus::Cloned,
            'clone_branch' => '12.x',
            'cloned_branch' => '12.x',
            'last_seen_at' => $seenAt,
        ]);

        $this->get('/repositories')
            ->assertInertia(fn (Assert $page) => $page
                ->where('repositories.data.0.clone', [
                    'mode' => 'inherit',
                    'enabled' => true,
                    'status' => 'cloned',
                    'branch' => '12.x',
                    'cloned_branch' => '12.x',
                    // Never actually cloned (no cloned_at); the target is inactive.
                    'available' => false,
                    'requestable' => false,
                ])
                ->where('repositories.data.0.last_seen_at', $seenAt->toIso8601String())
                ->where('repositories.data.0.target.status', 'inactive'));
    }

    public function test_a_repository_page_shows_the_branch_and_the_target_sync()
    {
        $repository = Repository::factory()->for(Target::factory()->synced())->create(['clone_branch' => '12.x', 'cloned_branch' => 'main']);

        $this->get("/repositories/{$repository->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('clone.branch', '12.x')
                ->where('clone.cloned_branch', 'main')
                ->where('clone.target_status', 'synced'));
    }

    public function test_guests_cannot_see_repositories()
    {
        auth()->logout();

        $this->get('/repositories')->assertRedirect('/login');
    }

    public function test_list_items_say_which_clone_actions_apply()
    {
        $target = Target::factory()->synced()->create(['clone_repositories' => true]);
        Repository::factory()->for($target)->create(['name' => 'cloned', 'clone_status' => CloneStatus::Cloned, 'cloned_at' => now()]);
        Repository::factory()->for($target)->create(['name' => 'removed', 'cloned_at' => now(), 'missing_at' => now()]);
        Repository::factory()->for($target)->create(['name' => 'never', 'clone_mode' => CloneMode::Never]);

        $clone = fn (string $name) => collect($this->get('/repositories?sort=name')->viewData('page')['props']['repositories']['data'])
            ->firstWhere('name', $name)['clone'];

        $this->assertSame([true, true], [$clone('cloned')['available'], $clone('cloned')['requestable']]);
        // Removed from GitHub: the clone stays, but it can't be cloned again.
        $this->assertSame([true, false], [$clone('removed')['available'], $clone('removed')['requestable']]);
        $this->assertSame([false, false], [$clone('never')['available'], $clone('never')['requestable']]);
    }
}
