<?php

use App\Jobs\QueueHeartbeat;
use App\Services\SyncSchedule;
use Illuminate\Support\Facades\Schedule;

// Resync every target's repositories; the jobs themselves skip targets already in progress.
Schedule::command('targets:sync')->cron(config('services.github.sync_schedule'));

// Lets the dashboard tell whether the scheduler is running at all.
Schedule::call(fn (SyncSchedule $schedule) => $schedule->beat())->everyMinute()->name('scheduler-heartbeat');

// Lets the dashboard and /health tell whether queue workers run and how far behind
// the queue is: a worker running this job records when it was queued.
// (A statement, not an arrow function: the scheduler keeps a callback's return value,
// so a returned PendingDispatch would only dispatch once it is garbage collected.)
Schedule::call(function () {
    QueueHeartbeat::dispatch(now()->getTimestamp());
})->everyMinute()->name('queue-heartbeat');
