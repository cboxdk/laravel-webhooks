<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\Catalog\EventDefinition;
use Cbox\Webhooks\Exceptions\UnknownEventType;

/**
 * The closed set of event types this application publishes.
 *
 * Deny by default: a type nobody registered cannot be published (when strict)
 * and cannot be subscribed to, so a typo in a publisher or a stale subscription
 * is refused where it is written rather than silently delivered or silently
 * dropped. The host registers its definitions from a service provider.
 */
interface EventCatalog
{
    public function register(EventDefinition ...$definitions): void;

    public function has(string $type): bool;

    /**
     * @throws UnknownEventType
     */
    public function get(string $type): EventDefinition;

    /** @return list<EventDefinition> */
    public function all(): array;

    /**
     * Concrete types for a subscription list. `*` is every offered type,
     * `group.*` every type in that group, anything else must be a known type.
     *
     * @param  list<string>  $subscriptions
     * @return list<string>
     *
     * @throws UnknownEventType
     */
    public function expand(array $subscriptions): array;

    /**
     * Whether a subscription list covers one type, wildcard-aware. Evaluated at
     * match time, so a `user.*` subscription receives a `user.created` registered
     * after the endpoint was.
     *
     * @param  list<string>  $subscriptions
     */
    public function covers(array $subscriptions, string $type): bool;
}
