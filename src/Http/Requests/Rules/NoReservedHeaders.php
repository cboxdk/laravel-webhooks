<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Requests\Rules;

use Cbox\Webhooks\Endpoints\EndpointPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class NoReservedHeaders implements ValidationRule
{
    public function __construct(private EndpointPolicy $policy) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach (array_keys($value) as $name) {
            if (! is_string($name) || $this->policy->isReservedHeader($name)) {
                $fail(sprintf('Header [%s] is reserved and cannot be set on an endpoint.', (string) $name));
            }
        }
    }
}
