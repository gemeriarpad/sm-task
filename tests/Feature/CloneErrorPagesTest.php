<?php

namespace Tests\Feature;

use App\Enums\CloneMode;
use App\Enums\CloneStatus;
use App\Models\Repository;
use App\Models\Target;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CloneErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    private Target $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->target = Target::factory()->synced()->create(['name' => 'laravel', 'clone_repositories' => true]);
    }

    private function failed(string $name, array $attributes = []): Repository
    {
        return Repository::factory()->for($this->target)->create([
            'name' => $name,
            'full_name' => "laravel/{$name}",
            'clone_status' => CloneStatus::Failed,
            'clone_error' => 'Could not reach GitHub.',
            ...$attributes,
        ]);
    }

    public function test_failed_and_too_large_clones_are_listed_with_their_message()
    {
        $this->failed('broken');
        $this->failed('huge', [
            'clone_status' => CloneStatus::Skipped,
            'clone_error' => 'Not cloned: GitHub reports 6251 MB, the limit is 512 MB (GITHUB_CLONE_MAX_SIZE_MB).',
            'size_kb' => 6_400_000,
        ]);
        $this->failed('fine', ['clone_status' => CloneStatus::Cloned, 'clone_error' => null]);

        $this->get('/sync-runs/errors/clones')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clone-errors/Index')
                ->has('repositories.data', 2)
                ->where('repositories.data.0.full_name', 'laravel/broken')
                ->where('repositories.data.0.clone_status', 'failed')
                ->where('repositories.data.0.clone_error', 'Could not reach GitHub.')
                ->where('repositories.data.1.full_name', 'laravel/huge')
                ->where('repositories.data.1.clone_status', 'skipped')
                ->where('repositories.data.1.clone_error', 'Not cloned: GitHub reports 6251 MB, the limit is 512 MB (GITHUB_CLONE_MAX_SIZE_MB).')
                ->where('repositories.data.1.target.name', 'laravel')
                ->has('options.kinds', 2));
    }

    public function test_only_clones_that_are_still_wanted_are_listed()
    {
        $this->failed('wanted');
        $this->failed('excluded', ['excluded_at' => now()]);
        $this->failed('removed', ['missing_at' => now()]);
        $this->failed('never', ['clone_mode' => CloneMode::Never]);

        // Cloning turned off for the target: only "always" repositories still count.
        $quiet = Target::factory()->synced()->create(['name' => 'quiet', 'clone_repositories' => false]);
        Repository::factory()->for($quiet)->create(['full_name' => 'quiet/inherit', 'clone_status' => CloneStatus::Failed]);
        Repository::factory()->for($quiet)->create(['full_name' => 'quiet/always', 'clone_status' => CloneStatus::Failed, 'clone_mode' => CloneMode::Always]);

        // A deactivated target isn't cloned at all.
        $inactive = Target::factory()->inactive()->create(['clone_repositories' => true]);
        Repository::factory()->for($inactive)->create(['clone_status' => CloneStatus::Failed]);

        $this->get('/sync-runs/errors/clones')
            ->assertInertia(fn (Assert $page) => $page
                ->has('repositories.data', 2)
                ->where('repositories.data.0.full_name', 'laravel/wanted')
                ->where('repositories.data.1.full_name', 'quiet/always'));
    }

    public function test_clone_errors_can_be_filtered_and_sorted()
    {
        $this->failed('broken', ['size_kb' => 10]);
        $this->failed('huge', ['clone_status' => CloneStatus::Skipped, 'size_kb' => 6_400_000]);
        $other = Target::factory()->synced()->create(['name' => 'acme', 'clone_repositories' => true]);
        Repository::factory()->for($other)->create(['full_name' => 'acme/tool', 'clone_status' => CloneStatus::Failed]);

        $this->get('/sync-runs/errors/clones?filter[kind]=skipped')
            ->assertInertia(fn (Assert $page) => $page->has('repositories.data', 1)->where('repositories.data.0.full_name', 'laravel/huge'));

        $this->get("/sync-runs/errors/clones?filter[target]={$other->id}")
            ->assertInertia(fn (Assert $page) => $page->has('repositories.data', 1)->where('repositories.data.0.full_name', 'acme/tool'));

        $this->get('/sync-runs/errors/clones?sort=size&direction=desc')
            ->assertInertia(fn (Assert $page) => $page
                ->where('repositories.data.0.full_name', 'laravel/huge')
                ->where('repositories.data.1.full_name', 'laravel/broken'));

        // Values that can't match are ignored.
        $this->get('/sync-runs/errors/clones?filter[kind]=cloned&filter[target]=abc')
            ->assertInertia(fn (Assert $page) => $page->has('repositories.data', 3)->where('filters', []));
    }

    public function test_guests_cannot_see_clone_errors()
    {
        auth()->logout();

        $this->get('/sync-runs/errors/clones')->assertRedirect('/login');
    }
}
