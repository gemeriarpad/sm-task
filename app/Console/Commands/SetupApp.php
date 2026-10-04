<?php

namespace App\Console\Commands;

use App\Models\Target;
use App\Models\User;
use App\Services\GitHub\GitHubClient;
use App\Services\GitHub\GitHubException;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DemoSeeder;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * `composer setup` (after `composer install` and copying .env): checks what the app
 * needs, fixes what it can and asks only for what is missing. Safe to run again.
 * With --no-interaction it keeps the current values and only warns.
 */
class SetupApp extends Command
{
    protected $signature = 'app:setup';

    protected $description = 'Check the requirements and set up .env, the database, the admin login, the GitHub token, Reverb, the frontend and demo data';

    /**
     * The PHP extensions Laravel needs (the database driver is checked separately).
     */
    private const REQUIRED_EXTENSIONS = ['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'mbstring', 'openssl', 'pdo', 'session', 'tokenizer', 'xml'];

    private const DRIVERS = [
        'sqlite' => ['label' => 'SQLite (a file, no server needed)', 'extension' => 'pdo_sqlite'],
        'pgsql' => ['label' => 'PostgreSQL', 'extension' => 'pdo_pgsql', 'port' => '5432'],
        'mysql' => ['label' => 'MySQL 8', 'extension' => 'pdo_mysql', 'port' => '3306'],
    ];

    /**
     * Things that work, but not fully; repeated at the end.
     *
     * @var list<string>
     */
    private array $warnings = [];

    public function handle(): int
    {
        if ($this->laravel->configurationIsCached()) {
            $this->callSilently('config:clear');
            $this->components->error('The configuration was cached, so .env was ignored. The cache is cleared now: run `composer setup` again.');

            return self::FAILURE;
        }

        foreach (['requirements', 'environment', 'database', 'admin', 'githubToken', 'reverb', 'frontend', 'demoData'] as $step) {
            if (! $this->{$step}()) {
                return self::FAILURE;
            }
        }

        if ($this->warnings !== []) {
            $this->components->warn('Set up, with these limits:');
            $this->components->bulletList($this->warnings);
        }

        $this->components->info('Done. Start the app with `composer dev`, open http://127.0.0.1:8000 and log in as '.config('app.admin.username').'.');

        return self::SUCCESS;
    }

    private function requirements(): bool
    {
        $this->heading('Requirements');
        $this->components->twoColumnDetail('PHP', PHP_VERSION);

        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, fn (string $extension) => ! extension_loaded($extension)));

        if ($missing !== []) {
            $this->components->error('Missing PHP extensions: '.implode(', ', $missing).'. Install them (e.g. `sudo apt install php-xml php-curl`) and run `composer setup` again.');

            return false;
        }

        if (! extension_loaded('zip')) {
            $this->components->warn('The PHP zip extension is missing: clones can\'t be downloaded as a zip.');
        }

        if (! extension_loaded('pcntl') && PHP_OS_FAMILY !== 'Windows') {
            $this->components->warn('The PHP pcntl extension is missing: queue workers can\'t stop jobs that run past their timeout.');
        }

        $git = $this->version(['git', '--version']);
        $git === null
            ? $this->components->warn('git is not installed: repositories can\'t be cloned and the branch list stays empty.')
            : $this->components->twoColumnDetail('git', Str::after($git, 'git version '));

        $node = $this->version(['node', '--version']);
        $npm = $this->version(['npm', '--version']);

        if ($node === null || $npm === null) {
            $this->components->error('Node.js 18 or newer with npm is needed for the frontend: https://nodejs.org. Install it and run `composer setup` again.');

            return false;
        }

        if ((int) ltrim($node, 'v') < 18) {
            $this->components->error("Node.js {$node} is too old: the frontend needs 18 or newer.");

            return false;
        }

        $this->components->twoColumnDetail('Node.js / npm', "{$node} / {$npm}");

        return true;
    }

    private function environment(): bool
    {
        $this->heading('.env');
        $path = $this->laravel->environmentFilePath();

        if (File::missing($path)) {
            if (File::missing(base_path('.env.example'))) {
                $this->components->error('Neither .env nor .env.example exists.');

                return false;
            }

            File::copy(base_path('.env.example'), $path);
            $this->components->twoColumnDetail('.env', 'created from .env.example');
        }

        if (blank($this->env('APP_KEY'))) {
            $key = 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));
            $this->writeEnv(['APP_KEY' => $key]);
            config(['app.key' => $key]);
            $this->components->twoColumnDetail('APP_KEY', 'generated');
        } else {
            $this->components->twoColumnDetail('APP_KEY', 'set');
        }

        return true;
    }

    private function database(): bool
    {
        $this->heading('Database');
        $connection = config('database.default');
        $changes = [];

        while (($error = $this->connectionError($connection)) !== null) {
            $this->components->error($error);

            if (! $this->interactive()) {
                return false;
            }

            $connection = select(
                label: 'Which database should the app use?',
                options: [...array_map(fn (array $driver) => $driver['label'], self::DRIVERS), 'stop' => 'Stop here, I\'ll fix it myself'],
                default: $connection,
            );

            if ($connection === 'stop') {
                return false;
            }

            $changes = $this->askForConnection($connection);
        }

        if ($changes !== []) {
            $this->writeEnv($changes);

            if ($connection === 'sqlite') {
                $this->commentOutEnv(['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']);
            }
        }

        $this->components->twoColumnDetail('Connection', $this->describeConnection($connection));

        try {
            $this->call('migrate', ['--force' => true]);
        } catch (Throwable $e) {
            $this->components->error('The migrations failed: '.Str::before($e->getMessage(), "\n"));

            return false;
        }

        return true;
    }

    /**
     * Why the connection doesn't work, or null when it does. Creates a missing SQLite file.
     */
    private function connectionError(string $connection): ?string
    {
        $driver = config("database.connections.{$connection}.driver");
        $extension = self::DRIVERS[$driver]['extension'] ?? null;

        if ($extension !== null && ! extension_loaded($extension)) {
            return "The PHP extension {$extension} is missing, so {$driver} can't be used.";
        }

        if ($driver === 'sqlite') {
            $path = config("database.connections.{$connection}.database");

            if ($path !== ':memory:' && File::missing($path)) {
                File::ensureDirectoryExists(dirname($path));
                File::put($path, '');
                $this->components->twoColumnDetail('SQLite file', 'created '.Str::after($path, base_path().DIRECTORY_SEPARATOR));
            }
        }

        try {
            DB::connection($connection)->getPdo();

            return null;
        } catch (Throwable $e) {
            return 'Can\'t connect to the database: '.Str::of($e->getMessage())->before("\n")->limit(300);
        }
    }

    /**
     * Ask for the connection details, apply them to this run and return them for .env.
     *
     * @return array<string, string>
     */
    private function askForConnection(string $connection): array
    {
        if ($connection === 'sqlite') {
            $path = (string) config('database.connections.sqlite.database');
            $path = $path === ':memory:' || str_ends_with($path, '.sqlite') ? $path : database_path('database.sqlite');
            config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $path]);
            DB::purge('sqlite');

            return ['DB_CONNECTION' => 'sqlite'];
        }

        $current = config("database.connections.{$connection}");
        $this->line('  Create the database and its user first, for example:');
        $this->line($connection === 'pgsql'
            ? "    sudo -u postgres psql -c \"CREATE ROLE sm_task LOGIN PASSWORD '…' CREATEDB;\" -c \"CREATE DATABASE sm_task OWNER sm_task;\""
            : "    sudo mysql -e \"CREATE DATABASE sm_task; CREATE USER 'sm_task'@'localhost' IDENTIFIED BY '…'; GRANT ALL ON sm_task.* TO 'sm_task'@'localhost';\"");

        $values = [
            'host' => text('Host', default: (string) $current['host'], required: true),
            'port' => text('Port', default: (string) ($current['port'] ?: self::DRIVERS[$connection]['port']), required: true),
            'database' => text('Database name', default: $current['database'] === 'laravel' ? 'sm_task' : (string) $current['database'], required: true),
            'username' => text('Username', default: $current['username'] === 'root' ? 'sm_task' : (string) $current['username'], required: true),
            'password' => password('Password'),
        ];

        config(['database.default' => $connection]);
        config(collect($values)->mapWithKeys(fn (string $value, string $key) => ["database.connections.{$connection}.{$key}" => $value])->all());
        DB::purge($connection);

        return [
            'DB_CONNECTION' => $connection,
            'DB_HOST' => $values['host'],
            'DB_PORT' => $values['port'],
            'DB_DATABASE' => $values['database'],
            'DB_USERNAME' => $values['username'],
            'DB_PASSWORD' => $values['password'],
        ];
    }

    private function describeConnection(string $connection): string
    {
        $config = config("database.connections.{$connection}");

        return $config['driver'] === 'sqlite'
            ? 'SQLite, '.Str::after($config['database'], base_path().DIRECTORY_SEPARATOR)
            : "{$config['driver']}, {$config['database']} on {$config['host']}:{$config['port']}";
    }

    private function admin(): bool
    {
        $this->heading('Admin login');
        $username = $this->env('ADMIN_USERNAME') ?: 'admin';
        $password = (string) $this->env('ADMIN_PASSWORD');
        $exists = User::where('username', $username)->exists();
        $weak = $password === '' || $password === 'password';

        if ($exists && ! $weak) {
            $this->components->twoColumnDetail("User {$username}", 'exists');

            return true;
        }

        $interactive = $this->interactive();

        if (! $exists && $interactive) {
            $username = text('Admin username', default: $username, required: true);
        }

        if ($weak) {
            $this->components->warn($password === '' ? 'ADMIN_PASSWORD is empty.' : 'ADMIN_PASSWORD is still the default "password".');

            $choice = $interactive
                ? select('Admin password', [
                    'generate' => 'Generate a strong password',
                    'enter' => 'Enter a password',
                    'keep' => 'Keep "password" (fine on your own machine only)',
                ], default: 'generate')
                : ($password === '' ? 'generate' : 'keep');

            if ($choice === 'generate') {
                $password = Str::password(20, symbols: false);
                $this->components->info("Generated password for {$username}: {$password} (saved as ADMIN_PASSWORD in .env)");
            } elseif ($choice === 'enter') {
                $password = password('Admin password', required: true, validate: fn (string $value) => mb_strlen($value) < 8 ? 'Use at least 8 characters.' : null);
            } elseif ($password === 'password') {
                $this->warnings[] = 'The admin password is "password".';
            }
        }

        $this->writeEnv(['ADMIN_USERNAME' => $username, 'ADMIN_PASSWORD' => $password]);
        config(['app.admin.username' => $username, 'app.admin.password' => $password]);
        $this->callSilently('db:seed', ['--class' => AdminUserSeeder::class, '--force' => true]);
        $this->components->twoColumnDetail("User {$username}", $exists ? 'password updated' : 'created');

        return true;
    }

    private function githubToken(): bool
    {
        $this->heading('GitHub token');
        $token = (string) $this->env('GITHUB_TOKEN');
        // Whether .env has a token at all, and whether $token was just entered.
        $inEnv = $token !== '';
        $entered = false;

        while (true) {
            if ($token === '') {
                if (! $inEnv) {
                    $this->components->warn('No GITHUB_TOKEN: GitHub allows 60 requests an hour from your IP address, for every sync and every Check together.');
                }

                $this->line('  A token raises the limit to 5,000 an hour, and unchanged pages cost nothing. Create one at https://github.com/settings/tokens');
                $this->line('  (fine-grained with read-only access to public repositories, or classic without any scopes).');

                $token = $this->interactive() ? trim(password('GitHub token', hint: 'Press Enter to skip.')) : '';

                if ($token === '') {
                    $this->warnings[] = $inEnv
                        ? 'GitHub rejects the GITHUB_TOKEN in .env: every sync fails until it is replaced.'
                        : 'No GitHub token: 60 GitHub requests an hour.';

                    return true;
                }

                $entered = true;
            }

            $result = $this->checkToken($token);

            if (is_array($result)) {
                if ($entered) {
                    $this->saveToken($token);
                }

                $this->components->twoColumnDetail('GITHUB_TOKEN', number_format($result['limit']).' requests an hour, '.number_format($result['remaining']).' left');

                return true;
            }

            if ($result === 'unreachable') {
                $this->components->warn('Could not reach GitHub to check the token.');

                if ($entered && confirm('Save it anyway?', default: true)) {
                    $this->saveToken($token);
                }

                return true;
            }

            $this->components->error($entered ? 'GitHub rejected that token.' : 'GitHub rejects the GITHUB_TOKEN in .env.');

            if (! $this->interactive()) {
                $this->warnings[] = 'GitHub rejects the GITHUB_TOKEN in .env: every sync fails until it is replaced.';

                return true;
            }

            $token = '';
        }
    }

    /**
     * The token's rate limit, or why it couldn't be checked: 'rejected' or 'unreachable'.
     *
     * @return array{limit: int, remaining: int, reset: int}|string
     */
    private function checkToken(string $token): array|string
    {
        try {
            return (new GitHubClient($token))->rateLimit();
        } catch (GitHubException $e) {
            return $e->getCode() === 401 ? 'rejected' : 'unreachable';
        }
    }

    private function saveToken(string $token): void
    {
        $this->writeEnv(['GITHUB_TOKEN' => $token]);
        config(['services.github.token' => $token]);
        $this->laravel->forgetInstance(GitHubClient::class);
    }

    private function reverb(): bool
    {
        $this->heading('Live updates (Reverb)');

        if (filled($this->env('REVERB_APP_KEY')) && $this->env('BROADCAST_CONNECTION') === 'reverb') {
            $this->components->twoColumnDetail('Reverb', 'configured');

            return true;
        }

        $this->line('  Without Reverb the pages poll every 3 seconds while a sync runs, instead of updating live.');

        if ($this->interactive() && ! confirm('Set up Reverb for live updates?', default: true)) {
            $this->warnings[] = 'No Reverb: pages poll instead of updating live.';

            return true;
        }

        if (blank($this->env('REVERB_APP_KEY'))) {
            // The same local keys `php artisan reverb:install` would add.
            $this->writeEnv([
                'REVERB_APP_ID' => (string) random_int(100_000, 999_999),
                'REVERB_APP_KEY' => Str::lower(Str::random(20)),
                'REVERB_APP_SECRET' => Str::lower(Str::random(20)),
                'REVERB_HOST' => 'localhost',
                'REVERB_PORT' => '8080',
                'REVERB_SCHEME' => 'http',
                'VITE_REVERB_APP_KEY' => '${REVERB_APP_KEY}',
                'VITE_REVERB_HOST' => '${REVERB_HOST}',
                'VITE_REVERB_PORT' => '${REVERB_PORT}',
                'VITE_REVERB_SCHEME' => '${REVERB_SCHEME}',
            ]);
        }

        $this->writeEnv(['BROADCAST_CONNECTION' => 'reverb']);
        $this->components->twoColumnDetail('Reverb', 'keys generated, BROADCAST_CONNECTION=reverb');

        return true;
    }

    private function frontend(): bool
    {
        $this->heading('Frontend');
        $installed = base_path('node_modules/.package-lock.json');

        if (File::exists($installed) && File::lastModified($installed) >= File::lastModified(base_path('package-lock.json'))) {
            $this->components->twoColumnDetail('Node packages', 'up to date');

            return true;
        }

        $result = Process::path(base_path())->forever()->run(['npm', 'install'], fn (string $type, string $output) => $this->output->write($output));

        if ($result->failed()) {
            $this->components->error('`npm install` failed; see the output above.');

            return false;
        }

        $this->components->twoColumnDetail('Node packages', 'installed');

        return true;
    }

    private function demoData(): bool
    {
        $this->heading('Demo data');

        if (($count = Target::count()) > 0) {
            $this->components->twoColumnDetail('Demo data', "skipped, {$count} targets exist");

            return true;
        }

        if (! $this->interactive()) {
            $this->components->twoColumnDetail('Demo data', 'not loaded; `php artisan db:seed --class=DemoSeeder` loads it');

            return true;
        }

        $this->line('  12 real GitHub accounts and one that doesn\'t exist, synced for real and put into every state the UI shows.');
        $this->line('  Needs network access and takes about 20 seconds'.(blank(config('services.github.token')) ? '; without a token it uses most of the hourly GitHub limit.' : '.'));

        // Never without asking: it calls GitHub and clones repositories.
        if (! confirm('Load the demo data?', default: true)) {
            return true;
        }

        try {
            $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        } catch (Throwable $e) {
            $this->warnings[] = 'The demo data failed to load: '.Str::before($e->getMessage(), "\n");
        }

        return true;
    }

    /**
     * The first line of `$command --version`, or null when it can't run.
     *
     * @param  list<string>  $command
     */
    private function version(array $command): ?string
    {
        try {
            $result = Process::timeout(10)->run($command);
        } catch (Throwable) {
            return null;
        }

        return $result->successful() ? trim(Str::before($result->output(), "\n")) : null;
    }

    /**
     * A value as written in .env right now (not the cached or overridden config).
     */
    private function env(string $key): ?string
    {
        $values = Dotenv::parse(File::get($this->laravel->environmentFilePath()));

        return $values[$key] ?? null;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function writeEnv(array $values): void
    {
        Env::writeVariables($values, $this->laravel->environmentFilePath(), overwrite: true);
    }

    /**
     * Turn `KEY=value` lines into comments, e.g. the server settings when switching to SQLite.
     *
     * @param  list<string>  $keys
     */
    private function commentOutEnv(array $keys): void
    {
        $path = $this->laravel->environmentFilePath();
        $pattern = '/^(('.implode('|', array_map('preg_quote', $keys)).')=.*)$/m';

        File::put($path, preg_replace($pattern, '# $1', File::get($path)));
    }

    /**
     * Whether someone can answer. Without a terminal (CI, a pipe, some Composer setups)
     * Symfony may still call the input interactive while Laravel Prompts silently
     * returns the defaults, so check for a terminal like Prompts does.
     */
    private function interactive(): bool
    {
        return $this->input->isInteractive()
            && ($this->laravel->runningUnitTests() || (defined('STDIN') && stream_isatty(STDIN)));
    }

    private function heading(string $title): void
    {
        $this->newLine();
        $this->line("  <options=bold>{$title}</>");
    }
}
