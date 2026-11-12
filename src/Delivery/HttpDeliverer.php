<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Contracts\Deliverer;
use Cbox\Webhooks\Contracts\EnvelopeFormats;
use Cbox\Webhooks\Contracts\Tracing;
use Cbox\Webhooks\Enums\AttemptOutcome;
use Cbox\Webhooks\Enums\DeliveryStatus;
use Cbox\Webhooks\Enums\EndpointStatus;
use Cbox\Webhooks\Enums\SpanKind;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\Models\WebhookEvent;
use Cbox\Webhooks\Monitoring\SpanContext;
use Cbox\Webhooks\Support\Json;
use Cbox\Webhooks\Support\Models;
use Cbox\Webhooks\ValueObjects\AttemptResult;
use Cbox\Webhooks\ValueObjects\EncodedEnvelope;
use Cbox\WebhookSignature\Contracts\Clock;
use Illuminate\Database\Eloquent\Builder;

/**
 * The worker side of the engine: claim, check, send, settle.
 *
 * The claim is a conditional update that moves the row to in-flight only while
 * it still holds the attempt number the job was pushed with and is in a
 * claimable state. Two jobs for the same attempt (the sweep rescuing a row
 * whose first job was slow rather than lost) therefore send once: the loser
 * finds nothing to claim and returns null.
 *
 * Policy refusals before the send (a paused or disabled endpoint, an open
 * breaker, a full rate-limit window) hand the attempt number back, because they
 * are this side's decisions and must not spend the delivery's retry budget.
 * Everything that reaches the transport spends one.
 *
 * The body comes from the endpoint's envelope format as final bytes. Those
 * bytes are signed and those bytes are sent; nothing re-encodes them. A format
 * name that is no longer registered is a deployment error and throws before
 * anything is admitted, so the job fails loudly, the row stays claimed, and
 * the sweep's stranded rescue retries it once the format is back.
 */
final readonly class HttpDeliverer implements Deliverer
{
    public function __construct(
        private Models $models,
        private HttpTransport $transport,
        private Signer $signer,
        private CircuitBreaker $breaker,
        private RateLimiter $rateLimiter,
        private Settlement $settlement,
        private Tracing $tracing,
        private Clock $clock,
        private EnvelopeFormats $envelopes,
    ) {}

    public function deliver(string $deliveryId, int $expectedAttempt): ?AttemptResult
    {
        $now = CarbonImmutable::now();

        $claimed = $this->deliveries()
            ->whereKey($deliveryId)
            ->where('attempt', $expectedAttempt)
            ->whereIn('status', [DeliveryStatus::Pending->value, DeliveryStatus::Failed->value])
            ->update(['status' => DeliveryStatus::InFlight->value, 'attempt' => $expectedAttempt + 1, 'updated_at' => $now]);

        if ($claimed !== 1) {
            return null;
        }

        $delivery = $this->deliveries()->find($deliveryId);

        if ($delivery === null) {
            return null;
        }

        $endpoint = $this->endpoints()->find($delivery->endpoint_id);
        $format = $endpoint === null ? null : $this->envelopes->get($endpoint->envelope);

        $admission = $this->admit([$delivery], $endpoint, $now);

        if ($admission->refusal !== null || $endpoint === null || $format === null) {
            return $admission->refusal;
        }

        $event = $this->events()->find($delivery->event_id);

        if ($event === null) {
            return $this->settlement->exhaust([$delivery], $endpoint, 'event_pruned');
        }

        $envelope = $format->single(Envelope::item($delivery, $event, $endpoint));

        $result = $this->send($endpoint, $delivery->id, $delivery->type, (string) $delivery->sequence, $delivery->attempt, $envelope);

        return $this->settlement->settle([$delivery], $endpoint, $result, null, $admission->probing);
    }

    public function deliverBatch(string $batchId): ?AttemptResult
    {
        $now = CarbonImmutable::now();

        $deliveries = array_values($this->deliveries()
            ->where('batch_id', $batchId)
            ->where('status', DeliveryStatus::InFlight->value)
            ->orderBy('sequence')
            ->get()
            ->all());

        if ($deliveries === []) {
            return null;
        }

        $endpoint = $this->endpoints()->find($deliveries[0]->endpoint_id);
        $format = $endpoint === null ? null : $this->envelopes->get($endpoint->envelope);

        $admission = $this->admit($deliveries, $endpoint, $now);

        if ($admission->refusal !== null || $endpoint === null || $format === null) {
            return $admission->refusal;
        }

        $events = $this->events()->whereIn('id', array_map(static fn (WebhookDelivery $d): string => $d->event_id, $deliveries))->get()->keyBy('id');

        $items = [];
        $kept = [];
        $pruned = [];

        foreach ($deliveries as $delivery) {
            $event = $events->get($delivery->event_id);

            if ($event instanceof WebhookEvent) {
                $items[] = Envelope::item($delivery, $event, $endpoint);
                $kept[] = $delivery;
            } else {
                $pruned[] = $delivery;
            }
        }

        if ($pruned !== []) {
            $this->settlement->exhaust($pruned, $endpoint, 'event_pruned');
        }

        if ($kept === [] || $items === []) {
            return null;
        }

        $envelope = $format->batch($batchId, $items);
        $first = $kept[0];
        $last = $kept[count($kept) - 1];
        $attempt = $this->highestAttempt($kept);

        $result = $this->send($endpoint, $batchId, 'batch', $first->sequence.'-'.$last->sequence, $attempt, $envelope);

        return $this->settlement->settle($kept, $endpoint, $result, $batchId, $admission->probing);
    }

    /**
     * The checks that cost no HTTP, in order: the endpoint exists and is active,
     * the breaker admits us, the rate limit has a slot. A refusal settles the
     * deliveries and is returned; otherwise the send may go ahead, as the probe
     * when the breaker had been open.
     *
     * @param  list<WebhookDelivery>  $deliveries
     */
    private function admit(array $deliveries, ?WebhookEndpoint $endpoint, CarbonImmutable $now): Admission
    {
        if ($endpoint === null) {
            return Admission::refused($this->settlement->exhaust($deliveries, null, 'endpoint_deleted'));
        }

        if ($endpoint->status === EndpointStatus::Paused) {
            return Admission::refused($this->settlement->park($deliveries, $endpoint));
        }

        if ($endpoint->status === EndpointStatus::Disabled) {
            return Admission::refused($this->settlement->cancel($deliveries, $endpoint, 'endpoint_disabled'));
        }

        $wasOpen = $endpoint->circuit_opened_at !== null;

        if (! $this->breaker->admit($endpoint, $now)) {
            return Admission::refused($this->settlement->wait($deliveries, $endpoint, $this->breaker->closesAt($endpoint) ?? $now));
        }

        $limit = $endpoint->rate_limit_per_minute;

        if ($limit !== null && $limit > 0 && ! $this->rateLimiter->acquire($endpoint->id, $limit, $now)) {
            return Admission::refused($this->settlement->rateLimited($deliveries, $endpoint, $this->rateLimiter->retryAt($limit, $now)));
        }

        return Admission::granted(probing: $wasOpen);
    }

    /**
     * Sign, add the transport headers, send inside a client span. The span
     * carries ids and the host, never the full URL: a path may hold a token.
     *
     * The signature timestamp comes from the signature package's own clock, so
     * a receiver using that package in the same process (a host's test suite,
     * typically with Carbon frozen) verifies what this side signed.
     */
    private function send(WebhookEndpoint $endpoint, string $messageId, string $type, string $sequence, int $attempt, EncodedEnvelope $envelope): TransportResult
    {
        $body = $envelope->body;
        $timestamp = $this->clock->now();

        $headers = [
            ...Json::stringMap($endpoint->headers),
            ...$this->signer->headers($endpoint->signature_scheme, $endpoint->secret, $body, $messageId, $timestamp),
            'X-Webhook-Event' => $type,
            'X-Webhook-Delivery' => $messageId,
            'X-Webhook-Attempt' => (string) $attempt,
            'X-Webhook-Sequence' => $sequence,
            'Idempotency-Key' => $messageId,
            ...$this->tracing->propagationHeaders(),
        ];

        $attributes = [
            'webhook.type' => $type,
            'webhook.endpoint_id' => $endpoint->id,
            'webhook.delivery_id' => $messageId,
            'webhook.attempt' => $attempt,
            'server.address' => (string) parse_url($endpoint->url, PHP_URL_HOST),
        ];

        return $this->tracing->span('webhooks.deliver', SpanKind::Client, $attributes, function (SpanContext $span) use ($endpoint, $envelope, $headers): TransportResult {
            $result = $this->transport->send($endpoint->url, $envelope->body, $headers, $envelope->contentType);

            $span->set('webhook.outcome', $result->outcome->value);

            if ($result->responseCode !== null) {
                $span->set('http.response.status_code', $result->responseCode);
            }

            if ($result->outcome !== AttemptOutcome::Success && $result->error !== null) {
                $span->set('error.message', $result->error);
            }

            return $result;
        });
    }

    /**
     * @param  list<WebhookDelivery>  $deliveries
     */
    private function highestAttempt(array $deliveries): int
    {
        $highest = 0;

        foreach ($deliveries as $delivery) {
            $highest = max($highest, $delivery->attempt);
        }

        return $highest;
    }

    /**
     * @return Builder<WebhookDelivery>
     */
    private function deliveries(): Builder
    {
        $class = $this->models->delivery();

        return $class::query();
    }

    /**
     * @return Builder<WebhookEndpoint>
     */
    private function endpoints(): Builder
    {
        $class = $this->models->endpoint();

        return $class::query();
    }

    /**
     * @return Builder<WebhookEvent>
     */
    private function events(): Builder
    {
        $class = $this->models->event();

        return $class::query();
    }
}
