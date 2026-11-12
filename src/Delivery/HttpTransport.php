<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Cbox\Ssrf\Contracts\UrlGuard;
use Cbox\Ssrf\Exceptions\BlockedUrl;
use Cbox\Webhooks\Endpoints\EndpointPolicy;
use Cbox\Webhooks\Enums\AttemptOutcome;
use Cbox\Webhooks\Support\Config;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Throwable;

/**
 * The one place an outbound request is built. The URL is validated and the
 * connection pinned by the SSRF guard immediately before the send, so a DNS
 * rebind between registration and delivery cannot redirect it. Timeouts are
 * short because a worker is holding a delivery while it waits.
 *
 * Redirects are refused unless the endpoint asked to follow them, and even
 * then only 307 and 308, the two that keep the method and the body: the same
 * POST, the same bytes and the same signature go to the next URL. At most
 * three hops. Every hop's `Location` is resolved against the URL that sent it
 * and goes through the guard and the pin exactly like the first URL, so a
 * redirect cannot reach an address the first URL could not. 301, 302 and 303
 * (which turn a POST into a GET in practice) and a fourth hop fail the
 * attempt with `redirect_refused` or `too_many_redirects`. The timeout covers
 * the whole chain, not each hop.
 */
final readonly class HttpTransport
{
    public const string USER_AGENT = 'Cbox-Webhooks/1.0 (+https://github.com/cboxdk/laravel-webhooks)';

    public const int MAX_REDIRECTS = 3;

    private const array FOLLOWED = [307, 308];

    private const array REFUSED = [301, 302, 303];

    public function __construct(
        private Factory $http,
        private UrlGuard $guard,
        private Config $config,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  string  $contentType  what the body is, as the envelope format declared it
     * @param  string|null  $userAgent  for this request; null uses the configured one, or the package's own
     * @param  int|null  $timeoutSeconds  the endpoint's own; null uses the configured one
     * @param  bool  $followRedirects  follow 307 and 308, at most three hops
     */
    public function send(string $url, string $body, array $headers, string $contentType = 'application/json', ?string $userAgent = null, ?int $timeoutSeconds = null, bool $followRedirects = false): TransportResult
    {
        $started = hrtime(true);
        $timeout = $timeoutSeconds ?? $this->config->positiveInt('http.timeout', 10);
        $deadline = $started + $timeout * 1_000_000_000;
        $current = $url;

        for ($hop = 0; ; $hop++) {
            try {
                $pinned = $this->guard->pinnedOptions($current, $this->schemes(), false);
            } catch (BlockedUrl $e) {
                return TransportResult::policy(AttemptOutcome::BlockedUrl, ($hop > 0 ? 'Redirect refused: ' : '').$e->getMessage());
            }

            $remaining = (int) ceil(($deadline - hrtime(true)) / 1_000_000_000);

            if ($remaining < 1) {
                return new TransportResult(AttemptOutcome::Timeout, $this->elapsed($started), null, null, 'The timeout was spent following redirects.');
            }

            try {
                $response = $this->http
                    ->withHeaders($headers)
                    ->withUserAgent($userAgent ?? $this->config->string('http.user_agent') ?? self::USER_AGENT)
                    ->withOptions($pinned)
                    ->withoutRedirecting()
                    ->connectTimeout(min($remaining, $this->config->positiveInt('http.connect_timeout', 5)))
                    ->timeout($remaining)
                    ->withBody($body, $contentType)
                    ->post($current);
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

            $status = $response->status();
            $redirect = in_array($status, self::FOLLOWED, true) || in_array($status, self::REFUSED, true);

            if (! $redirect || ! $followRedirects) {
                return $this->fromResponse($response, $this->elapsed($started));
            }

            if (in_array($status, self::REFUSED, true)) {
                return $this->fromResponse($response, $this->elapsed($started), sprintf('redirect_refused: HTTP %d is not followed; only 307 and 308 keep the method and the body.', $status));
            }

            if ($hop >= self::MAX_REDIRECTS) {
                return $this->fromResponse($response, $this->elapsed($started), sprintf('too_many_redirects: more than %d hops.', self::MAX_REDIRECTS));
            }

            $location = trim($response->header('Location'));

            if ($location === '') {
                return $this->fromResponse($response, $this->elapsed($started), sprintf('redirect_refused: HTTP %d without a Location.', $status));
            }

            try {
                $current = (string) UriResolver::resolve(new Uri($current), new Uri($location))->withFragment('');
            } catch (InvalidArgumentException) {
                return $this->fromResponse($response, $this->elapsed($started), 'redirect_refused: the Location is not a URL.');
            }
        }
    }

    /**
     * The longest one send may take: the connect timeout plus the highest
     * request timeout any endpoint may have, which is what a delivery job's
     * own timeout is sized from.
     */
    public function timeoutBudgetSeconds(): int
    {
        $endpointCeiling = min(EndpointPolicy::MAX_TIMEOUT_SECONDS, $this->config->positiveInt('http.max_endpoint_timeout', EndpointPolicy::MAX_TIMEOUT_SECONDS));

        return $this->config->positiveInt('http.connect_timeout', 5)
            + max($this->config->positiveInt('http.timeout', 10), $endpointCeiling);
    }

    private function fromResponse(Response $response, int $durationMs, ?string $error = null): TransportResult
    {
        $status = $response->status();

        return new TransportResult(
            $response->successful() ? AttemptOutcome::Success : AttemptOutcome::HttpError,
            $durationMs,
            $status,
            $this->excerpt($response->body()),
            $response->successful() ? null : ($error ?? 'HTTP '.$status),
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
