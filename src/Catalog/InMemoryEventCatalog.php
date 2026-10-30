<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Catalog;

use Cbox\Webhooks\Contracts\EventCatalog;
use Cbox\Webhooks\Exceptions\UnknownEventType;

final class InMemoryEventCatalog implements EventCatalog
{
    public const string WILDCARD = '*';

    /** @var array<string, EventDefinition> keyed by type, in registration order */
    private array $definitions = [];

    public function register(EventDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            // Last registration wins so a host can correct a shipped description
            // without a second entry showing up in the catalog.
            $this->definitions[$definition->type] = $definition;
        }
    }

    public function has(string $type): bool
    {
        return isset($this->definitions[$type]);
    }

    public function get(string $type): EventDefinition
    {
        return $this->definitions[$type] ?? throw UnknownEventType::forType($type, array_keys($this->definitions));
    }

    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function expand(array $subscriptions): array
    {
        $types = [];

        foreach ($subscriptions as $subscription) {
            $matched = false;

            foreach ($this->definitions as $definition) {
                if ($this->patternMatches($subscription, $definition)) {
                    $types[$definition->type] = true;
                    $matched = true;
                }
            }

            if (! $matched) {
                throw UnknownEventType::forSubscription($subscription);
            }
        }

        return array_keys($types);
    }

    public function covers(array $subscriptions, string $type): bool
    {
        $definition = $this->definitions[$type] ?? null;

        foreach ($subscriptions as $subscription) {
            if ($subscription === $type) {
                return true;
            }

            if ($definition !== null && $this->patternMatches($subscription, $definition)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `*` covers every offered type; `group.*` every type whose group matches;
     * an exact name covers itself whether or not it is offered, since a legacy
     * subscription must keep receiving the event it named.
     */
    private function patternMatches(string $pattern, EventDefinition $definition): bool
    {
        if ($pattern === self::WILDCARD) {
            return $definition->isOffered();
        }

        if (str_ends_with($pattern, '.'.self::WILDCARD)) {
            return $definition->group === substr($pattern, 0, -2) && $definition->isOffered();
        }

        return $pattern === $definition->type;
    }
}
