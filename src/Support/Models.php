<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Support;

use Cbox\Webhooks\Models\WebhookAttempt;
use Cbox\Webhooks\Models\WebhookDelivery;
use Cbox\Webhooks\Models\WebhookEndpoint;
use Cbox\Webhooks\Models\WebhookEvent;
use Cbox\Webhooks\Models\WebhookInboundDelivery;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * The model classes in force, read from `webhooks.models.*`. A host subclasses
 * to add a global scope or a relation; anything that is not a subclass of the
 * shipped model is refused, because the package's queries rely on its columns
 * and casts.
 */
final readonly class Models
{
    public function __construct(private Repository $config) {}

    /** @return class-string<WebhookEndpoint> */
    public function endpoint(): string
    {
        return $this->resolve('endpoint', WebhookEndpoint::class);
    }

    /** @return class-string<WebhookEvent> */
    public function event(): string
    {
        return $this->resolve('event', WebhookEvent::class);
    }

    /** @return class-string<WebhookDelivery> */
    public function delivery(): string
    {
        return $this->resolve('delivery', WebhookDelivery::class);
    }

    /** @return class-string<WebhookAttempt> */
    public function attempt(): string
    {
        return $this->resolve('attempt', WebhookAttempt::class);
    }

    /** @return class-string<WebhookInboundDelivery> */
    public function inbound(): string
    {
        return $this->resolve('inbound', WebhookInboundDelivery::class);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $base
     * @return class-string<T>
     */
    private function resolve(string $key, string $base): string
    {
        $configured = $this->config->get('webhooks.models.'.$key, $base);

        if (! is_string($configured) || ! class_exists($configured) || ! is_a($configured, $base, true)) {
            throw new InvalidArgumentException(sprintf('webhooks.models.%s must name a class extending %s.', $key, $base));
        }

        return $configured;
    }
}
