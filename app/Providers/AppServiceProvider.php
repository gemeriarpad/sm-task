<?php

namespace App\Providers;

use App\Queue\UserMessageFailedJobProvider;
use App\Services\Git\RepositoryCloner;
use App\Services\GitHub\GitHubClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GitHubClient::class, fn () => new GitHubClient(config('services.github.token')));
        $this->app->singleton(RepositoryCloner::class, fn () => new RepositoryCloner(config('services.github.clone_path')));

        // Failed jobs also store a message users may see; the failed jobs page shows only that.
        $this->app->extend('queue.failer', fn (FailedJobProviderInterface $failer, Application $app) => config('queue.failed.driver') === 'database-uuids'
            ? new UserMessageFailedJobProvider($app['db'], config('queue.failed.database'), config('queue.failed.table'))
            : $failer);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // `migrate:fresh` drops every repository row, so their clones are orphaned:
        // remove them too. (Tests point the clone path at a throwaway folder.)
        Event::listen(DatabaseRefreshed::class, fn () => $this->app->make(RepositoryCloner::class)->purgeAll());

        // REST API, in two layers. Every API request counts per IP address before it is
        // authenticated (a global middleware, see bootstrap/app.php), so requests with a
        // missing or wrong token are limited too. Authenticated requests also count per
        // token, so one client (say a CI hook) can't use up another's budget.
        RateLimiter::for('api-ip', fn (Request $request) => $request->is('api/*')
            ? Limit::perMinute(120)->by($request->ip())
            : Limit::none());
        RateLimiter::for('api', function (Request $request) {
            $token = $request->user()?->currentAccessToken();

            return Limit::perMinute(60)->by($token instanceof PersonalAccessToken ? 'token:'.$token->getKey() : $request->ip());
        });

        // Alert on every job that failed for good (all attempts used, or failed on
        // purpose). Retried attempts don't get here. Jobs add their own log context
        // (e.g. the sync target), which the alert carries too.
        Queue::failing(fn (JobFailed $event) => Log::channel('alerts')->critical('Queue job failed: '.$event->job->resolveName(), [
            'job' => $event->job->resolveName(),
            'uuid' => $event->job->uuid(),
            'connection' => $event->connectionName,
            'queue' => $event->job->getQueue(),
            'attempts' => $event->job->attempts(),
            'exception' => $event->exception::class.': '.$event->exception->getMessage(),
        ]));
    }
}
