<?php

namespace Tests\Feature\Sync;

use App\Enums\TargetStatus;
use App\Jobs\SyncTargetRepositories;
use App\Models\Repository;
use App\Models\Target;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TargetSyncFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->actingAs(User::factory()->create());
    }

    public function test_adding_a_target_queues_its_first_sync()
    {
        $this->post('/targets', ['name' => 'laravel', 'type' => 'organization'])->assertRedirect();

        $target = Target::sole();
        $this->assertSame(TargetStatus::Queued, $target->status);
        Queue::assertPushed(SyncTargetRepositories::class, fn (SyncTargetRepositories $job) => $job->target->is($target));
    }

    public function test_a_single_target_can_be_synced()
    {
        $target = Target::factory()->synced()->create();

        $this->from("/targets/{$target->id}")->post("/targets/{$target->id}/sync")->assertRedirect("/targets/{$target->id}");

        $this->assertSame(TargetStatus::Queued, $target->fresh()->status);
        Queue::assertPushed(SyncTargetRepositories::class, 1);
    }

    public function test_syncing_a_target_already_in_progress_is_skipped()
    {
        $target = Target::factory()->create(['status' => TargetStatus::Syncing]);

        $this->post("/targets/{$target->id}/sync")->assertRedirect();

        $this->assertSame(TargetStatus::Syncing, $target->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_all_targets_can_be_synced_at_once()
    {
        Target::factory()->count(2)->synced()->create();
        Target::factory()->create(['status' => TargetStatus::Queued]);

        $this->post('/targets/sync')->assertRedirect();

        Queue::assertPushed(SyncTargetRepositories::class, 2);
    }

    public function test_guests_cannot_sync()
    {
        $target = Target::factory()->create();
        auth()->logout();

        $this->post("/targets/{$target->id}/sync")->assertRedirect('/login');
        $this->post('/targets/sync')->assertRedirect('/login');
        Queue::assertNothingPushed();
    }

    public function test_the_target_page_lists_its_repositories()
    {
        $target = Target::factory()->synced()->create(['name' => 'laravel']);
        Repository::factory()->for($target)->create(['name' => 'framework', 'pushed_at' => now()->subDay(), 'language' => 'PHP']);
        Repository::factory()->for($target)->missing()->create(['name' => 'old', 'pushed_at' => now()->subYear(), 'language' => 'Go']);
        Repository::factory()->create(['name' => 'someone-elses']);

        $this->get("/targets/{$target->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('targets/Show')
                ->where('target.name', 'laravel')
                ->where('target.repositories_count', 1)
                ->where('target.missing_count', 1)
                ->where('target.excluded_count', 0)
                ->has('repositories.data', 2)
                ->where('repositories.data.0.name', 'framework')
                ->where('repositories.data.1.name', 'old')
                ->whereNot('repositories.data.1.missing_at', null)
                ->where('query.sort', 'pushed')
                ->where('options.languages', [['value' => 'Go', 'label' => 'Go'], ['value' => 'PHP', 'label' => 'PHP']])
                ->has('options.sorts', 4));
    }

    public function test_the_repository_list_can_be_searched_filtered_and_sorted()
    {
        $target = Target::factory()->create();
        Repository::factory()->for($target)->create(['name' => 'framework', 'description' => 'The Laravel framework', 'stargazers_count' => 100, 'language' => 'PHP']);
        Repository::factory()->for($target)->create(['name' => 'docs', 'description' => 'Documentation', 'stargazers_count' => 500, 'language' => 'Markdown', 'archived' => true]);
        Repository::factory()->for($target)->create(['name' => 'forked', 'description' => null, 'stargazers_count' => 1, 'language' => 'PHP', 'fork' => true]);
        Repository::factory()->for($target)->missing()->create(['name' => 'gone', 'description' => null, 'stargazers_count' => 0, 'language' => 'PHP']);

        $names = fn (string $query) => collect($this->get("/targets/{$target->id}?{$query}")->viewData('page')['props']['repositories']['data'])->pluck('name')->all();

        $this->assertSame(['framework'], $names('search=FRAMEWORK'));
        $this->assertSame(['docs'], $names('search=documentation'));
        $this->assertSame(['docs', 'framework', 'forked', 'gone'], $names('sort=stars'));
        $this->assertSame(['docs', 'forked', 'framework', 'gone'], $names('sort=name'));
        $this->assertSame(['framework', 'forked', 'gone'], $names('sort=stars&language=PHP'));
        $this->assertSame(['docs'], $names('archived=only'));
        $this->assertSame(['framework', 'forked', 'gone'], $names('sort=stars&archived=hide'));
        $this->assertSame(['forked'], $names('fork=only'));
        $this->assertSame(['gone'], $names('missing=only'));
        $this->assertSame(['docs', 'framework', 'forked'], $names('sort=stars&missing=hide'));
    }

    public function test_repositories_without_a_value_sort_last_on_every_database()
    {
        $target = Target::factory()->create();
        Repository::factory()->for($target)->create(['name' => 'empty', 'pushed_at' => null]);
        Repository::factory()->for($target)->create(['name' => 'old', 'pushed_at' => now()->subYear()]);
        Repository::factory()->for($target)->create(['name' => 'new', 'pushed_at' => now()]);

        $names = collect($this->get("/targets/{$target->id}?sort=pushed")->viewData('page')['props']['repositories']['data'])->pluck('name')->all();

        $this->assertSame(['new', 'old', 'empty'], $names);
    }

    public function test_the_repository_list_is_paginated_and_ignores_invalid_query_values()
    {
        $target = Target::factory()->create();
        Repository::factory()->for($target)->count(12)->create();

        $this->get("/targets/{$target->id}?per_page=10&page=2")
            ->assertInertia(fn (Assert $page) => $page
                ->has('repositories.data', 2)
                ->where('repositories.current_page', 2)
                ->where('repositories.total', 12));

        $this->get("/targets/{$target->id}?sort[]=x&search[]=y&archived=sideways&per_page=7")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('query.sort', 'pushed')
                ->where('query.search', '')
                ->where('query.archived', '')
                ->where('repositories.per_page', 25));
    }

    public function test_the_sync_command_queues_every_target_or_the_given_ones()
    {
        Target::factory()->synced()->create(['name' => 'laravel']);
        Target::factory()->synced()->create(['name' => 'vuejs']);
        Target::factory()->create(['name' => 'busy', 'status' => TargetStatus::Syncing]);

        $this->artisan('targets:sync', ['name' => ['Laravel']])
            ->expectsOutputToContain('Queued 1 target(s), skipped 0 already in progress.')
            ->assertSuccessful();

        $this->artisan('targets:sync')
            ->expectsOutputToContain('Queued 1 target(s), skipped 2 already in progress.')
            ->assertSuccessful();

        Queue::assertPushed(SyncTargetRepositories::class, 2);
    }

    public function test_the_sync_command_is_scheduled_with_the_configured_cron()
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'targets:sync'));

        $this->assertNotNull($event);
        $this->assertSame(config('services.github.sync_schedule'), $event->expression);
    }
}
