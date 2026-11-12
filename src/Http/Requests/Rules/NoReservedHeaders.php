<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Requests\Rules;

use Cbox\Webhooks\Endpoints\EndpointPolicy;
use Cbox\Webhooks\Exceptions\InvalidEndpoint;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The endpoint policy's header rules as a validation rule, so the API and the
 * registry refuse the same headers with the same message: no reserved name,
 * every name a token, at most ten, every value short and on one line.
 */
final readonly class NoReservedHeaders implements ValidationRule
{
    public function __construct(private EndpointPolicy $policy) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $headers = [];

        foreach ($value as $name => $item) {
            if (! is_string($item)) {
                $fail(sprintf('The value of header [%s] must be a string.', $name));

                return;
            }

            $headers[(string) $name] = $item;
        }

        try {
            $this->policy->assertHeaders($headers);
        } catch (InvalidEndpoint $e) {
            $fail($e->getMessage());
        }
    }
}
