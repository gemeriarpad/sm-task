<?php

namespace Tests\Feature\Clone;

use App\Services\Git\RepositoryCloner;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PurgeClonesTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = storage_path('framework/testing/clones-purge');
        File::deleteDirectory($this->base);
        File::ensureDirectoryExists($this->base.'/42/.git');
        File::ensureDirectoryExists($this->base.'/43.tmp-abc123');
        File::ensureDirectoryExists($this->base.'/not-a-clone');
        File::put($this->base.'/notes.txt', 'keep me');

        config(['services.github.clone_path' => $this->base]);
        $this->app->forgetInstance(RepositoryCloner::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);

        parent::tearDown();
    }

    public function test_only_clone_folders_are_purged()
    {
        $this->assertSame(2, app(RepositoryCloner::class)->purgeAll());

        $this->assertDirectoryDoesNotExist($this->base.'/42');
        $this->assertDirectoryDoesNotExist($this->base.'/43.tmp-abc123');
        $this->assertDirectoryExists($this->base.'/not-a-clone');
        $this->assertFileExists($this->base.'/notes.txt');
    }

    public function test_refreshing_the_database_purges_the_clones()
    {
        event(new DatabaseRefreshed);

        $this->assertDirectoryDoesNotExist($this->base.'/42');
    }

    public function test_tests_never_touch_the_real_clone_folder()
    {
        $this->assertSame(base_path('storage/framework/testing/clones'), env('GITHUB_CLONE_PATH') === null ? null : base_path(env('GITHUB_CLONE_PATH')));
    }
}
