<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Jobs;

use Cbox\Webhooks\Receiving\InboundLedger;
use Cbox\Webhooks\Receiving\InboundProcessor;
use Cbox\Webhooks\ValueObjects\IncomingWebhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Processes one recorded inbound item off the request thread. The handler's
 * exception propagates so the queue retries by its own policy; the ledger row
 * says failed in the meantime and processed once a retry gets through.
 */
final class ProcessInboundWebhook implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly string $ledgerId,
        public readonly IncomingWebhook $webhook,
    ) {}

    public function handle(InboundProcessor $processor, InboundLedger $ledger): void
    {
        if ($ledger->find($this->ledgerId) === null) {
            return;
        }

        $processor->process($this->ledgerId, $this->webhook);
    }
}
