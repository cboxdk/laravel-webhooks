<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Receiving;

use Cbox\Webhooks\Events\WebhookGapDetected;
use Cbox\Webhooks\ValueObjects\IncomingWebhook;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Remembers the highest sequence seen per (endpoint, scope) and announces a
 * skip. Informational: the sender's retries may still fill the gap, so nothing
 * is refused. The memory is the cache, so it is as shared as the cache is.
 */
final readonly class GapDetector
{
    private const int TTL = 86_400 * 7;

    public function __construct(
        private Repository $cache,
        private Dispatcher $events,
    ) {}

    public function observe(IncomingWebhook $webhook): void
    {
        if ($webhook->sequence === null) {
            return;
        }

        $key = 'webhooks:inbound:seq:'.$webhook->endpointName.':'.$webhook->scope->toString();
        $last = $this->cache->get($key);
        $last = is_int($last) ? $last : null;

        if ($last !== null && $webhook->sequence > $last + 1) {
            $this->events->dispatch(new WebhookGapDetected($webhook->endpointName, $webhook->scope, $last + 1, $webhook->sequence));
        }

        if ($last === null || $webhook->sequence > $last) {
            $this->cache->put($key, $webhook->sequence, self::TTL);
        }
    }
}
