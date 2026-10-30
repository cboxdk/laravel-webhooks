<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Http\Requests\Rules;

use Cbox\Webhooks\Contracts\EventCatalog;
use Cbox\Webhooks\Exceptions\UnknownEventType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Every subscription must expand to at least one catalog type. */
final readonly class CatalogEventTypes implements ValidationRule
{
    public function __construct(private EventCatalog $catalog) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('The :attribute must be a list of event types.');

            return;
        }

        $types = array_values(array_filter($value, 'is_string'));

        try {
            $this->catalog->expand($types);
        } catch (UnknownEventType $e) {
            $fail($e->getMessage());
        }
    }
}
