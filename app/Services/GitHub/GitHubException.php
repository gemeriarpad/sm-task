<?php

namespace App\Services\GitHub;

use App\Exceptions\UserFacingException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * A GitHub API request failed. The message is written for users; GitHub's own
 * response text is logged by GitHubClient, never put in the message.
 */
class GitHubException extends RuntimeException implements UserFacingException
{
    /**
     * @param  bool  $retryable  Whether retrying later may succeed (rate limits, 5xx, network errors).
     * @param  int|null  $retryAfter  Seconds until a retry makes sense, when GitHub tells us.
     */
    public function __construct(
        string $message,
        int $code = 0,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * The account or its repositories do not exist (or the target type is wrong).
     */
    public static function notFound(string $what): self
    {
        return new self("{$what} was not found on GitHub.", 404);
    }

    /**
     * The GitHub API could not be reached at all.
     */
    public static function connectionFailed(Throwable $previous): self
    {
        // The raw cURL error stays on the previous exception (logged), not in the UI message.
        return new self('Could not reach the GitHub API (network error or timeout).', retryable: true, previous: $previous);
    }

    /**
     * Create an exception describing a failed GitHub API response.
     */
    public static function fromResponse(Response $response): self
    {
        $status = $response->status();
        $rateLimited = $status === 429
            || ($status === 403 && ($response->header('X-RateLimit-Remaining') === '0' || $response->header('Retry-After') !== ''));

        if ($rateLimited) {
            // Secondary rate limits send Retry-After, primary ones the reset timestamp.
            $reset = $response->header('X-RateLimit-Reset');
            $retryAfter = $response->header('Retry-After') !== ''
                ? (int) $response->header('Retry-After')
                : ($reset !== '' ? max(0, (int) $reset - time()) : null);
            $resetAt = $reset !== '' ? CarbonImmutable::createFromTimestamp((int) $reset)->toDateTimeString() : null;

            return new self(
                'GitHub API rate limit exceeded'.($resetAt ? ", resets at {$resetAt} UTC." : '.'),
                $status,
                retryable: true,
                retryAfter: $retryAfter,
            );
        }

        return new self(self::messageFor($status), $status, retryable: $response->serverError());
    }

    /**
     * What a failed response status means for the user.
     */
    private static function messageFor(int $status): string
    {
        return match (true) {
            $status === 401 => 'GitHub rejected the access token. Check GITHUB_TOKEN in .env.',
            $status === 403 => 'GitHub refused the request (HTTP 403).',
            $status >= 500 => "GitHub is having problems right now (HTTP {$status}).",
            $status >= 400 => "GitHub rejected the request (HTTP {$status}).",
            default => "GitHub sent an unexpected response (HTTP {$status}).",
        };
    }
}
