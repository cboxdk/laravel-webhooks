<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * What a publisher hands the engine. `idempotencyKey`, when set, makes a
 * re-publish within the same scope a no-op, which is what lets a domain-event
 * relay re-run safely after a crash. `subject`, when set, names the person the
 * event is about (the host's own id for them, never an address), so their events
 * can be erased alone through `Contracts\SubjectErasure`.
 */
final readonly class OutboundEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public Scope $scope,
        public array $payload,
        public ?string $idempotencyKey = null,
        public ?CarbonImmutable $occurredAt = null,
        public ?string $subject = null,
    ) {}
}
