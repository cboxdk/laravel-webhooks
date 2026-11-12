<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Delivery;

use Cbox\Webhooks\Jobs\DeliverBatch;
use Cbox\Webhooks\Jobs\DeliverWebhook;
use Cbox\Webhooks\Jobs\FlushEndpointBatches;
use Cbox\Webhooks\Support\Config;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * Hands the engine's jobs to the queue on the configured connection and queue.
 *
 * Goes through {@see PendingDispatch} rather than the bus directly, because the
 * `ShouldBeUnique` lock is taken there: a job pushed straight to the bus would
 * skip it, and a backed-up queue would then hold several jobs for one delivery.
 */
final readonly class JobDispatcher
{
    /** Seconds past the HTTP budget a job may spend on the claim and the settle before the worker kills it. */
    private const int TIMEOUT_MARGIN = 15;

    public function __construct(private Config $config, private HttpTransport $transport) {}

    /** A delay holds the job back that many seconds; a paced replay uses it. */
    public function delivery(string $deliveryId, int $expectedAttempt, int $delaySeconds = 0): void
    {
        $pending = $this->route(new PendingDispatch(new DeliverWebhook($deliveryId, $expectedAttempt, $this->strandedAfter(), $this->timeout())));

        if ($delaySeconds > 0) {
            $pending->delay($delaySeconds);
        }
    }

    public function batch(string $batchId): void
    {
        $this->route(new PendingDispatch(new DeliverBatch($batchId, $this->strandedAfter(), $this->timeout())));
    }

    /** A delayed flush lets the batch window fill; an immediate one runs because the size was reached. */
    public function flush(string $endpointId, int $delaySeconds): void
    {
        $pending = $this->route(new PendingDispatch(new FlushEndpointBatches($endpointId, $delaySeconds === 0, $delaySeconds)));

        if ($delaySeconds > 0) {
            $pending->delay($delaySeconds);
        }
    }

    private function route(PendingDispatch $pending): PendingDispatch
    {
        $connection = $this->config->string('queue.connection');
        $queue = $this->config->string('queue.queue');

        if ($connection !== null) {
            $pending->onConnection($connection);
        }

        if ($queue !== null) {
            $pending->onQueue($queue);
        }

        return $pending;
    }

    private function strandedAfter(): int
    {
        return $this->config->positiveInt('stranded_after_seconds', 900);
    }

    private function timeout(): int
    {
        return $this->transport->timeoutBudgetSeconds() + self::TIMEOUT_MARGIN;
    }
}
