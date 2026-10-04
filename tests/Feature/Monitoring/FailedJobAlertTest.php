<?php

namespace Tests\Feature\Monitoring;

use App\Enums\TargetType;
use App\Jobs\SyncTargetRepositories;
use App\Models\Target;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class FailedJobAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_job_that_fails_for_good_is_alerted()
    {
        Log::spy();
        $alerts = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('alerts')->andReturn($alerts);
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);
        $target = Target::factory()->create(['name' => 'nobody', 'type' => TargetType::Organization]);

        // The sync queue runs it right away; an unknown account fails it without retries.
        SyncTargetRepositories::dispatch($target);

        $alerts->shouldHaveReceived('critical')->once()->withArgs(fn (string $message, array $context) => $message === 'Queue job failed: '.SyncTargetRepositories::class
            && $context['job'] === SyncTargetRepositories::class
            && str_contains($context['exception'], 'Organization "nobody" was not found on GitHub.'));
    }

    public function test_the_alerts_channel_never_breaks_the_failure_handling()
    {
        // Tests send alerts nowhere (LOG_ALERT_CHANNELS=null in phpunit.xml); the
        // default is its own file, storage/logs/alerts.log.
        $this->assertSame('stack', config('logging.channels.alerts.driver'));
        $this->assertSame(storage_path('logs/alerts.log'), config('logging.channels.alerts_file.path'));
        $this->assertTrue(config('logging.channels.alerts.ignore_exceptions'));
    }
}
