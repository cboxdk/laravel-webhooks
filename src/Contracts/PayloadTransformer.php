<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Contracts;

use Cbox\Webhooks\Exceptions\InvalidEndpoint;
use Cbox\Webhooks\Exceptions\TransformFailed;

/**
 * Reshapes an event before it is sent, by an expression stored on the
 * endpoint. The package ships no expression language: the default binding
 * refuses every expression, so transforms stay off until the host binds an
 * implementation of the language it chose (JMESPath, for example).
 *
 * `validate()` runs when an endpoint is registered or changed with a
 * transform, so a broken expression is refused then, not at 3 a.m. on the
 * first delivery. `apply()` runs on every attempt inside a worker, so it must
 * be deterministic, must not reach outside its arguments, and must not run
 * code the endpoint owner wrote.
 *
 * The input is the event:
 *
 *     ['id' => string, 'type' => string, 'occurred_at' => ISO-8601 string,
 *      'subject' => ?string, 'data' => the published payload]
 *
 * The result replaces `data` in the `cbox` and `cloudevents` envelopes and is
 * the whole body in the `raw` envelope. It must be JSON-encodable. A null
 * result is treated as a failure.
 */
interface PayloadTransformer
{
    /**
     * @throws InvalidEndpoint with a message the endpoint owner can act on
     */
    public function validate(string $expression): void;

    /**
     * @param  array{id: string, type: string, occurred_at: string, subject: string|null, data: array<string, mixed>}  $input
     *
     * @throws TransformFailed
     */
    public function apply(string $expression, array $input): mixed;
}
