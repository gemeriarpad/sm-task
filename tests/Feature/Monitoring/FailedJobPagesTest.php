<?php

namespace Tests\Feature\Monitoring;

use App\Jobs\SyncTargetRepositories;
use App\Models\Target;
use App\Models\User;
use App\Services\GitHub\GitHubException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class FailedJobPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /**
     * A failed_jobs row with the payload the queue would have stored for the job.
     */
    private function failedJob(object $job, string $exception = "RuntimeException: Boom\n#0 /app/file.php(1)", ?string $message = null): string
    {
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => $job::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'attempts' => 3,
                'data' => ['commandName' => $job::class, 'command' => serialize(clone $job)],
            ]),
            'exception' => $exception,
            'message' => $message,
            'failed_at' => now(),
        ]);

        return $uuid;
    }

    public function test_failed_jobs_are_listed_with_their_target_and_a_message_for_users()
    {
        $target = Target::factory()->create(['name' => 'laravel']);
        $this->failedJob(
            new SyncTargetRepositories($target),
            "App\\Services\\GitHub\\GitHubException: GitHub is having problems right now (HTTP 502). in /app/x.php:10\n#0 /app/x.php(10)",
            'GitHub is having problems right now (HTTP 502).',
        );

        $this->get('/failed-jobs')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('failed-jobs/Index')
                ->has('jobs.data', 1)
                ->where('jobs.data.0.job', 'SyncTargetRepositories')
                ->where('jobs.data.0.target', ['id' => $target->id, 'name' => 'laravel'])
                ->where('jobs.data.0.attempts', 3)
                ->where('jobs.data.0.message', 'GitHub is having problems right now (HTTP 502).')
                ->missing('jobs.data.0.exception'));
    }

    public function test_jobs_that_failed_before_messages_were_stored_never_show_the_raw_exception()
    {
        $this->failedJob(new SyncTargetRepositories(Target::factory()->create()), "PDOException: SQLSTATE[08006] host=10.0.0.5 password=secret\n#0 /app/x.php(1)");

        $this->get('/failed-jobs')
            ->assertInertia(fn (Assert $page) => $page->where('jobs.data.0.message', 'The job failed. The error has been logged.'))
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('10.0.0.5');
    }

    public function test_a_failing_job_stores_a_message_for_users_next_to_the_raw_exception()
    {
        $failer = app('queue.failer');
        $payload = fn () => json_encode(['uuid' => (string) Str::uuid(), 'displayName' => SyncTargetRepositories::class, 'timeout' => 1800]);

        $written = $failer->log('database', 'default', $payload(), new GitHubException('Organization "nobody" was not found on GitHub.', 404));
        $raw = $failer->log('database', 'default', $payload(), new RuntimeException('SQLSTATE[42703]: Undefined column: 7 ERROR: column "x" does not exist'));
        $timedOut = $failer->log('database', 'default', $payload(), new TimeoutExceededException('SyncTargetRepositories has timed out.'));

        $message = fn (string $uuid) => DB::table('failed_jobs')->where('uuid', $uuid)->value('message');
        $this->assertSame('Organization "nobody" was not found on GitHub.', $message($written));
        $this->assertSame('The job failed unexpectedly. The error has been logged.', $message($raw));
        $this->assertSame('The job took longer than 1800 seconds and was stopped.', $message($timedOut));
        // The raw exception is still stored for debugging (`queue:failed`, the logs).
        $this->assertStringContainsString('SQLSTATE[42703]', DB::table('failed_jobs')->where('uuid', $raw)->value('exception'));
    }

    public function test_the_failed_jobs_list_pages_by_the_chosen_page_size()
    {
        $target = Target::factory()->create();

        foreach (range(1, 12) as $i) {
            $this->failedJob(new SyncTargetRepositories($target));
        }

        $this->get('/failed-jobs')->assertInertia(fn (Assert $page) => $page->has('jobs.data', 12)->where('jobs.per_page', 25));
        $this->get('/failed-jobs?per_page=10')->assertInertia(fn (Assert $page) => $page->has('jobs.data', 10)->where('jobs.per_page', 10));
        // Not an offered size: the default.
        $this->get('/failed-jobs?per_page=7')->assertInertia(fn (Assert $page) => $page->where('jobs.per_page', 25));
    }

    public function test_a_failed_job_can_be_retried()
    {
        Queue::fake();
        $uuid = $this->failedJob(new SyncTargetRepositories(Target::factory()->create()));

        $this->from('/failed-jobs')->post("/failed-jobs/{$uuid}/retry")->assertRedirect('/failed-jobs');

        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertCount(1, Queue::pushedRaw(fn (string $payload) => json_decode($payload, true)['displayName'] === SyncTargetRepositories::class));
    }

    public function test_a_failed_job_can_be_forgotten()
    {
        $uuid = $this->failedJob(new SyncTargetRepositories(Target::factory()->create()));

        $this->from('/failed-jobs')->delete("/failed-jobs/{$uuid}")->assertRedirect('/failed-jobs');

        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_unknown_failed_jobs_are_not_found()
    {
        $uuid = (string) Str::uuid();

        $this->post("/failed-jobs/{$uuid}/retry")->assertNotFound();
        $this->delete("/failed-jobs/{$uuid}")->assertNotFound();
    }

    public function test_guests_cannot_see_failed_jobs()
    {
        auth()->logout();

        $this->get('/failed-jobs')->assertRedirect('/login');
    }
}
