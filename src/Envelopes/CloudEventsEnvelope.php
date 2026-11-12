<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Envelopes;

use Cbox\Webhooks\Contracts\EnvelopeFormat;
use Cbox\Webhooks\Support\Json;
use Cbox\Webhooks\ValueObjects\EncodedEnvelope;
use Cbox\Webhooks\ValueObjects\EnvelopeItem;
use stdClass;

/**
 * CloudEvents 1.0 in structured mode, JSON event format: the whole event,
 * attributes and data, is the request body (`application/cloudevents+json`).
 * A batch is the JSON batch format, an array of events
 * (`application/cloudevents-batch+json`).
 *
 * How the engine's fields map:
 *
 *  - `id` is the event id, so `source` + `id` names one event wherever and
 *    however often it is sent, as the specification asks. A retry and a replay
 *    carry the same `id`.
 *  - `source` identifies this producer: `webhooks.cloudevents.source`.
 *  - `subject` is the owner the event belongs to, `partition/key` (or the key
 *    alone); omitted for an event with no owner.
 *  - `time` is when the event occurred, RFC 3339, UTC.
 *  - `data` is the published payload, always a JSON object.
 *
 * What CloudEvents has no attribute for travels as extension attributes:
 * `deliveryid` (unique per event and endpoint: deduplicate a delivery on it),
 * `sequence` with `sequencetype` "Integer" (the documented sequence
 * extension; per endpoint, gapless), `attempt`, and `apiversion` when the
 * endpoint has one.
 */
final readonly class CloudEventsEnvelope implements EnvelopeFormat
{
    public const string NAME = 'cloudevents';

    public const string SPEC_VERSION = '1.0';

    public const string CONTENT_TYPE = 'application/cloudevents+json; charset=utf-8';

    public const string BATCH_CONTENT_TYPE = 'application/cloudevents-batch+json; charset=utf-8';

    public function __construct(private string $source) {}

    public function single(EnvelopeItem $item): EncodedEnvelope
    {
        return new EncodedEnvelope(Json::encode($this->event($item)), self::CONTENT_TYPE);
    }

    public function batch(string $batchId, array $items): EncodedEnvelope
    {
        return new EncodedEnvelope(Json::encodeList(array_map($this->event(...), $items)), self::BATCH_CONTENT_TYPE);
    }

    /**
     * @return array<string, mixed>
     */
    public function event(EnvelopeItem $item): array
    {
        $event = [
            'specversion' => self::SPEC_VERSION,
            'id' => $item->eventId,
            'source' => $this->source,
            'type' => $item->type,
            'time' => $item->occurredAt->utc()->format('Y-m-d\TH:i:s\Z'),
        ];

        $subject = $this->subject($item);

        if ($subject !== null) {
            $event['subject'] = $subject;
        }

        $event['datacontenttype'] = 'application/json';
        $event['deliveryid'] = $item->deliveryId;
        $event['sequence'] = (string) $item->sequence;
        $event['sequencetype'] = 'Integer';
        $event['attempt'] = $item->attempt;

        if ($item->apiVersion !== null) {
            $event['apiversion'] = $item->apiVersion;
        }

        // An empty PHP array would encode as a list; the payload is an object.
        $event['data'] = $item->data === [] ? new stdClass : $item->data;

        return $event;
    }

    private function subject(EnvelopeItem $item): ?string
    {
        $scope = $item->scope;

        if ($scope->key === null) {
            return $scope->partition;
        }

        return $scope->partition === null ? $scope->key : $scope->partition.'/'.$scope->key;
    }
}
