<?php

namespace Tests\Feature;

use App\Enums\TargetStatus;
use App\Models\SyncRun;
use App\Models\Target;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SyncRunPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_the_sync_history_lists_every_run_newest_first()
    {
        $laravel = Target::factory()->create(['name' => 'laravel']);
        $vue = Target::factory()->create(['name' => 'vuejs', 'status' => TargetStatus::Syncing]);
        SyncRun::factory()->for($laravel)->create(['started_at' => now()->subHours(2)]);
        SyncRun::factory()->for($laravel)->failed()->create(['started_at' => now()->subHour()]);
        SyncRun::factory()->for($vue)->running()->create(['started_at' => now()]);

        $this->get('/sync-runs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sync-runs/Index')
                ->has('runs.data', 3)
                ->where('runs.data.0.target.name', 'vuejs')
                ->where('runs.data.0.status', 'running')
                ->where('runs.data.1.status', 'failed')
                ->where('sort', 'started_at')
                ->where('direction', 'desc')
                ->where('syncing', true)
                ->has('options.targets', 2)
                ->has('options.statuses', 3));
    }

    public function test_the_sync_history_filters_by_target_and_status()
    {
        $laravel = Target::factory()->create(['name' => 'laravel']);
        $vue = Target::factory()->create(['name' => 'vuejs']);
        SyncRun::factory()->for($laravel)->create();
        SyncRun::factory()->for($laravel)->failed()->create();
        SyncRun::factory()->for($vue)->failed()->create();

        $this->get("/sync-runs?filter[target]={$laravel->id}&filter[status]=failed")
            ->assertInertia(fn (Assert $page) => $page
                ->has('runs.data', 1)
                ->where('runs.data.0.target.name', 'laravel')
                ->where('runs.data.0.status', 'failed')
                ->where('filters', ['target' => (string) $laravel->id, 'status' => 'failed']));

        // Values that can't match are ignored instead of emptying the table.
        $this->get('/sync-runs?filter[target]=abc&filter[status]=exploded')
            ->assertInertia(fn (Assert $page) => $page->has('runs.data', 3)->where('filters', []));
    }

    public function test_the_sync_history_sorts_by_target_name_and_puts_unfinished_runs_last()
    {
        $zeta = Target::factory()->create(['name' => 'zeta']);
        $alpha = Target::factory()->create(['name' => 'alpha']);
        SyncRun::factory()->for($zeta)->create(['duration_ms' => 500]);
        SyncRun::factory()->for($alpha)->running()->create();
        SyncRun::factory()->for($alpha)->create(['duration_ms' => 9000]);

        $this->get('/sync-runs?sort=target&direction=asc')
            ->assertInertia(fn (Assert $page) => $page
                ->where('runs.data.0.target.name', 'alpha')
                ->where('runs.data.2.target.name', 'zeta'));

        $this->get('/sync-runs?sort=duration_ms&direction=desc')
            ->assertInertia(fn (Assert $page) => $page
                ->where('runs.data.0.duration_ms', 9000)
                ->where('runs.data.1.duration_ms', 500)
                ->where('runs.data.2.duration_ms', null));
    }

    public function test_the_errors_page_lists_failed_runs_and_runs_with_clone_errors()
    {
        $target = Target::factory()->create(['name' => 'laravel']);
        SyncRun::factory()->for($target)->create(['clone_errors_count' => 0, 'started_at' => now()->subHours(3)]);
        SyncRun::factory()->for($target)->running()->create(['started_at' => now()]);
        SyncRun::factory()->for($target)->failed('Organization "laravel" was not found on GitHub.')->create(['started_at' => now()->subHours(2)]);
        SyncRun::factory()->for($target)->create(['clone_errors_count' => 2, 'started_at' => now()->subHour()]);

        $this->get('/sync-runs/errors')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sync-runs/Errors')
                ->has('runs.data', 2)
                ->where('runs.data.0.status', 'succeeded')
                ->where('runs.data.0.clone_errors_count', 2)
                ->where('runs.data.1.status', 'failed')
                ->where('runs.data.1.error', 'Organization "laravel" was not found on GitHub.')
                ->has('options.retrying', 2));
    }

    public function test_the_errors_page_filters_by_error_text_and_retrying()
    {
        $target = Target::factory()->create();
        SyncRun::factory()->for($target)->failed('GitHub API rate limit exceeded', willRetry: true)->create();
        SyncRun::factory()->for($target)->failed('Organization "x" was not found on GitHub.')->create();

        $this->get('/sync-runs/errors?filter[error]=rate+limit')
            ->assertInertia(fn (Assert $page) => $page->has('runs.data', 1)->where('runs.data.0.error', 'GitHub API rate limit exceeded'));

        $this->get('/sync-runs/errors?filter[retrying]=no')
            ->assertInertia(fn (Assert $page) => $page->has('runs.data', 1)->where('runs.data.0.will_retry', false));

        // The errors page has no status filter: it would always be empty for "succeeded".
        $this->get('/sync-runs/errors?filter[status]=succeeded')
            ->assertInertia(fn (Assert $page) => $page->has('runs.data', 2)->where('filters', []));
    }

    public function test_guests_cannot_see_the_sync_history_or_errors()
    {
        auth()->logout();

        $this->get('/sync-runs')->assertRedirect('/login');
        $this->get('/sync-runs/errors')->assertRedirect('/login');
    }
}
