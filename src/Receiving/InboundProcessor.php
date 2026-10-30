<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Receiving;

use Cbox\Webhooks\Contracts\Metrics;
use Cbox\Webhooks\Contracts\Receivers;
use Cbox\Webhooks\Contracts\Tracing;
use Cbox\Webhooks\Enums\SpanKind;
use Cbox\Webhooks\Events\WebhookProcessed;
use Cbox\Webhooks\Events\WebhookProcessingFailed;
use Cbox\Webhooks\Monitoring\SpanContext;
use Cbox\Webhooks\ValueObjects\IncomingWebhook;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * Runs every registered handler for one inbound item inside a server span,
 * marks the ledger row, and rethrows a handler's exception so the caller (the
 * controller inline, or the queued job) applies its own retry policy.
 */
final readonly class InboundProcessor
{
    public function __construct(
        private Receivers $receivers,
        private InboundLedger $ledger,
        private Container $container,
        private Dispatcher $events,
        private Metrics $metrics,
        private Tracing $tracing,
    ) {}

    /**
     * @throws Throwable whatever the handler threw, after the row is marked failed
     */
    public function process(string $ledgerId, IncomingWebhook $webhook): void
    {
        $attributes = [
            'webhook.type' => $webhook->type,
            'webhook.delivery_id' => $webhook->deliveryId,
            'webhook.endpoint' => $webhook->endpointName,
        ];

        $this->tracing->span('webhooks.receive', SpanKind::Server, $attributes, function (SpanContext $span) use ($ledgerId, $webhook): void {
            $started = hrtime(true);

            try {
                foreach ($this->receivers->handlersFor($webhook->type) as $handler) {
                    $this->invoke($handler, $webhook);
                }
            } catch (Throwable $e) {
                $this->ledger->failed($ledgerId, $e::class.': '.$e->getMessage());
                $this->metrics->counter('webhooks.inbound.received', 1, ['endpoint' => $webhook->endpointName, 'outcome' => 'failed']);
                $span->set('webhook.outcome', 'failed');
                $span->set('error.message', $e->getMessage());
                $this->events->dispatch(new WebhookProcessingFailed($webhook, $e));

                throw $e;
            }

            $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);

            $this->ledger->processed($ledgerId);
            $this->metrics->counter('webhooks.inbound.received', 1, ['endpoint' => $webhook->endpointName, 'outcome' => 'processed']);
            $this->metrics->histogram('webhooks.inbound.duration_ms', (float) $durationMs, ['endpoint' => $webhook->endpointName]);
            $span->set('webhook.outcome', 'processed');
            $this->events->dispatch(new WebhookProcessed($webhook, $durationMs));
        });
    }

    /**
     * @param  class-string|\Closure(IncomingWebhook): void  $handler
     */
    private function invoke(string|\Closure $handler, IncomingWebhook $webhook): void
    {
        if ($handler instanceof \Closure) {
            $handler($webhook);

            return;
        }

        $instance = $this->container->make($handler);

        if (is_callable($instance)) {
            $instance($webhook);

            return;
        }

        if (is_object($instance) && method_exists($instance, 'handle')) {
            $instance->handle($webhook);

            return;
        }

        throw new \LogicException(sprintf('Webhook handler %s is neither invokable nor has a handle() method.', $handler));
    }
}
