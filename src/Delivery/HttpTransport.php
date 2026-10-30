<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Cbox\Ssrf\Contracts\UrlGuard;
use Cbox\Ssrf\Exceptions\BlockedUrl;
use Cbox\Webhooks\Enums\AttemptOutcome;
use Cbox\Webhooks\Support\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * The one place an outbound request is built. The URL is validated and the
 * connection pinned by the SSRF guard immediately before the send, so a DNS
 * rebind between registration and delivery cannot redirect it; redirects are
 * refused for the same reason. Timeouts are short because a worker is holding
 * a delivery while it waits.
 */
final readonly class HttpTransport
{
    public const string USER_AGENT = 'Cbox-Webhooks/1.0 (+https://github.com/cboxdk/laravel-webhooks)';

    public function __construct(
        private Factory $http,
        private UrlGuard $guard,
        private Config $config,
    ) {}

    /**
     * @param  array<string, string>  $headers
     */
    public function send(string $url, string $body, array $headers): TransportResult
    {
        $started = hrtime(true);

        try {
            $pinned = $this->guard->pinnedOptions($url, $this->schemes(), false);
        } catch (BlockedUrl $e) {
            return TransportResult::policy(AttemptOutcome::BlockedUrl, $e->getMessage());
        }

        try {
            $response = $this->http
                ->withHeaders($headers)
                ->withUserAgent($this->config->string('http.user_agent') ?? self::USER_AGENT)
                ->withOptions($pinned)
                ->withoutRedirecting()
                ->connectTimeout($this->config->positiveInt('http.connect_timeout', 5))
                ->timeout($this->config->positiveInt('http.timeout', 10))
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException $e) {
            return new TransportResult(
                $this->isTimeout($e) ? AttemptOutcome::Timeout : AttemptOutcome::ConnectionError,
                $this->elapsed($started),
                null,
                null,
                $this->message($e),
            );
        } catch (Throwable $e) {
            return new TransportResult(AttemptOutcome::ConnectionError, $this->elapsed($started), null, null, $this->message($e));
        }

        return $this->fromResponse($response, $this->elapsed($started));
    }

    public function timeoutBudgetSeconds(): int
    {
        return $this->config->positiveInt('http.connect_timeout', 5) + $this->config->positiveInt('http.timeout', 10);
    }

    private function fromResponse(Response $response, int $durationMs): TransportResult
    {
        $status = $response->status();

        return new TransportResult(
            $response->successful() ? AttemptOutcome::Success : AttemptOutcome::HttpError,
            $durationMs,
            $status,
            $this->excerpt($response->body()),
            $response->successful() ? null : 'HTTP '.$status,
            $response->header('X-Request-Id') !== '' ? $response->header('X-Request-Id') : null,
            $response->header('Retry-After') !== '' ? $response->header('Retry-After') : null,
        );
    }

    /**
     * The first 1000 bytes of the body, cut on a character boundary and scrubbed
     * of invalid sequences so the excerpt can be stored in a UTF-8 column.
     */
    private function excerpt(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        return mb_scrub(mb_strcut($body, 0, 1000, 'UTF-8'), 'UTF-8');
    }

    /**
     * @return list<string>
     */
    private function schemes(): array
    {
        return $this->config->bool('allow_http', false) ? ['https', 'http'] : ['https'];
    }

    private function isTimeout(ConnectionException $e): bool
    {
        $message = $e->getMessage().' '.($e->getPrevious()?->getMessage() ?? '');

        return stripos($message, 'timed out') !== false
            || stripos($message, 'timeout') !== false
            || str_contains($message, 'cURL error 28');
    }

    private function message(Throwable $e): string
    {
        return mb_strcut($e::class.': '.$e->getMessage(), 0, 1000);
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
