<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * Which stored events to re-create deliveries for. `since` is required so a
 * replay is always bounded; `types` empty means every type the endpoint
 * subscribes to.
 *
 * @param  list<string>  $types
 */
final readonly class ReplayRequest
{
    /**
     * @param  list<string>  $types
     */
    public function __construct(
        public CarbonImmutable $since,
        public ?CarbonImmutable $until = null,
        public array $types = [],
        public int $limit = 1000,
    ) {}
}
