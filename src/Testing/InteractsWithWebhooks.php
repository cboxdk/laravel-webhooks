<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Testing;

use Cbox\Ssrf\Testing\InteractsWithSsrf;
use Cbox\Webhooks\Catalog\EventDefinition;
use Cbox\Webhooks\Contracts\Endpoints;
use Cbox\Webhooks\Contracts\EventCatalog;
use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\Contracts\Receivers;
use Cbox\Webhooks\Contracts\Sweeper;
use Cbox\Webhooks\Delivery\Envelope;
use Cbox\Webhooks\Events\WebhookProcessed;
use Cbox\Webhooks\Housekeeping\SweepReport;
use Cbox\Webhooks\Support\Json;
use Cbox\Webhooks\ValueObjects\IncomingWebhook;
use Cbox\Webhooks\ValueObjects\NewEndpoint;
use Cbox\Webhooks\ValueObjects\RegisteredEndpoint;
use Cbox\Webhooks\ValueObjects\Scope;
use Cbox\WebhookSignature\Testing\InteractsWithWebhookSignatures;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test-side wiring for both halves of the engine. Compose it into a base
 * TestCase; it brings the SSRF and signature packages' helpers with it, since
 * registering an endpoint needs DNS fixed and posting to a receiver needs a
 * signature.
 *
 * Outbound tests fake HTTP with `Http::fake()` and drive the engine with
 * `deliverPendingWebhooks()`, which runs the sweep on a sync queue so every
 * due delivery is attempted right there. Inbound tests post real envelopes
 * signed by the production path, so a change to the wire format fails the
 * test rather than the deployment.
 */
trait InteractsWithWebhooks
{
    use InteractsWithSsrf;
    use InteractsWithWebhookSignatures;

    /** @var list<IncomingWebhook> */
    private array $handledWebhooks = [];

    private bool $recordingHandled = false;

    /** Swap the publisher for the in-memory fake and hand it back for assertions. */
    protected function fakeWebhooks(): FakeWebhooks
    {
        $fake = new FakeWebhooks;

        Container::getInstance()->instance(Publisher::class, $fake);

        return $fake;
    }

    /**
     * Register an endpoint in a scope, through the real registry. Fixes DNS for
     * the URL's host to a public address so the SSRF guard accepts it, unless
     * the host was already faked.
     *
     * @param  list<string>  $eventTypes
     * @param  array<string, string>  $headers
     */
    protected function registerWebhookEndpoint(
        Scope $scope,
        string $url,
        array $eventTypes,
        string $scheme = 'cbox',
        int $batchSize = 1,
        int $batchWindowSeconds = 0,
        ?int $rateLimitPerMinute = null,
        array $headers = [],
        ?string $apiVersion = null,
    ): RegisteredEndpoint {
        $this->fakeDnsForUrl($url);

        $spec = new NewEndpoint(
            url: $url,
            eventTypes: $eventTypes,
            scheme: $scheme,
            batchSize: $batchSize,
            batchWindowSeconds: $batchWindowSeconds,
            rateLimitPerMinute: $rateLimitPerMinute,
            headers: $headers,
            apiVersion: $apiVersion,
        );

        return Container::getInstance()->make(Endpoints::class)->register($scope, $spec);
    }

    /** Resolve the URL's host to a public address for the rest of the test. */
    protected function fakeDnsForUrl(string $url, string $address = '93.184.216.34'): void
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return;
        }

        $this->fakeSsrfDns([...$this->fakedDns, $host => [$address]]);
        $this->fakedDns[$host] = [$address];
    }

    /** @var array<string, list<string>> */
    private array $fakedDns = [];

    /** Run the sweep; on a sync queue every due delivery is attempted before this returns. */
    protected function deliverPendingWebhooks(): SweepReport
    {
        return Container::getInstance()->make(Sweeper::class)->sweep();
    }

    /** Register an event type in the catalog for this test. */
    protected function defineWebhookEvent(string $type, string $group = 'test', string $label = '', string $description = ''): void
    {
        Container::getInstance()->make(EventCatalog::class)->register(
            new EventDefinition($type, $group, $label !== '' ? $label : $type, $description !== '' ? $description : $type),
        );
    }

    /**
     * A request with this type was recorded by `Http::fake()` for the URL.
     *
     * @param  (callable(ClientRequest, array<string, mixed>): bool)|null  $where  the request and its decoded envelope
     */
    protected function assertWebhookDelivered(string $url, string $type, ?callable $where = null): void
    {
        $matching = $this->recordedWebhookRequests($url, $type);

        Assert::assertNotEmpty($matching, "No webhook of type [{$type}] was delivered to [{$url}].");

        if ($where !== null) {
            foreach ($matching as [$request, $envelope]) {
                if ($where($request, $envelope)) {
                    return;
                }
            }

            Assert::fail("Webhooks of type [{$type}] were delivered to [{$url}], but none matched the given callback.");
        }
    }

    protected function assertWebhookNotDelivered(string $url, ?string $type = null): void
    {
        Assert::assertEmpty($this->recordedWebhookRequests($url, $type), "A webhook was unexpectedly delivered to [{$url}].");
    }

    protected function assertWebhookDeliveredTimes(string $url, int $times, ?string $type = null): void
    {
        Assert::assertCount($times, $this->recordedWebhookRequests($url, $type));
    }

    /**
     * Every recorded request to the URL with its decoded envelope, batch items
     * flattened so a type assertion sees inside a batch.
     *
     * @return list<array{0: ClientRequest, 1: array<string, mixed>}>
     */
    protected function recordedWebhookRequests(string $url, ?string $type = null): array
    {
        $out = [];

        foreach (Http::recorded() as [$request]) {
            if ($request->url() !== $url) {
                continue;
            }

            $envelope = Json::object($request->body()) ?? [];

            if (($envelope['batch'] ?? false) === true) {
                $items = $envelope['deliveries'] ?? [];

                foreach (is_array($items) ? $items : [] as $item) {
                    $item = Json::map($item);

                    if ($type === null || ($item['type'] ?? null) === $type) {
                        $out[] = [$request, $item];
                    }
                }

                continue;
            }

            if ($type === null || ($envelope['type'] ?? null) === $type) {
                $out[] = [$request, $envelope];
            }
        }

        return $out;
    }

    /**
     * A receiving endpoint for this test, in the signature package's config,
     * and inline processing so a handler runs inside the request.
     *
     * @param  string|list<string>|array<string, string>  $secrets
     */
    protected function fakeWebhookReceiver(string $name, string|array $secrets = 'test-secret', string $scheme = 'cbox'): static
    {
        $this->fakeWebhookEndpoint($name, $scheme, $secrets);

        config()->set('webhooks.receive.inline', true);

        return $this;
    }

    /**
     * POST a real envelope to a receiving route, signed by the production path.
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    protected function postWebhook(string $uri, string $name, string $type, array $data = [], ?string $deliveryId = null, ?int $sequence = null, ?Scope $scope = null): TestResponse
    {
        $this->recordHandledWebhooks();

        return $this->postSignedWebhook($uri, $name, Json::encode($this->webhookEnvelope($type, $data, $deliveryId, $sequence, $scope)));
    }

    /**
     * POST a batch envelope.
     *
     * @param  list<array{type: string, data?: array<string, mixed>, delivery_id?: string, sequence?: int}>  $items
     * @return TestResponse<Response>
     */
    protected function postWebhookBatch(string $uri, string $name, array $items, ?Scope $scope = null): TestResponse
    {
        $this->recordHandledWebhooks();

        $envelopes = [];

        foreach ($items as $item) {
            $envelopes[] = $this->webhookEnvelope($item['type'], $item['data'] ?? [], $item['delivery_id'] ?? null, $item['sequence'] ?? null, $scope);
        }

        return $this->postSignedWebhook($uri, $name, Json::encode(Envelope::batch((string) Str::ulid(), $envelopes)));
    }

    /**
     * The envelope the engine would send for one event, so a receiver test
     * exercises the exact shape production produces.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function webhookEnvelope(string $type, array $data = [], ?string $deliveryId = null, ?int $sequence = null, ?Scope $scope = null): array
    {
        return [
            'id' => (string) Str::ulid(),
            'type' => $type,
            'created_at' => now()->toImmutable()->toIso8601String(),
            'delivery_id' => $deliveryId ?? (string) Str::ulid(),
            'attempt' => 1,
            'sequence' => $sequence ?? 1,
            'scope' => ($scope ?? Scope::of('test'))->toArray(),
            'api_version' => null,
            'data' => $data,
        ];
    }

    /**
     * Register a handler for the test.
     *
     * @param  class-string|\Closure(IncomingWebhook): void  $handler
     */
    protected function onWebhook(string $pattern, string|\Closure $handler): void
    {
        Container::getInstance()->make(Receivers::class)->on($pattern, $handler);
    }

    /**
     * @param  (callable(IncomingWebhook): bool)|null  $where
     */
    protected function assertWebhookHandled(string $type, ?callable $where = null): void
    {
        $matching = array_values(array_filter($this->handledWebhooks, static fn (IncomingWebhook $w): bool => $w->type === $type));

        Assert::assertNotEmpty($matching, "No inbound webhook of type [{$type}] was handled.");

        if ($where !== null) {
            foreach ($matching as $webhook) {
                if ($where($webhook)) {
                    return;
                }
            }

            Assert::fail("Inbound webhooks of type [{$type}] were handled, but none matched the given callback.");
        }
    }

    protected function assertWebhookNotHandled(string $type): void
    {
        $matching = array_filter($this->handledWebhooks, static fn (IncomingWebhook $w): bool => $w->type === $type);

        Assert::assertEmpty($matching, "An inbound webhook of type [{$type}] was unexpectedly handled.");
    }

    private function recordHandledWebhooks(): void
    {
        if ($this->recordingHandled) {
            return;
        }

        $this->recordingHandled = true;

        Container::getInstance()->make(Dispatcher::class)
            ->listen(WebhookProcessed::class, function (WebhookProcessed $event): void {
                $this->handledWebhooks[] = $event->webhook;
            });
    }
}
