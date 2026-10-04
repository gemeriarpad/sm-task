<?php

namespace Tests\Feature\Clone;

use App\Models\Repository;
use App\Services\Git\CloneException;
use App\Services\Git\RepositoryCloner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class RepositoryClonerTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = storage_path('framework/testing/clones-cloner');
        File::deleteDirectory($this->base);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);

        parent::tearDown();
    }

    public function test_a_new_repository_is_shallow_cloned_from_github()
    {
        Process::fake();
        $repository = Repository::factory()->create(['github_id' => 42, 'full_name' => 'laravel/framework']);

        (new RepositoryCloner($this->base))->cloneOrUpdate($repository);

        Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'git'
            && array_slice($process->command, 1, 6) === ['clone', '--depth', '1', '--single-branch', '--no-tags', 'https://github.com/laravel/framework.git']
            && str_starts_with($process->command[7], $this->base.'/42.tmp-')
            && $process->environment === ['GIT_TERMINAL_PROMPT' => '0']);
    }

    public function test_an_existing_clone_fetches_and_resets_to_the_default_branch()
    {
        Process::fake();
        $repository = Repository::factory()->create(['github_id' => 42, 'default_branch' => 'main', 'cloned_branch' => 'main']);
        File::ensureDirectoryExists($this->base.'/42/.git');

        (new RepositoryCloner($this->base))->cloneOrUpdate($repository);

        Process::assertRan(fn (PendingProcess $process) => $process->command === ['git', '-C', $this->base.'/42', 'fetch', '--depth', '1', '--no-tags', 'origin', 'main']);
        Process::assertRan(fn (PendingProcess $process) => $process->command === ['git', '-C', $this->base.'/42', 'reset', '--hard', 'FETCH_HEAD']);
        Process::assertDidntRun(fn (PendingProcess $process) => in_array('clone', $process->command, true));
    }

    public function test_a_chosen_branch_is_cloned_by_name()
    {
        Process::fake();
        $repository = Repository::factory()->create(['github_id' => 42, 'full_name' => 'laravel/framework', 'clone_branch' => '12.x']);

        (new RepositoryCloner($this->base))->cloneOrUpdate($repository);

        Process::assertRan(fn (PendingProcess $process) => array_slice($process->command, 1, 8) === [
            'clone', '--depth', '1', '--single-branch', '--no-tags', '--branch', '12.x', 'https://github.com/laravel/framework.git',
        ]);
    }

    public function test_a_clone_of_another_branch_is_replaced_by_a_fresh_clone()
    {
        Process::fake();
        $repository = Repository::factory()->create(['github_id' => 42, 'default_branch' => 'main', 'cloned_branch' => 'main', 'clone_branch' => '12.x']);
        File::ensureDirectoryExists($this->base.'/42/.git');

        (new RepositoryCloner($this->base))->cloneOrUpdate($repository);

        Process::assertRan(fn (PendingProcess $process) => in_array('clone', $process->command, true) && in_array('12.x', $process->command, true));
        Process::assertDidntRun(fn (PendingProcess $process) => in_array('fetch', $process->command, true));
    }

    public function test_a_chosen_branch_that_no_longer_exists_fails_with_a_readable_error()
    {
        Process::fake(['*' => Process::result(errorOutput: "Cloning into 'x'...\nwarning: Could not find remote branch gone to clone.\nfatal: Remote branch gone not found in upstream origin", exitCode: 128)]);
        $repository = Repository::factory()->create(['github_id' => 42, 'clone_branch' => 'gone']);

        $this->expectExceptionObject(new CloneException('The branch "gone" no longer exists on GitHub. Pick another branch.'));

        (new RepositoryCloner($this->base))->cloneOrUpdate($repository);
    }

    public function test_temporary_folders_of_an_interrupted_clone_are_removed()
    {
        Process::fake();
        $repository = Repository::factory()->create(['github_id' => 42]);
        File::ensureDirectoryExists($this->base.'/42.tmp-abc12345/.git');

        (new RepositoryCloner($this->base))->cloneOrUpdate($repository);

        $this->assertDirectoryDoesNotExist($this->base.'/42.tmp-abc12345');
    }

    public function test_unexpected_repository_names_are_never_passed_to_git()
    {
        Process::fake();
        $repository = Repository::factory()->create(['full_name' => 'evil/--upload-pack=touch /tmp/pwned']);

        $this->expectException(CloneException::class);

        try {
            (new RepositoryCloner($this->base))->cloneOrUpdate($repository);
        } finally {
            Process::assertNothingRan();
        }
    }
}
