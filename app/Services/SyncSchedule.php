<?php

namespace App\Services;

use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Lorisleiva\CronTranslator\CronParsingException;
use Lorisleiva\CronTranslator\CronTranslator;

/**
 * The scheduled resync of every target: when it runs next, and whether the
 * scheduler that runs it is alive.
 */
class SyncSchedule
{
    private const HEARTBEAT_KEY = 'scheduler:heartbeat';

    /**
     * The heartbeat is written every minute, so an older one means the scheduler stopped.
     */
    private const HEARTBEAT_TTL = 180;

    /**
     * The cron expression of the scheduled resync.
     */
    public function expression(): string
    {
        return (string) config('services.github.sync_schedule');
    }

    /**
     * When the scheduled resync runs next, or null for an invalid expression.
     */
    public function nextRunAt(): ?Carbon
    {
        if (! CronExpression::isValidExpression($this->expression())) {
            return null;
        }

        // Same calculation as the scheduler's Event::nextRunDate().
        return Date::instance((new CronExpression($this->expression()))->getNextRunDate(now(), 0, false, $this->timezone()));
    }

    /**
     * The schedule in words, e.g. "Every 15 minutes" or "Every day at 3:00am UTC".
     */
    public function description(): ?string
    {
        try {
            $description = CronTranslator::translate($this->expression());
        } catch (CronParsingException) {
            return null;
        }

        // Clock times in the description are in the scheduler's timezone, not the viewer's.
        return preg_match('/\d:\d\d/', $description) ? "{$description} {$this->timezone()}" : $description;
    }

    /**
     * Record that the scheduler is running. Called by a task scheduled every minute.
     */
    public function beat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->getTimestamp());
    }

    /**
     * When the scheduler last ran, or null if it never has.
     */
    public function lastHeartbeatAt(): ?Carbon
    {
        $timestamp = Cache::get(self::HEARTBEAT_KEY);

        return is_int($timestamp) ? Date::createFromTimestamp($timestamp) : null;
    }

    /**
     * Whether the scheduler ran recently enough to be considered alive.
     */
    public function schedulerRunning(): bool
    {
        return $this->lastHeartbeatAt()?->gt(now()->subSeconds(self::HEARTBEAT_TTL)) ?? false;
    }

    /**
     * The schedule serialized for the dashboard.
     *
     * @return array{expression: string, description: string|null, next_run_at: string|null, scheduler_running: bool, last_heartbeat_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'expression' => $this->expression(),
            'description' => $this->description(),
            'next_run_at' => $this->nextRunAt()?->toIso8601String(),
            'scheduler_running' => $this->schedulerRunning(),
            'last_heartbeat_at' => $this->lastHeartbeatAt()?->toIso8601String(),
        ];
    }

    /**
     * The timezone the scheduler evaluates cron expressions in.
     */
    private function timezone(): string
    {
        return config('app.schedule_timezone') ?? config('app.timezone');
    }
}
