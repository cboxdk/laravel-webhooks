<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Exceptions;

final class UnknownEventType extends WebhooksException
{
    /**
     * @param  list<string>  $known
     */
    public static function forType(string $type, array $known = []): self
    {
        return new self(sprintf(
            'Unknown webhook event type [%s]. Register it in the EventCatalog from your service provider. Known: %s.',
            $type,
            $known === [] ? '(none)' : implode(', ', $known),
        ));
    }

    public static function forSubscription(string $pattern): self
    {
        return new self(sprintf('Subscription [%s] matches no event type in the catalog.', $pattern));
    }
}
