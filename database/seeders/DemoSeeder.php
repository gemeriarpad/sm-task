<?php

namespace Database\Seeders;

use App\Enums\CloneMode;
use App\Enums\RepositoryScope;
use App\Enums\SyncRunStatus;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Jobs\SyncTargetRepositories;
use App\Models\Repository;
use App\Models\SyncRun;
use App\Models\Target;
use App\Services\TargetLifecycle;
use Carbon\CarbonInterface;
use Illuminate\Console\View\TaskResult;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Demo data for local development: real, active GitHub accounts, synced for real and
 * then put into the states the UI can show.
 *
 * The accounts were picked on 2026-10-04 among owners of recently pushed repositories,
 * preferring ones with few public repositories (about 95 in total) so seeding stays
 * fast. Seeding takes about 20 seconds: it makes a few dozen GitHub API requests and
 * clones three tiny repositories, so it needs network access. Without GITHUB_TOKEN
 * (60 requests an hour) seed at most once an hour, or the syncs fail on the rate limit.
 *
 * Produced by the real code paths: synced targets (sources only and incl. forks),
 * a target that doesn't exist on GitHub, repositories removed from GitHub (placeholder
 * rows the sync marks as removed), clones (cloned, skipped as too large, failed on a
 * missing branch), clone overrides, exclusions, a deactivated target and two targets
 * queued for the worker. Added on top: older sync history (retried and final failures,
 * deferred clones) and a target whose latest attempts failed.
 */
class DemoSeeder extends Seeder
{
    /**
     * name => [type, repository scope].
     */
    private const TARGETS = [
        'taylorotwell' => [TargetType::User, RepositoryScope::Sources],
        'torvalds' => [TargetType::User, RepositoryScope::All],
        'norvig' => [TargetType::User, RepositoryScope::Sources],
        'zloirock' => [TargetType::User, RepositoryScope::All],
        'emilkowalski' => [TargetType::User, RepositoryScope::Sources],
        '3b1b' => [TargetType::User, RepositoryScope::Sources],
        'symfony-cli' => [TargetType::Organization, RepositoryScope::Sources],
        'shaarli' => [TargetType::Organization, RepositoryScope::Sources],
        'nutgram' => [TargetType::Organization, RepositoryScope::Sources],
        'freqtrade' => [TargetType::Organization, RepositoryScope::Sources],
        'DNSCrypt' => [TargetType::Organization, RepositoryScope::Sources],
        'engelsystem' => [TargetType::Organization, RepositoryScope::Sources],
        // Doesn't exist on GitHub: its sync fails with a real "not found" error.
        'sm-task-missing-account' => [TargetType::User, RepositoryScope::Sources],
    ];

    /**
     * Left for the queue worker: synced (for the first time) once it runs.
     */
    private const QUEUED = 'emilkowalski';

    /**
     * Seed the demo targets.
     */
    public function run(TargetLifecycle $lifecycle): void
    {
        if (Target::query()->exists()) {
            $this->command?->warn('Targets already exist, skipping the demo data.');

            return;
        }

        $targets = collect(self::TARGETS)->map(fn (array $settings, string $name) => Target::create([
            'name' => $name,
            'type' => $settings[0],
            'repository_scope' => $settings[1],
        ]));

        // Repositories deleted from GitHub since an earlier sync: the first sync marks
        // them as removed because GitHub doesn't return them.
        $this->seedRemovedRepositories($targets['DNSCrypt']);

        $targets->except(self::QUEUED)->each(fn (Target $target) => $this->sync($target));

        // Clone overrides, applied by a second sync.
        $this->repository($targets['taylorotwell'], 'flask-app')?->update(['clone_mode' => CloneMode::Always]);
        // Far above GITHUB_CLONE_MAX_SIZE_MB: skipped.
        $this->repository($targets['torvalds'], 'linux')?->update(['clone_mode' => CloneMode::Always]);
        // A branch that doesn't exist: the clone fails and the run counts a clone error.
        $this->repository($targets['shaarli'], 'devtools')?->update(['clone_mode' => CloneMode::Always, 'clone_branch' => 'seeded-missing-branch']);
        // Cloning on for the whole target, but only its two smallest repositories.
        $targets['nutgram']->repositories()->whereNotIn('name', ['async', 'starter-kit'])->update(['clone_mode' => CloneMode::Never]);
        $targets['nutgram']->update(['clone_repositories' => true]);

        $targets->only(['taylorotwell', 'torvalds', 'shaarli', 'nutgram'])->each(fn (Target $target) => $this->sync($target));

        $this->repository($targets['shaarli'], 'shaarli-pkg-debian')?->update(['excluded_at' => now()]);
        $this->repository($targets['engelsystem'], 'engelsystem.de')?->update(['excluded_at' => now()]);

        $lifecycle->deactivate($targets['norvig'], keepRepositories: true, keepClones: true, keepHistory: true);

        $this->seedHistory($targets);
        $this->failLatestAttempts($targets['freqtrade']);

        // Queued last, so the worker picks them up once it runs.
        $this->repository($targets['zloirock'], 'eslint-plugin-name')?->update(['clone_mode' => CloneMode::Always]);
        $this->repository($targets['zloirock'], 'eslint-plugin-name')?->requestClone();
        $targets[self::QUEUED]->queueSync(force: true);

        // The history goes back a few days.
        Target::query()->whereKeyNot($targets[self::QUEUED]->id)->toBase()->update(['created_at' => now()->subDays(5)]);
    }

    /**
     * Run a real sync of the target right away. A failure is recorded on the target and
     * in its history like any failed sync, so seeding goes on.
     */
    private function sync(Target $target): void
    {
        $sync = function () use ($target): int {
            try {
                SyncTargetRepositories::dispatchSync($target);
            } catch (Throwable) {
                // Recorded by the job.
            }

            // The console shows FAIL for a failed sync (expected for the missing account).
            return ($target->refresh()->status === TargetStatus::Failed ? TaskResult::Failure : TaskResult::Success)->value;
        };

        $this->command ? $this->command->outputComponents()->task("Syncing {$target->name}", $sync) : $sync();
    }

    /**
     * A stored repository of the target, null when GitHub didn't return it.
     */
    private function repository(Target $target, string $name): ?Repository
    {
        return $target->repositories()->where('name', $name)->first();
    }

    /**
     * Placeholder rows standing in for repositories that were deleted from GitHub.
     */
    private function seedRemovedRepositories(Target $target): void
    {
        foreach (['removed-example-1', 'removed-example-2'] as $index => $name) {
            Repository::create([
                'target_id' => $target->id,
                // Far above GitHub's ids, so no real repository ever takes this row over.
                'github_id' => 9_000_000_000_000 + $target->id * 10 + $index,
                'name' => $name,
                'full_name' => "{$target->name}/{$name}",
                'description' => 'Seeded placeholder for a repository that was deleted from GitHub.',
                'html_url' => "https://github.com/{$target->name}/{$name}",
                'language' => 'Go',
                'stargazers_count' => 12 * ($index + 1),
                'github_updated_at' => now()->subYear(),
                'pushed_at' => now()->subYear(),
                'last_seen_at' => now()->subWeek(),
            ]);
        }
    }

    /**
     * Older sync attempts: a successful sync a day for each synced target, plus a few
     * stories (a retried rate limit, a network error, clones left for the next sync).
     *
     * @param  Collection<string, Target>  $targets
     */
    private function seedHistory(Collection $targets): void
    {
        $targets->each(function (Target $target) {
            if ($target->github_repositories_count === null) {
                return;
            }

            foreach ([2, 3, 4] as $daysAgo) {
                $this->pastRun($target, $this->daysAgo($target, $daysAgo));
            }
        });

        $rateLimited = $this->daysAgo($targets['3b1b'], 1);
        $this->pastRun($targets['3b1b'], $rateLimited, [
            'status' => SyncRunStatus::Failed,
            'error' => 'GitHub API rate limit exceeded, resets at '.$rateLimited->copy()->addMinutes(40)->utc()->format('Y-m-d H:i:s').' UTC.',
            'will_retry' => true,
        ]);
        $this->pastRun($targets['3b1b'], $rateLimited->copy()->addMinutes(41), ['attempt' => 2]);

        $unreachable = $this->daysAgo($targets['engelsystem'], 1);
        $this->pastRun($targets['engelsystem'], $unreachable, [
            'status' => SyncRunStatus::Failed,
            'error' => 'Could not reach the GitHub API (network error or timeout).',
            'will_retry' => true,
        ]);
        $this->pastRun($targets['engelsystem'], $unreachable->copy()->addSeconds(30), ['attempt' => 2]);

        $this->pastRun($targets['nutgram'], $this->daysAgo($targets['nutgram'], 1), ['clones_count' => 1, 'clones_deferred_count' => 1]);
    }

    /**
     * The target's sync worked yesterday, but today's attempts failed: GitHub answered
     * with a server error three times, so the queue gave up.
     */
    private function failLatestAttempts(Target $target): void
    {
        if ($target->github_repositories_count === null) {
            return;
        }

        $target->syncRuns()->whereDate('started_at', today())->get()->each(fn (SyncRun $run) => $run->update([
            'started_at' => $run->started_at->copy()->subDay(),
            'finished_at' => $run->finished_at?->copy()->subDay(),
        ]));

        $error = 'GitHub is having problems right now (HTTP 502).';
        $startedAt = now()->subMinutes(30);

        // The job's backoff: 30 seconds before the 2nd attempt, 120 before the 3rd.
        foreach ([1 => 0, 2 => 30, 3 => 120] as $attempt => $backoff) {
            $startedAt = $startedAt->copy()->addSeconds($backoff + 1);
            $this->pastRun($target, $startedAt, ['attempt' => $attempt, 'status' => SyncRunStatus::Failed, 'error' => $error, 'will_retry' => $attempt < 3]);
        }

        $target->update([
            'status' => TargetStatus::Failed,
            'last_synced_at' => $target->last_synced_at?->copy()->subDay(),
            'last_failed_at' => $startedAt,
            'last_error' => $error,
        ]);
    }

    /**
     * A finished sync attempt at the given time; a successful one by default.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function pastRun(Target $target, CarbonInterface $startedAt, array $attributes = []): void
    {
        $failed = ($attributes['status'] ?? null) === SyncRunStatus::Failed;
        $durationMs = $failed ? 400 + $target->id * 13 : 900 + $target->id * 170;

        SyncRun::create([
            'target_id' => $target->id,
            'attempt' => 1,
            'status' => SyncRunStatus::Succeeded,
            'started_at' => $startedAt,
            'finished_at' => $startedAt->copy()->addMilliseconds($durationMs),
            'duration_ms' => $durationMs,
            'repositories_count' => $failed ? null : $target->github_repositories_count,
            'clones_count' => $failed ? null : 0,
            'clone_errors_count' => $failed ? null : 0,
            'clones_deferred_count' => $failed ? null : 0,
            ...$attributes,
        ]);
    }

    /**
     * A time on the given day, spread per target so runs don't all start at once.
     */
    private function daysAgo(Target $target, int $days): CarbonInterface
    {
        return now()->subDays($days)->subMinutes(($target->id * 37) % 300);
    }
}
