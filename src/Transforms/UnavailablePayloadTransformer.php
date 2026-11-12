<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Transforms;

use Cbox\Webhooks\Contracts\PayloadTransformer;
use Cbox\Webhooks\Exceptions\InvalidEndpoint;
use Cbox\Webhooks\Exceptions\TransformFailed;

/**
 * The default: no expression language is configured, so no transform is
 * accepted. Deny by default; a host binds its own transformer to turn them on.
 * `apply()` can only be reached by a row written while another transformer
 * was bound, and fails that delivery rather than sending it untransformed.
 */
final class UnavailablePayloadTransformer implements PayloadTransformer
{
    public function validate(string $expression): void
    {
        throw InvalidEndpoint::noPayloadTransformer();
    }

    public function apply(string $expression, array $input): mixed
    {
        throw new TransformFailed('No payload transformer is configured.');
    }
}
