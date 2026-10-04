<?php

namespace Tests\Feature;

use App\Enums\TargetStatus;
use App\Models\Repository;
use App\Models\SyncRun;
use App\Models\Target;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_root_redirects_to_the_dashboard()
    {
        $this->get('/')->assertRedirect('/dashboard');
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/dashboard');
        $response->assertStatus(200);
    }

    public function test_the_dashboard_summarizes_targets_repositories_and_sync_activity()
    {
        $this->actingAs(User::factory()->create());

        $laravel = Target::factory()->synced()->create(['name' => 'laravel']);
        $vue = Target::factory()->failed('Boom')->create(['name' => 'vuejs']);
        Target::factory()->create(['name' => 'busy', 'status' => TargetStatus::Syncing]);
        Repository::factory()->for($laravel)->count(2)->create();
        Repository::factory()->for($laravel)->missing()->create();
        SyncRun::factory()->for($laravel)->create(['started_at' => now()->subHour()]);
        SyncRun::factory()->for($vue)->failed('Organization "vuejs" was not found on GitHub.')->create(['started_at' => now()->subMinute()]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('stats.targets', 3)
                ->where('stats.repositories', 3)
                ->where('stats.missing', 1)
                ->where('stats.statuses', ['queued' => 0, 'syncing' => 1, 'synced' => 1, 'failed' => 1, 'inactive' => 0])
                // Recently synced first; never synced ones after it.
                ->where('targetStrip', 'synced')
                ->has('targetStrips', 4)
                ->has('targets', 3)
                ->where('targets.0.name', 'laravel')
                ->where('targets.0.repositories_count', 3)
                ->where('targetsTotal', 3)
                ->has('latestErrors', 1)
                ->where('latestErrors.0.target.name', 'vuejs')
                ->where('latestErrors.0.error', 'Organization "vuejs" was not found on GitHub.')
                ->has('history', 2)
                ->where('history.0.target.name', 'vuejs')
                ->where('syncing', true)
                ->where('strip', 'pushed')
                ->has('strips', 4));
    }

    public function test_the_latest_errors_and_the_sync_history_show_at_most_twenty_runs()
    {
        $this->actingAs(User::factory()->create());

        $target = Target::factory()->create();
        SyncRun::factory()->count(25)->failed()->for($target)->create();

        $this->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('latestErrors', 20)
                ->has('history', 20));
    }

    public function test_the_repository_strip_can_be_switched()
    {
        $this->actingAs(User::factory()->create());

        Repository::factory()->create(['name' => 'popular', 'stargazers_count' => 900, 'pushed_at' => now()->subYear()]);
        Repository::factory()->create(['name' => 'active', 'stargazers_count' => 1, 'pushed_at' => now()]);
        Repository::factory()->missing()->create(['name' => 'gone', 'stargazers_count' => 0]);

        $names = fn (string $strip) => collect($this->get("/dashboard?strip={$strip}")->viewData('page')['props']['repositoryStrip'])->pluck('name')->all();

        $this->assertSame(['active', 'popular'], $names('pushed'));
        $this->assertSame('popular', $names('stars')[0]);
        $this->assertSame(['gone'], $names('removed'));
        $this->assertSame('gone', $names('added')[0]);
        $this->assertSame(['active', 'popular'], $names('nonsense'));
        $this->get('/dashboard?strip[]=x')->assertOk();
    }

    public function test_the_repository_strip_shows_five_repositories_and_links_to_the_matching_list()
    {
        $this->actingAs(User::factory()->create());

        Repository::factory()->count(7)->create();
        Repository::factory()->missing()->create();
        Repository::factory()->create(['excluded_at' => now()]);

        $this->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('repositoryStrip', 5)
                ->where('repositoryStripTotal', 7)
                ->where('repositoryStripUrl', route('repositories.index', ['sort' => 'pushed', 'missing' => 'hide'])));

        $this->get('/dashboard?strip=removed')
            ->assertInertia(fn (Assert $page) => $page
                ->has('repositoryStrip', 1)
                ->where('repositoryStripTotal', 1)
                ->where('repositoryStripUrl', route('repositories.index', ['missing' => 'only'])));
    }

    public function test_the_target_strip_can_be_switched()
    {
        $this->actingAs(User::factory()->create());

        $old = Target::factory()->synced()->create(['name' => 'old', 'last_synced_at' => now()->subDay(), 'created_at' => now()->subWeek()]);
        Target::factory()->synced()->create(['name' => 'fresh', 'created_at' => now()->subDays(2)]);
        Target::factory()->failed()->create(['name' => 'broken', 'created_at' => now()->subDay()]);
        Target::factory()->create(['name' => 'new']);
        Repository::factory()->for($old)->count(2)->create();
        Repository::factory()->for($old)->create(['excluded_at' => now()]);

        $names = fn (string $mode) => collect($this->get("/dashboard?target_strip={$mode}")->viewData('page')['props']['targets'])->pluck('name')->all();

        $this->assertSame(['fresh', 'old', 'broken', 'new'], $names('synced'));
        $this->assertSame(['new', 'broken', 'fresh', 'old'], $names('added'));
        $this->assertSame('old', $names('repositories')[0]);
        $this->assertSame(['broken'], $names('failed'));
        $this->assertSame(['fresh', 'old', 'broken', 'new'], $names('nonsense'));
        $this->get('/dashboard?target_strip[]=x')->assertOk();

        // Each strip keeps its own mode.
        $this->get('/dashboard?strip=stars&target_strip=failed')
            ->assertInertia(fn (Assert $page) => $page->where('strip', 'stars')->where('targetStrip', 'failed'));
    }

    public function test_the_target_strip_shows_five_targets_and_links_to_the_matching_table()
    {
        $this->actingAs(User::factory()->create());

        Target::factory()->synced()->count(6)->create();
        Target::factory()->failed()->create();

        $this->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('targets', 5)
                ->where('targetsTotal', 7)
                ->where('targetsUrl', route('targets.index', ['sort' => 'last_synced_at', 'direction' => 'desc'])));

        $this->get('/dashboard?target_strip=repositories')
            ->assertInertia(fn (Assert $page) => $page
                ->where('targetsTotal', 7)
                ->where('targetsUrl', route('targets.index', ['sort' => 'repositories_count', 'direction' => 'desc'])));

        $this->get('/dashboard?target_strip=failed')
            ->assertInertia(fn (Assert $page) => $page
                ->has('targets', 1)
                ->where('targetsTotal', 1)
                ->where('targetsUrl', route('targets.index', ['filter' => ['status' => 'failed']])));
    }
}
