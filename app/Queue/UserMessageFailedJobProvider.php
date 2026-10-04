<?php

namespace App\Queue;

use App\Exceptions\FailureMessage;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;

/**
 * Laravel's failed job store, plus a message users may see. The raw exception is
 * still stored for debugging (`queue:failed`, the logs), but the failed jobs page
 * only ever shows the message.
 */
class UserMessageFailedJobProvider extends DatabaseUuidFailedJobProvider
{
    /**
     * Log a failed job into storage.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @param  string  $payload
     * @param  \Throwable  $exception
     * @return string|null
     */
    public function log($connection, $queue, $payload, $exception)
    {
        $uuid = parent::log($connection, $queue, $payload, $exception);
        $timeout = json_decode($payload, true)['timeout'] ?? null;

        $this->getTable()->where('uuid', $uuid)->update([
            'message' => FailureMessage::for($exception, timeout: is_int($timeout) ? $timeout : null),
        ]);

        return $uuid;
    }
}
