<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Receiving;

use Cbox\Webhooks\ValueObjects\IncomingWebhook;
use Illuminate\Http\Request;

/**
 * What the middleware hands the controller: the items recorded as new, with
 * their ledger ids, and how many were duplicates.
 */
final class ReceivedBatch
{
    public const string REQUEST_ATTRIBUTE = 'cbox_webhooks_received';

    /** @var list<array{0: string, 1: IncomingWebhook}> */
    private array $accepted = [];

    private int $duplicates = 0;

    public function __construct(public readonly string $endpointName) {}

    public static function fromRequest(Request $request): ?self
    {
        $batch = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return $batch instanceof self ? $batch : null;
    }

    public function accepted(string $ledgerId, IncomingWebhook $webhook): void
    {
        $this->accepted[] = [$ledgerId, $webhook];
    }

    public function duplicate(IncomingWebhook $webhook): void
    {
        $this->duplicates++;
    }

    /**
     * @return list<array{0: string, 1: IncomingWebhook}>
     */
    public function items(): array
    {
        return $this->accepted;
    }

    public function count(): int
    {
        return count($this->accepted);
    }

    public function duplicates(): int
    {
        return $this->duplicates;
    }

    public function isEmpty(): bool
    {
        return $this->accepted === [];
    }
}
