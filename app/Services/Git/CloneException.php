<?php

namespace App\Services\Git;

use App\Exceptions\UserFacingException;
use RuntimeException;
use Throwable;

/**
 * A git command failed or a clone could not be read. The message is written for
 * users; git's own output is kept apart for the log.
 */
class CloneException extends RuntimeException implements UserFacingException
{
    /**
     * @param  string|null  $output  What git printed, for the log only.
     */
    public function __construct(string $message, public readonly ?string $output = null, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Describe a failed git command for users from what git printed.
     */
    public static function fromGitOutput(string $output): self
    {
        $message = match (true) {
            (bool) preg_match('/Could not resolve host|Failed to connect|Connection (timed out|refused)|Network is unreachable|Operation timed out/i', $output) => 'Could not reach GitHub.',
            (bool) preg_match('/Repository not found|could not read Username|Authentication failed|terminal prompts disabled/i', $output) => 'The repository was not found on GitHub, or it is not public anymore.',
            (bool) preg_match('/No space left on device/i', $output) => 'The server ran out of disk space.',
            default => 'Git failed. The error has been logged.',
        };

        return new self($message, $output);
    }
}
