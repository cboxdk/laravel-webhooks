<?php

declare(strict_types=1);

namespace Cbox\Webhooks\Catalog;

/**
 * One entry of the event catalog: the wire `type`, its `group` (the part before
 * the first dot, by convention, which is what `group.*` subscriptions expand
 * over), and what a console, an API or a docs page shows about it.
 *
 * `supersededBy` keeps a renamed event deliverable to subscribers that already
 * use it while a picker stops offering it; `offered` false hides it from
 * pickers altogether while still allowing it to be published.
 */
final readonly class EventDefinition
{
    /**
     * @param  array<string, mixed>  $example
     */
    public function __construct(
        public string $type,
        public string $group,
        public string $label,
        public string $description,
        public array $example = [],
        public ?string $supersededBy = null,
        public bool $offered = true,
    ) {}

    public function isLegacy(): bool
    {
        return $this->supersededBy !== null;
    }

    /** Whether a subscription picker should list it: offered and not superseded. */
    public function isOffered(): bool
    {
        return $this->offered && ! $this->isLegacy();
    }

    /**
     * @return array{type: string, group: string, label: string, description: string, example: array<string, mixed>, superseded_by: string|null, offered: bool}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'group' => $this->group,
            'label' => $this->label,
            'description' => $this->description,
            'example' => $this->example,
            'superseded_by' => $this->supersededBy,
            'offered' => $this->isOffered(),
        ];
    }
}
