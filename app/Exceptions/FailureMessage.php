<?php

namespace App\Exceptions;

use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

/**
 * The message shown to users for a failure. Only messages written for users are
 * shown as they are; anything else (database errors, PHP errors, raw HTTP or git
 * output) gets a fixed sentence and stays in the log.
 */
final class FailureMessage
{
    /**
     * @param  string  $subject  What failed, e.g. "The synchronization".
     * @param  int|null  $timeout  The time limit in seconds, for a timeout message.
     */
    public static function for(?Throwable $exception, string $subject = 'The job', ?int $timeout = null): string
    {
        return match (true) {
            $exception instanceof UserFacingException => $exception->getMessage(),
            $exception instanceof TimeoutExceededException => $timeout !== null
                ? "{$subject} took longer than {$timeout} seconds and was stopped."
                : "{$subject} took too long and was stopped.",
            $exception instanceof MaxAttemptsExceededException => "{$subject} was attempted too many times and was stopped.",
            default => "{$subject} failed unexpectedly. The error has been logged.",
        };
    }
}
