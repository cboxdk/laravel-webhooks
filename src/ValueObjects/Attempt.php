<?php

declare(strict_types=1);

namespace Cbox\Webhooks\ValueObjects;

use Carbon\CarbonImmutable;
use Cbox\Webhooks\Enums\AttemptOutcome;

readonly class Attempt
{
    public function __construct(
        public string $id = '',
        public string $deliveryId = '',
        public string $endpointId = '',
        public int $number = 0,
        public AttemptOutcome $outcome = AttemptOutcome::Success,
        public ?CarbonImmutable $startedAt = null,
        public int $durationMs = 0,
        public ?int $responseCode = null,
        public ?string $responseExcerpt = null,
        public ?string $error = null,
        public ?string $requestId = null,
        public ?string $batchId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'delivery_id' => $this->deliveryId,
            'endpoint_id' => $this->endpointId,
            'batch_id' => $this->batchId,
            'attempt_number' => $this->number,
            'outcome' => $this->outcome->value,
            'started_at' => $this->startedAt?->toIso8601String(),
            'duration_ms' => $this->durationMs,
            'response_code' => $this->responseCode,
            'response_excerpt' => $this->responseExcerpt,
            'error' => $this->error,
            'request_id' => $this->requestId,
        ];
    }
}
