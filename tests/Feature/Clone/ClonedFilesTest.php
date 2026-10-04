<?php

namespace Tests\Feature\Clone;

use App\Models\Repository;
use App\Models\User;
use App\Services\Git\RepositoryCloner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class ClonedFilesTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    private Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = storage_path('framework/testing/clones-files');
        File::deleteDirectory($this->base);
        config(['services.github.clone_path' => $this->base]);
        $this->app->forgetInstance(RepositoryCloner::class);

        $this->repository = Repository::factory()->create(['github_id' => 42, 'name' => 'framework']);

        // A small fake clone on disk.
        $root = $this->base.'/42';
        File::ensureDirectoryExists($root.'/.git');
        File::put($root.'/.git/HEAD', "ref: refs/heads/main\n");
        File::ensureDirectoryExists($root.'/src/Support');
        File::put($root.'/README.md', "# Framework\n");
        File::put($root.'/composer.json', '{}');
        File::put($root.'/src/App.php', "<?php\n");
        File::put($root.'/logo.png', "\x89PNG\r\n\x1a\n\0\0\0");
        File::put($root.'/big.txt', str_repeat('a', 600 * 1024));
        File::put($this->base.'/secret.txt', 'outside the clone');
        symlink($this->base.'/secret.txt', $root.'/escape');

        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);

        parent::tearDown();
    }

    private function treeUrl(string $path = ''): string
    {
        return "/repositories/{$this->repository->id}/tree?path=".urlencode($path);
    }

    private function fileUrl(string $path): string
    {
        return "/repositories/{$this->repository->id}/file?path=".urlencode($path);
    }

    public function test_the_root_lists_folders_first_and_hides_git()
    {
        $names = collect($this->getJson($this->treeUrl())->assertOk()->json('entries'))->pluck('name')->all();

        $this->assertSame(['src', 'big.txt', 'composer.json', 'escape', 'logo.png', 'README.md'], $names);
    }

    public function test_sub_folders_can_be_listed()
    {
        $this->getJson($this->treeUrl('src'))
            ->assertOk()
            ->assertJsonPath('entries.0', ['name' => 'Support', 'path' => 'src/Support', 'type' => 'dir', 'size' => null])
            ->assertJsonPath('entries.1', ['name' => 'App.php', 'path' => 'src/App.php', 'type' => 'file', 'size' => 6]);
    }

    public function test_text_files_can_be_previewed()
    {
        $this->getJson($this->fileUrl('README.md'))
            ->assertOk()
            ->assertExactJson(['path' => 'README.md', 'size' => 12, 'binary' => false, 'truncated' => false, 'content' => "# Framework\n"]);
    }

    public function test_binary_files_are_not_returned()
    {
        $this->getJson($this->fileUrl('logo.png'))->assertJsonPath('binary', true)->assertJsonPath('content', null);
    }

    public function test_large_files_are_truncated()
    {
        $response = $this->getJson($this->fileUrl('big.txt'))->assertJsonPath('truncated', true);

        $this->assertSame(512 * 1024, strlen($response->json('content')));
    }

    public function test_paths_outside_the_clone_and_git_internals_are_refused()
    {
        $this->getJson($this->fileUrl('../secret.txt'))->assertNotFound();
        $this->getJson($this->fileUrl('/etc/passwd'))->assertNotFound();
        $this->getJson($this->fileUrl('escape'))->assertNotFound();
        $this->getJson($this->fileUrl('.git/HEAD'))->assertNotFound();
        $this->getJson($this->treeUrl('.git'))->assertNotFound();
        $this->getJson($this->treeUrl('README.md'))->assertNotFound();
    }

    public function test_repositories_without_a_clone_have_no_files()
    {
        $other = Repository::factory()->create(['github_id' => 7]);

        $this->getJson("/repositories/{$other->id}/tree")->assertNotFound();
        $this->get("/repositories/{$other->id}/download")->assertNotFound();
    }

    public function test_the_clone_downloads_as_a_zip_including_git()
    {
        $response = $this->get("/repositories/{$this->repository->id}/download")->assertOk();

        $this->assertSame('attachment; filename=framework.zip', $response->headers->get('content-disposition'));

        $zip = new ZipArchive;
        $zip->open($response->getFile()->getPathname());
        $names = collect(range(0, $zip->numFiles - 1))->map(fn (int $i) => $zip->getNameIndex($i));
        $zip->close();

        $this->assertContains('framework/.git/HEAD', $names);
        $this->assertContains('framework/src/App.php', $names);
        $this->assertNotContains('framework/escape', $names);
    }

    public function test_guests_cannot_browse_clones()
    {
        auth()->logout();

        $this->getJson($this->treeUrl())->assertUnauthorized();
        $this->get("/repositories/{$this->repository->id}/download")->assertRedirect('/login');
    }
}
