<?php

namespace Tests\Unit\GitHub;

use App\Services\GitHub\GitHubException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GitHubExceptionTest extends TestCase
{
    /**
     * @param  array<string, string>  $headers
     */
    private function response(int $status, string $message, array $headers = []): Response
    {
        return new Response(new Psr7Response($status, $headers, json_encode(['message' => $message])));
    }

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function statusCodes(): array
    {
        return [
            'server error is temporary' => [500, true, 'GitHub is having problems right now (HTTP 500).'],
            'bad gateway is temporary' => [502, true, 'GitHub is having problems right now (HTTP 502).'],
            'unavailable is temporary' => [503, true, 'GitHub is having problems right now (HTTP 503).'],
            'unauthorized (bad token) is permanent' => [401, false, 'GitHub rejected the access token. Check GITHUB_TOKEN in .env.'],
            'forbidden without rate limit headers is permanent' => [403, false, 'GitHub refused the request (HTTP 403).'],
            'unprocessable is permanent' => [422, false, 'GitHub rejected the request (HTTP 422).'],
        ];
    }

    #[DataProvider('statusCodes')]
    public function test_it_classifies_failed_responses(int $status, bool $retryable, string $message)
    {
        $exception = GitHubException::fromResponse($this->response($status, 'Something happened'));

        $this->assertSame($retryable, $exception->retryable);
        $this->assertSame($status, $exception->getCode());
        // Our own wording: GitHub's response text ("Something happened") is only logged.
        $this->assertSame($message, $exception->getMessage());
        $this->assertNull($exception->retryAfter);
    }

    public function test_an_exhausted_primary_rate_limit_waits_until_the_reset()
    {
        $exception = GitHubException::fromResponse($this->response(403, 'API rate limit exceeded', [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) (time() + 120),
        ]));

        $this->assertTrue($exception->retryable);
        $this->assertEqualsWithDelta(120, $exception->retryAfter, 1);
        $this->assertStringStartsWith('GitHub API rate limit exceeded, resets at ', $exception->getMessage());
    }

    public function test_a_secondary_rate_limit_uses_retry_after()
    {
        $exception = GitHubException::fromResponse($this->response(403, 'You have exceeded a secondary rate limit', ['Retry-After' => '60']));

        $this->assertTrue($exception->retryable);
        $this->assertSame(60, $exception->retryAfter);
    }

    public function test_too_many_requests_is_a_rate_limit()
    {
        $exception = GitHubException::fromResponse($this->response(429, 'Too Many Requests', ['Retry-After' => '30']));

        $this->assertTrue($exception->retryable);
        $this->assertSame(30, $exception->retryAfter);
        $this->assertSame('GitHub API rate limit exceeded.', $exception->getMessage());
    }

    public function test_not_found_is_permanent()
    {
        $exception = GitHubException::notFound('User "ghost-user"');

        $this->assertFalse($exception->retryable);
        $this->assertSame(404, $exception->getCode());
        $this->assertSame('User "ghost-user" was not found on GitHub.', $exception->getMessage());
    }

    public function test_connection_failures_are_temporary_and_keep_the_cause()
    {
        $cause = new \RuntimeException('cURL error 6: Could not resolve host: api.github.com');

        $exception = GitHubException::connectionFailed($cause);

        $this->assertTrue($exception->retryable);
        $this->assertSame($cause, $exception->getPrevious());
        $this->assertStringNotContainsString('cURL', $exception->getMessage());
    }
}
