<?php

namespace Tests\Feature;

use App\Models\Target;
use App\Models\User;
use Dotenv\Dotenv;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `php artisan app:setup`, run against a throwaway .env (never the real one), with the
 * external programs and GitHub faked. No RefreshDatabase: the command migrates itself.
 */
class SetupCommandTest extends TestCase
{
    private string $dir;

    private const DATABASE_CHOICES = [
        'sqlite' => 'SQLite (a file, no server needed)',
        'pgsql' => 'PostgreSQL',
        'mysql' => 'MySQL 8',
        'stop' => 'Stop here, I\'ll fix it myself',
    ];

    private const PASSWORD_CHOICES = [
        'generate' => 'Generate a strong password',
        'enter' => 'Enter a password',
        'keep' => 'Keep "password" (fine on your own machine only)',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/setup-'.Str::random(8));
        File::ensureDirectoryExists($this->dir);
        File::copy(base_path('.env.example'), $this->dir.'/.env');
        $this->app->useEnvironmentPath($this->dir);

        // Laravel matches fakes against the quoted command line, e.g. 'node' '--version'.
        Process::preventStrayProcesses();
        Process::fake([
            "'git' *" => Process::result('git version 2.43.0'),
            "'node' *" => Process::result('v22.22.3'),
            "'npm' *" => Process::result('10.9.0'),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $values
     */
    private function prepareEnv(array $values): void
    {
        Env::writeVariables($values, $this->dir.'/.env', overwrite: true);
    }

    private function env(string $key): ?string
    {
        return Dotenv::parse(File::get($this->dir.'/.env'))[$key] ?? null;
    }

    private function fakeGitHub(int $status = 200): void
    {
        Http::fake(['api.github.com/rate_limit' => $status === 200
            ? Http::response(['resources' => ['core' => ['limit' => 5000, 'remaining' => 4999, 'reset' => 1_800_000_000]]])
            : Http::response(['message' => 'Bad credentials'], $status)]);
    }

    public function test_a_fresh_checkout_is_set_up_without_questions_when_not_interactive()
    {
        Http::fake();

        $this->artisan('app:setup', ['--no-interaction' => true])
            ->expectsOutputToContain('No GITHUB_TOKEN')
            ->expectsOutputToContain('ADMIN_PASSWORD is still the default "password".')
            ->assertSuccessful();

        $this->assertStringStartsWith('base64:', $this->env('APP_KEY'));
        $this->assertSame('reverb', $this->env('BROADCAST_CONNECTION'));
        $this->assertNotEmpty($this->env('REVERB_APP_KEY'));
        // Expanded by Laravel when it loads .env, like `reverb:install` writes it.
        $this->assertStringContainsString('VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"', File::get($this->dir.'/.env'));
        // Kept as it was: without a terminal nothing is changed behind your back.
        $this->assertSame('password', $this->env('ADMIN_PASSWORD'));
        $this->assertTrue(Hash::check('password', User::where('username', 'admin')->value('password')));
        // No demo data without asking.
        $this->assertSame(0, Target::count());
        Http::assertNothingSent();
    }

    public function test_a_github_token_is_checked_with_github_and_saved()
    {
        $this->fakeGitHub();
        $this->prepareEnv(['ADMIN_PASSWORD' => 'a-strong-password', 'BROADCAST_CONNECTION' => 'reverb', 'REVERB_APP_KEY' => 'local-key']);

        $this->artisan('app:setup')
            ->expectsQuestion('Admin username', 'admin')
            ->expectsQuestion('GitHub token', 'ghp_valid')
            ->expectsOutputToContain('5,000 requests an hour, 4,999 left')
            ->expectsConfirmation('Load the demo data?', 'no')
            ->assertSuccessful();

        $this->assertSame('ghp_valid', $this->env('GITHUB_TOKEN'));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ghp_valid'));
    }

    public function test_a_rejected_token_is_asked_for_again_and_can_be_skipped()
    {
        $this->fakeGitHub(401);
        $this->prepareEnv(['ADMIN_PASSWORD' => 'a-strong-password', 'BROADCAST_CONNECTION' => 'reverb', 'REVERB_APP_KEY' => 'local-key']);

        $this->artisan('app:setup')
            ->expectsQuestion('Admin username', 'admin')
            ->expectsQuestion('GitHub token', 'ghp_wrong')
            ->expectsOutputToContain('GitHub rejected that token.')
            ->expectsQuestion('GitHub token', '')
            ->expectsConfirmation('Load the demo data?', 'no')
            ->expectsOutputToContain('No GitHub token: 60 GitHub requests an hour')
            ->assertSuccessful();

        $this->assertSame('', $this->env('GITHUB_TOKEN'));
    }

    public function test_the_default_admin_password_can_be_replaced_by_a_generated_one()
    {
        Http::fake();

        $this->artisan('app:setup')
            ->expectsQuestion('Admin username', 'arpi')
            ->expectsChoice('Admin password', 'generate', self::PASSWORD_CHOICES)
            ->expectsQuestion('GitHub token', '')
            ->expectsConfirmation('Set up Reverb for live updates?', 'yes')
            ->expectsConfirmation('Load the demo data?', 'no')
            ->assertSuccessful();

        $password = $this->env('ADMIN_PASSWORD');
        $this->assertSame('arpi', $this->env('ADMIN_USERNAME'));
        $this->assertSame(20, strlen($password));
        $this->assertTrue(Hash::check($password, User::where('username', 'arpi')->value('password')));
    }

    public function test_an_unreachable_database_can_be_switched_to_sqlite()
    {
        Http::fake();
        $sqlite = $this->dir.'/database.sqlite';
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => '1',
            'database.connections.sqlite.database' => $sqlite,
        ]);
        $this->prepareEnv([
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_DATABASE' => 'sm_task',
            'ADMIN_PASSWORD' => 'a-strong-password',
            'BROADCAST_CONNECTION' => 'reverb',
            'REVERB_APP_KEY' => 'local-key',
        ]);

        $this->artisan('app:setup')
            ->expectsChoice('Which database should the app use?', 'sqlite', self::DATABASE_CHOICES)
            ->expectsQuestion('Admin username', 'admin')
            ->expectsQuestion('GitHub token', '')
            ->expectsConfirmation('Load the demo data?', 'no')
            ->assertSuccessful();

        $this->assertSame('sqlite', $this->env('DB_CONNECTION'));
        // The server settings would point SQLite at a file named "sm_task".
        $this->assertNull($this->env('DB_DATABASE'));
        $this->assertStringContainsString('# DB_HOST=127.0.0.1', File::get($this->dir.'/.env'));
        $this->assertFileExists($sqlite);
        $this->assertTrue(User::where('username', 'admin')->exists());
    }

    public function test_a_finished_setup_asks_nothing_and_changes_nothing()
    {
        $this->fakeGitHub();
        $this->prepareEnv([
            'ADMIN_PASSWORD' => 'a-strong-password',
            'GITHUB_TOKEN' => 'ghp_valid',
            'BROADCAST_CONNECTION' => 'reverb',
            'REVERB_APP_KEY' => 'local-key',
        ]);
        $this->artisan('app:setup', ['--no-interaction' => true])->assertSuccessful();
        Target::factory()->create();
        $env = File::get($this->dir.'/.env');

        $this->artisan('app:setup')
            ->expectsOutputToContain('5,000 requests an hour')
            ->assertSuccessful();

        $this->assertSame($env, File::get($this->dir.'/.env'));
    }

    public function test_it_stops_when_node_is_missing()
    {
        Process::fake(["'node' *" => Process::result(errorOutput: 'node: not found', exitCode: 127)]);

        $this->artisan('app:setup', ['--no-interaction' => true])
            ->expectsOutputToContain('Node.js 18 or newer')
            ->assertFailed();

        // Stopped before touching .env.
        $this->assertSame('', $this->env('APP_KEY'));
    }
}
