<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Controllers;

use Cbox\Webhooks\Jobs\ProcessInboundWebhook;
use Cbox\Webhooks\Receiving\InboundProcessor;
use Cbox\Webhooks\Receiving\ReceivedBatch;
use Cbox\Webhooks\Support\Config;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The whole of a receiver's controller: queue (or run) the processing for
 * every new item the middleware recorded and answer 202 fast. Inline mode
 * answers 500 on a handler failure so the sender retries; queued mode lets the
 * queue retry and answers 202 regardless.
 */
final readonly class ReceiveWebhookController
{
    public function __construct(
        private InboundProcessor $processor,
        private Dispatcher $bus,
        private Config $config,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $batch = ReceivedBatch::fromRequest($request);

        if ($batch === null) {
            return new JsonResponse(['status' => 'unverified'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $inline = $this->config->bool('receive.inline', false);

        foreach ($batch->items() as [$ledgerId, $webhook]) {
            if ($inline) {
                try {
                    $this->processor->process($ledgerId, $webhook);
                } catch (Throwable) {
                    return new JsonResponse(['status' => 'failed', 'received' => $batch->count()], Response::HTTP_INTERNAL_SERVER_ERROR);
                }

                continue;
            }

            $job = new ProcessInboundWebhook($ledgerId, $webhook);

            $connection = $this->config->string('receive.connection');
            $queue = $this->config->string('receive.queue');

            if ($connection !== null) {
                $job->onConnection($connection);
            }

            if ($queue !== null) {
                $job->onQueue($queue);
            }

            $this->bus->dispatch($job);
        }

        return new JsonResponse(['status' => 'accepted', 'received' => $batch->count(), 'duplicates' => $batch->duplicates()], Response::HTTP_ACCEPTED);
    }
}
