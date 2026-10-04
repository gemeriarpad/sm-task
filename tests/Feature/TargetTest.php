<?php

namespace Tests\Feature;

use App\Enums\CloneMode;
use App\Enums\CloneStatus;
use App\Enums\RepositoryScope;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Jobs\SyncTargetRepositories;
use App\Models\Repository;
use App\Models\Target;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TargetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Saving targets can queue syncs; keep them from running.
        Queue::fake();

        $this->actingAs(User::factory()->create());
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        auth()->logout();

        $this->get('/targets')->assertRedirect('/login');
        $this->get('/targets/create')->assertRedirect('/login');
        $this->post('/targets', ['name' => 'laravel', 'type' => 'organization'])->assertRedirect('/login');
    }

    public function test_index_lists_targets()
    {
        Target::factory()->create(['name' => 'laravel', 'type' => TargetType::Organization]);
        Target::factory()->failed('Not found')->create(['name' => 'taylorotwell', 'type' => TargetType::User]);

        $this->get('/targets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('targets/Index')
                ->has('targets.data', 2)
                ->where('targets.data.0.name', 'laravel')
                ->where('targets.data.0.type', 'organization')
                ->where('targets.data.0.status', 'queued')
                ->where('targets.data.1.last_error', 'Not found')
                ->where('sort', 'name')
                ->where('direction', 'asc')
                ->has('options.types', 2)
                ->has('options.statuses', 5));
    }

    public function test_index_shows_repository_exclusion_and_clone_counts()
    {
        $target = Target::factory()->synced()->create(['name' => 'laravel', 'clone_repositories' => true, 'github_repositories_count' => 6]);
        Repository::factory()->for($target)->create(['clone_status' => CloneStatus::Cloned]);
        Repository::factory()->for($target)->create(['clone_status' => CloneStatus::Failed]);
        Repository::factory()->for($target)->create(['clone_mode' => CloneMode::Never, 'clone_status' => CloneStatus::Cloned]);
        Repository::factory()->for($target)->create(['excluded_at' => now()]);
        Repository::factory()->for($target)->missing()->create();
        Target::factory()->create(['name' => 'vuejs']);

        $this->get('/targets')->assertInertia(fn (Assert $page) => $page
            ->where('targets.data.0.name', 'laravel')
            // On GitHub and not excluded.
            ->where('targets.data.0.repositories_count', 3)
            ->where('targets.data.0.github_repositories_count', 6)
            ->where('targets.data.0.excluded_count', 1)
            // Cloning is on for the target, so "inherit" counts; "never" and excluded don't.
            ->where('targets.data.0.clones_enabled_count', 2)
            ->where('targets.data.0.clones_cloned_count', 1)
            ->where('targets.data.1.github_repositories_count', null)
            ->where('targets.data.1.clones_enabled_count', 0));

        $this->get('/targets?sort=repositories_count&direction=desc')
            ->assertInertia(fn (Assert $page) => $page->where('targets.data.0.name', 'laravel')->where('sort', 'repositories_count'));
    }

    public function test_index_filters_by_name_type_and_status()
    {
        Target::factory()->create(['name' => 'laravel', 'type' => TargetType::Organization]);
        Target::factory()->create(['name' => 'laravel-shift', 'type' => TargetType::User]);
        Target::factory()->synced()->create(['name' => 'vuejs', 'type' => TargetType::Organization]);

        $this->get('/targets?filter[name]=LARA')
            ->assertInertia(fn (Assert $page) => $page->has('targets.data', 2)->where('filters.name', 'LARA'));

        $this->get('/targets?filter[type]=organization')
            ->assertInertia(fn (Assert $page) => $page->has('targets.data', 2));

        $this->get('/targets?filter[status]=synced')
            ->assertInertia(fn (Assert $page) => $page->has('targets.data', 1)->where('targets.data.0.name', 'vuejs'));
    }

    public function test_index_sorts_and_ignores_unknown_sort_columns()
    {
        Target::factory()->create(['name' => 'alpha']);
        Target::factory()->synced()->create(['name' => 'beta']);

        $this->get('/targets?sort=name&direction=desc')
            ->assertInertia(fn (Assert $page) => $page->where('targets.data.0.name', 'beta')->where('direction', 'desc'));

        $this->get('/targets?sort=last_synced_at&direction=desc')
            ->assertInertia(fn (Assert $page) => $page->where('targets.data.1.last_synced_at', null));

        $this->get('/targets?sort=password&direction=sideways')
            ->assertInertia(fn (Assert $page) => $page->where('sort', 'name')->where('direction', 'asc'));
    }

    public function test_create_page_can_be_rendered()
    {
        $this->get('/targets/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('targets/Create')->has('options.types', 2));
    }

    public function test_a_target_can_be_added()
    {
        $this->post('/targets', ['name' => 'Laravel', 'type' => 'organization'])
            ->assertRedirect(route('targets.index'));

        $target = Target::sole();
        $this->assertSame('Laravel', $target->name);
        $this->assertSame(TargetType::Organization, $target->type);
        // Adding a target queues its first sync right away.
        $this->assertSame(TargetStatus::Queued, $target->status);
        $this->assertNull($target->last_synced_at);
        $this->assertNull($target->last_error);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidTargets(): array
    {
        return [
            'missing name' => [['type' => 'user'], 'name'],
            'name with spaces' => [['name' => 'two words', 'type' => 'user'], 'name'],
            'name starting with hyphen' => [['name' => '-laravel', 'type' => 'user'], 'name'],
            'name ending with hyphen' => [['name' => 'laravel-', 'type' => 'user'], 'name'],
            'name with invalid characters' => [['name' => 'lara_vel', 'type' => 'user'], 'name'],
            'name longer than 39 characters' => [['name' => str_repeat('a', 40), 'type' => 'user'], 'name'],
            'missing type' => [['name' => 'laravel'], 'type'],
            'unknown type' => [['name' => 'laravel', 'type' => 'team'], 'type'],
        ];
    }

    #[DataProvider('invalidTargets')]
    public function test_invalid_targets_are_rejected(array $data, string $field)
    {
        $this->post('/targets', $data)->assertSessionHasErrors($field);

        $this->assertDatabaseCount('targets', 0);
    }

    public function test_a_name_may_only_be_added_once_regardless_of_case()
    {
        Target::factory()->create(['name' => 'laravel']);

        $this->post('/targets', ['name' => 'LaRaVeL', 'type' => 'organization'])
            ->assertSessionHasErrors(['name' => 'This target has already been added.']);

        $this->assertDatabaseCount('targets', 1);
    }

    public function test_the_database_rejects_case_insensitive_duplicates()
    {
        Target::factory()->create(['name' => 'laravel']);

        $this->expectException(UniqueConstraintViolationException::class);

        Target::factory()->create(['name' => 'Laravel']);
    }

    public function test_precognition_validates_a_single_field_without_saving()
    {
        Target::factory()->create(['name' => 'laravel']);

        $this->withHeaders(['Precognition' => 'true', 'Precognition-Validate-Only' => 'name'])
            ->postJson('/targets', ['name' => 'Laravel'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name')
            ->assertJsonMissingValidationErrors('type');

        $this->withHeaders(['Precognition' => 'true', 'Precognition-Validate-Only' => 'name'])
            ->postJson('/targets', ['name' => 'vuejs'])
            ->assertNoContent();

        $this->assertDatabaseCount('targets', 1);
    }

    public function test_edit_page_can_be_rendered()
    {
        $target = Target::factory()->create(['name' => 'laravel', 'type' => TargetType::Organization]);

        $this->get("/targets/{$target->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('targets/Edit')
                ->where('target.id', $target->id)
                ->where('target.name', 'laravel')
                ->where('target.type', 'organization'));
    }

    public function test_the_edit_page_explains_what_changing_the_settings_does()
    {
        $target = Target::factory()->create();
        Repository::factory()->for($target)->create(['fork' => true, 'cloned_at' => now()]);
        Repository::factory()->for($target)->create(['fork' => true]);
        Repository::factory()->for($target)->create(['cloned_at' => now()]);
        Repository::factory()->for($target)->create(['clone_mode' => CloneMode::Always]);
        Repository::factory()->for($target)->missing()->create();

        $this->get("/targets/{$target->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->where('impact.forks_count', 2)
                ->where('impact.fork_clones_count', 1)
                ->where('impact.inherit_count', 3)
                ->where('impact.inherit_clones_count', 2)
                ->where('impact.always_count', 1));
    }

    public function test_the_settings_of_a_target_can_be_updated()
    {
        $target = Target::factory()->synced()->create(['name' => 'laravel', 'type' => TargetType::Organization]);

        $this->patch("/targets/{$target->id}", ['repository_scope' => 'all', 'clone_repositories' => true])
            ->assertRedirect(route('targets.index'));

        $target->refresh();
        $this->assertSame(RepositoryScope::All, $target->repository_scope);
        $this->assertTrue($target->clone_repositories);
    }

    public function test_the_name_and_type_cannot_be_changed()
    {
        $target = Target::factory()->create(['name' => 'laravel', 'type' => TargetType::User]);

        $this->patch("/targets/{$target->id}", ['name' => 'vuejs', 'type' => 'organization'])
            ->assertSessionHasErrors([
                'name' => 'The GitHub name cannot be changed. Delete the target and add a new one instead.',
                'type' => 'The type cannot be changed. Delete the target and add a new one instead.',
            ]);

        $this->assertSame('laravel', $target->fresh()->name);
        $this->assertSame(TargetType::User, $target->fresh()->type);
    }

    public function test_changing_what_gets_synced_queues_a_sync()
    {
        $target = Target::factory()->synced()->create(['name' => 'laravel', 'type' => TargetType::User]);

        // Unchanged settings, or cloning turned off: nothing to sync.
        $this->patch("/targets/{$target->id}", ['repository_scope' => 'sources', 'clone_repositories' => false])->assertRedirect();
        $target->update(['clone_repositories' => true]);
        $this->patch("/targets/{$target->id}", ['clone_repositories' => false])->assertRedirect();
        Queue::assertNothingPushed();

        $this->patch("/targets/{$target->id}", ['clone_repositories' => true])->assertRedirect();
        Queue::assertPushed(SyncTargetRepositories::class, 1);
        $this->assertSame(TargetStatus::Queued, $target->fresh()->status);
    }

    public function test_editing_an_unknown_target_returns_not_found()
    {
        $this->get('/targets/999/edit')->assertNotFound();
    }
}
