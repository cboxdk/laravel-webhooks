<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Middleware;

use Cbox\Webhooks\Contracts\Tracing;
use Cbox\Webhooks\Events\WebhookDuplicate;
use Cbox\Webhooks\Events\WebhookReceived;
use Cbox\Webhooks\Exceptions\MalformedEnvelope;
use Cbox\Webhooks\Receiving\EnvelopeParser;
use Cbox\Webhooks\Receiving\GapDetector;
use Cbox\Webhooks\Receiving\InboundLedger;
use Cbox\Webhooks\Receiving\ReceivedBatch;
use Cbox\Webhooks\Support\Config;
use Cbox\WebhookSignature\Contracts\Webhooks as Signatures;
use Cbox\WebhookSignature\Exceptions\SignatureVerificationFailed;
use Cbox\WebhookSignature\ValueObjects\VerifiedWebhook;
use Cbox\WebhookSignature\ValueObjects\WebhookMessage;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * `webhooks.receive:{endpointName}`: verify the signature with the signature
 * package, parse the envelope, record every item in the ledger, and hand the
 * new ones to the controller. A delivery that was seen before answers 200
 * `{"status":"duplicate"}` without reaching the controller, so a sender's
 * retry after a lost response never processes twice.
 *
 * The status codes follow the signature package's reasoning: a bad signature
 * is the sender's problem (401); a missing secret is ours and gets 500 so the
 * sender keeps retrying valid deliveries while we fix the configuration.
 */
final readonly class ReceiveWebhook
{
    public function __construct(
        private Signatures $signatures,
        private EnvelopeParser $parser,
        private InboundLedger $ledger,
        private GapDetector $gaps,
        private Config $config,
        private Tracing $tracing,
        private Dispatcher $events,
        private LoggerInterface $log,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $endpointName): Response
    {
        $this->tracing->continueTrace($request->headers->get('traceparent'));

        try {
            $verified = $this->signatures->verify($endpointName, WebhookMessage::fromRequest($request));
        } catch (SignatureVerificationFailed $e) {
            $this->log->warning('Refused a webhook delivery.', ['endpoint' => $endpointName, ...$e->context()]);

            return new JsonResponse(
                ['status' => 'refused', 'reason' => $e->reason->isConfigurationFault() ? 'misconfigured' : 'invalid_signature'],
                $e->reason->isConfigurationFault() ? Response::HTTP_INTERNAL_SERVER_ERROR : Response::HTTP_UNAUTHORIZED,
            );
        }

        try {
            $items = $this->parser->parse($endpointName, $verified->body);
        } catch (MalformedEnvelope $e) {
            return new JsonResponse(['status' => 'rejected', 'reason' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $batch = new ReceivedBatch($endpointName);

        foreach ($items as $item) {
            $row = $this->ledger->record($item);

            if ($row === null) {
                $batch->duplicate($item);
                $this->events->dispatch(new WebhookDuplicate($endpointName, $item->deliveryId));

                continue;
            }

            if ($this->config->bool('receive.detect_gaps', true)) {
                $this->gaps->observe($item);
            }

            $batch->accepted($row->id, $item);
            $this->events->dispatch(new WebhookReceived($item));
        }

        if ($batch->isEmpty()) {
            return new JsonResponse(['status' => 'duplicate', 'received' => 0, 'duplicates' => $batch->duplicates()], Response::HTTP_OK);
        }

        $request->attributes->set(VerifiedWebhook::REQUEST_ATTRIBUTE, $verified);
        $request->attributes->set(ReceivedBatch::REQUEST_ATTRIBUTE, $batch);

        return $next($request);
    }
}
