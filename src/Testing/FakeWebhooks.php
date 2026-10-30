<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Testing;

use Cbox\Webhooks\Contracts\Publisher;
use Cbox\Webhooks\ValueObjects\OutboundEvent;
use Cbox\Webhooks\ValueObjects\PublishedEvent;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * The publisher in memory: what an application test asserts against when it
 * only cares that an event was published, not that it was delivered. Honours
 * idempotency keys the way the real publisher does, so a relay test can prove
 * a re-run is a duplicate.
 */
final class FakeWebhooks implements Publisher
{
    /** @var list<OutboundEvent> */
    private array $published = [];

    /** @var array<string, string> dedup key => event id */
    private array $seen = [];

    public function publish(OutboundEvent $event): PublishedEvent
    {
        $key = $event->idempotencyKey === null
            ? null
            : ($event->scope->partition ?? '').'|'.($event->scope->key ?? '').'|'.$event->idempotencyKey;

        if ($key !== null && isset($this->seen[$key])) {
            return new PublishedEvent($this->seen[$key], 0, true);
        }

        $id = (string) Str::ulid();

        if ($key !== null) {
            $this->seen[$key] = $id;
        }

        $this->published[] = $event;

        return new PublishedEvent($id, 1, false);
    }

    public function publishMany(array $events): array
    {
        return array_map(fn (OutboundEvent $e): PublishedEvent => $this->publish($e), $events);
    }

    /**
     * @param  (callable(OutboundEvent): bool)|null  $where
     */
    public function assertPublished(string $type, ?callable $where = null): void
    {
        $matching = $this->published($type);

        Assert::assertNotEmpty($matching, "No webhook event of type [{$type}] was published.");

        if ($where !== null) {
            foreach ($matching as $event) {
                if ($where($event)) {
                    return;
                }
            }

            Assert::fail("A webhook event of type [{$type}] was published, but none matched the given callback.");
        }
    }

    public function assertNotPublished(string $type): void
    {
        Assert::assertEmpty($this->published($type), "A webhook event of type [{$type}] was unexpectedly published.");
    }

    public function assertNothingPublished(): void
    {
        Assert::assertEmpty($this->published, 'Webhook events were unexpectedly published: '.implode(', ', array_map(static fn (OutboundEvent $e): string => $e->type, $this->published)));
    }

    public function assertPublishedCount(int $count, ?string $type = null): void
    {
        Assert::assertCount($count, $this->published($type));
    }

    /**
     * @return list<OutboundEvent>
     */
    public function published(?string $type = null): array
    {
        if ($type === null) {
            return $this->published;
        }

        return array_values(array_filter($this->published, static fn (OutboundEvent $e): bool => $e->type === $type));
    }
}
